<?php

namespace App\Domain\Documents;

use App\Enums\SourceType;
use App\Models\Order;
use App\Models\RequirementRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves the requirements an order's document must satisfy. (Owned by the document engine.)
 *
 * Every candidate value carries its source and an authority:
 *
 *   customer-provided official instructions (form limits, pasted portal text)
 *   > official institution sources (programme > admissions/scholarship > university/department > faculty)
 *     and administrator rules scoped to the programme / institution / scholarship
 *   > the official application platform (research, then platform rules)
 *   > government / country guidance (research, then country rules)
 *   > well-established country conventions (language variant, paper size)
 *   > service and document-kind defaults (length target only).
 *
 * The highest authority wins and every disagreement is recorded with the
 * chosen value and why. When sources of equal authority disagree the
 * strictest limit is kept and the conflict is flagged for review. Nothing is
 * invented: unverified or secondary findings are ignored and fields no
 * source sets stay null, so the template's professional defaults apply.
 */
class RequirementResolver
{
    private const AUTHORITY = [
        'customer' => 100,
        SourceType::OfficialProgramme->value => 90,
        'rule:programme' => 85,
        SourceType::OfficialAdmissions->value => 80,
        SourceType::OfficialScholarship->value => 80,
        SourceType::OfficialUniversity->value => 78,
        SourceType::OfficialDepartment->value => 78,
        SourceType::OfficialFaculty->value => 76,
        'rule:institution' => 75,
        'rule:scholarship' => 75,
        SourceType::ApplicationPlatform->value => 60,
        'rule:platform' => 55,
        SourceType::Government->value => 40,
        'rule:country' => 35,
        'convention' => 20,
    ];

    /** Typical lengths when no limit is known (targets, never limits). */
    public const KIND_DEFAULT_WORDS = [
        'personal_statement' => 650,
        'statement_of_purpose' => 900,
        'motivation_letter' => 600,
        'scholarship_essay' => 650,
        'general_essay' => 750,
        'cover_letter' => 400,
        'research_proposal' => 1500,
        'resume' => 800,
        'custom' => 750,
    ];

    /** Share of a maximum to aim for, so the final text is comfortably inside the limit. */
    public const TARGET_SHARE = 0.93;

    /** Average characters per word including the following space (English prose). */
    private const CHARS_PER_WORD = 6.2;

    /** Words that fit on one page in the standard templates (title area included). */
    private const WORDS_PER_PAGE = 400;

    private const INT_LIMITS = ['min_words' => 20000, 'max_words' => 20000, 'min_characters' => 200000, 'max_characters' => 200000, 'max_pages' => 100];

    private const STRING_FIELDS = ['font_family' => 60, 'application_platform' => 60, 'submission_method' => 255, 'special_instructions' => 2000, 'naming_convention' => 255, 'date_format' => 20];

    private const FIELD_ALIASES = [
        'word_limit' => 'max_words', 'maximum_words' => 'max_words', 'max_word_count' => 'max_words', 'words' => 'max_words',
        'minimum_words' => 'min_words', 'min_word_count' => 'min_words',
        'character_limit' => 'max_characters', 'char_limit' => 'max_characters', 'max_chars' => 'max_characters', 'maximum_characters' => 'max_characters',
        'min_chars' => 'min_characters', 'minimum_characters' => 'min_characters',
        'page_limit' => 'max_pages', 'maximum_pages' => 'max_pages', 'pages' => 'max_pages',
        'font' => 'font_family', 'font_name' => 'font_family',
        'margins' => 'margins_mm', 'margin' => 'margins_mm', 'margin_mm' => 'margins_mm',
        'spacing' => 'line_spacing',
        'paper_size' => 'page_size',
        'platform' => 'application_platform',
        'sections' => 'required_sections', 'questions' => 'required_sections',
        'prohibited' => 'prohibited_content',
        'file_type' => 'file_types', 'formats' => 'file_types', 'file_format' => 'file_types',
        'language' => 'language_variant', 'spelling' => 'language_variant',
        'instructions' => 'special_instructions',
        'file_naming' => 'naming_convention', 'filename' => 'naming_convention',
    ];

    private const FIELDS = [
        'min_words', 'max_words', 'min_characters', 'max_characters', 'max_pages', 'language_variant', 'date_format',
        'page_size', 'font_family', 'font_size', 'margins_mm', 'line_spacing', 'required_sections', 'prohibited_content',
        'application_platform', 'submission_method', 'special_instructions', 'file_types', 'naming_convention',
    ];

    /** Smaller is stricter. */
    private const MAXIMA = ['max_words', 'max_characters', 'max_pages'];

    /** Larger is stricter. */
    private const MINIMA = ['min_words', 'min_characters'];

    /** @var array<string, list<array{value:mixed, source:array, authority:int, priority:int, verified:int, seq:int}>> */
    private array $candidates = [];

    /** @var array<string, array{name:string,url:?string,type:string,checked_at:?string}> */
    private array $sources = [];

    private int $seq = 0;

    /**
     * $researched: verified findings from the research stage. Only findings
     * with verified === true and an official source_type (App\Enums\SourceType,
     * not "secondary") are used. Field names may be snake_case or camelCase
     * ("max_words", "maxWords", "word_limit"...); values may be numbers or
     * phrases ("4,000 characters", "2.54 cm", "double spacing").
     *
     * @param  list<array{field:string,value:mixed,source_url:?string,source_type:string,quote:?string,verified:bool}>  $researched
     * @param  array<string, mixed>  $customerStated  limits stated in the customer's prompt/uploads (e.g. ['max_words' => 500])
     */
    public function resolve(Order $order, array $researched = [], array $customerStated = []): ResolvedRequirements
    {
        $this->candidates = [];
        $this->sources = [];
        $this->seq = 0;

        $kind = $order->documentKind();
        $country = CountryConventions::normalizeCountry($order->country_code);
        $now = now()->toIso8601String();

        // 1. The customer's own official instructions: what they stated, plus the limit field on the form.
        //    (orders.language_variant is not a form field: the pipeline records the resolved variant there.)
        $customer = $this->normalizeFields($customerStated);
        if ($order->word_limit && ! array_key_exists('max_words', $customer)) {
            $customer['max_words'] = (int) $order->word_limit;
        }
        $customerSource = ['name' => 'Customer-provided application instructions', 'url' => null, 'type' => 'customer', 'checked_at' => $now];
        foreach ($customer as $field => $value) {
            $this->offer($field, $value, $customerSource, self::AUTHORITY['customer']);
        }
        if ($mentioned = $this->platformMentionedIn((string) $order->essay_prompt)) {
            $this->offer('application_platform', $mentioned, ['name' => 'Essay prompt provided by the customer', 'url' => null, 'type' => 'customer', 'checked_at' => $now], self::AUTHORITY['customer']);
        }

        // 2. Verified findings from official sources (secondary or unverified findings never set requirements).
        $officialHosts = [];
        foreach ($researched as $finding) {
            $type = (string) ($finding['source_type'] ?? '');
            $field = $this->fieldName((string) ($finding['field'] ?? ''));
            if (($finding['verified'] ?? false) !== true || ! isset(self::AUTHORITY[$type]) || $type === 'customer' || $field === null) {
                continue;
            }
            $url = filled($finding['source_url'] ?? null) ? (string) $finding['source_url'] : null;
            if ($url && ($host = parse_url($url, PHP_URL_HOST))) {
                $officialHosts[] = strtolower((string) $host);
            }
            $this->offer($field, $finding['value'] ?? null, [
                'name' => $this->researchSourceName($type, $url),
                'url' => $url,
                'type' => $type,
                'checked_at' => $now,
            ], self::AUTHORITY[$type]);
        }

        // 3. Administrator rules. Platform rules need the platform, which other sources (or convention) may tell us.
        $rules = $this->activeRules();
        $appliedRuleIds = [];
        $context = [
            'country' => $country,
            'kind' => $kind,
            'degree' => self::degreeLevel($order->degree_level),
            'institution' => $this->key($order->institution),
            'programme' => $this->key($order->programme),
            'hosts' => array_values(array_unique($officialHosts)),
        ];

        foreach ($rules->where('scope', '!=', 'platform') as $rule) {
            if ($this->ruleMatches($rule, $context, null)) {
                $this->offerRule($rule);
                $appliedRuleIds[] = $rule->id;
            }
        }

        if (! isset($this->candidates['application_platform']) && ($default = CountryConventions::defaultPlatform($country, $order->degree_level, $kind))) {
            $this->offer('application_platform', $default, [
                'name' => "Standard application route ({$default})",
                'url' => null,
                'type' => 'convention',
                'checked_at' => null,
            ], self::AUTHORITY['convention']);
        }
        $platform = $this->choose('application_platform')['value'] ?? null;

        foreach ($rules->where('scope', 'platform') as $rule) {
            if ($this->ruleMatches($rule, $context, $platform)) {
                $this->offerRule($rule);
                $appliedRuleIds[] = $rule->id;
            }
        }

        // 4. Country conventions (only when the destination is known).
        if ($country !== null) {
            $convention = ['name' => "Writing conventions for {$country}", 'url' => null, 'type' => 'convention', 'checked_at' => null];
            $this->offer('language_variant', CountryConventions::languageVariant($country), $convention, self::AUTHORITY['convention']);
            $this->offer('page_size', CountryConventions::pageSize($country), $convention, self::AUTHORITY['convention']);
        }

        // 5. Choose a value for every field and record disagreements.
        $chosen = [];
        $conflicts = [];
        foreach (self::FIELDS as $field) {
            $choice = $this->choose($field);
            if ($choice === null) {
                continue;
            }
            $chosen[$field] = $choice;
            if ($choice['conflict'] !== null) {
                $conflicts[] = $choice['conflict'];
            }
        }
        $this->reconcileMinimums($chosen, $conflicts);

        $values = array_map(fn (array $choice) => $choice['value'], $chosen);
        $language = LanguageVariant::normalize($values['language_variant'] ?? null) ?? LanguageVariant::DEFAULT;
        $sections = $values['required_sections'] ?? [];

        $requirements = new ResolvedRequirements(
            minWords: $values['min_words'] ?? null,
            maxWords: $values['max_words'] ?? null,
            minCharacters: $values['min_characters'] ?? null,
            maxCharacters: $values['max_characters'] ?? null,
            maxPages: $values['max_pages'] ?? null,
            languageVariant: $language,
            dateFormat: $values['date_format'] ?? LanguageVariant::dateFormat($language),
            pageSize: $values['page_size'] ?? null,
            fontFamily: $values['font_family'] ?? null,
            fontSize: $values['font_size'] ?? null,
            marginsMm: $values['margins_mm'] ?? null,
            lineSpacing: $values['line_spacing'] ?? null,
            requiredSections: $sections,
            prohibitedContent: $this->union('prohibited_content'),
            applicationPlatform: $values['application_platform'] ?? null,
            submissionMethod: $values['submission_method'] ?? null,
            specialInstructions: $values['special_instructions'] ?? null,
            fileTypes: $values['file_types'] ?? ['pdf', 'docx'],
            namingConvention: $values['naming_convention'] ?? null,
            conflicts: $conflicts,
            appliedRuleIds: array_values(array_unique($appliedRuleIds)),
            // When the platform shows each question itself (UCAS), only the answers count towards limits.
            limitsIncludeHeadings: ! ($sections !== [] && collect($sections)->every(fn (array $s) => filled($s['question'] ?? null))),
            fieldSources: array_combine(
                array_map(fn (string $field) => Str::camel($field), array_keys($chosen)),
                array_map(fn (array $choice) => $choice['type'], $chosen),
            ) + ['languageVariant' => 'default'],
        );

        $requirements->targetWords = $this->targetWords($requirements, $order, $kind);
        $requirements->sources = array_values($this->sources);

        return $requirements;
    }

    /** Normalise a degree level ("Master's", "MSc", "Bachelor") to the form's values. */
    public static function degreeLevel(?string $value): ?string
    {
        $value = strtolower(trim(str_replace(['’', "'"], '', (string) $value)));
        if ($value === '') {
            return null;
        }

        return match (true) {
            (bool) preg_match('/\b(undergrad\w*|bachelors?|ug|first degree|ba|bsc|beng|llb)\b/', $value) => 'undergraduate',
            (bool) preg_match('/\bmba\b/', $value) => 'mba',
            (bool) preg_match('/\b(phd|dphil|doctor\w*|doctoral)\b/', $value) => 'phd',
            (bool) preg_match('/\b(postgraduate[_ ](diploma|certificate)|pg ?dip\w*|pg ?cert\w*)\b/', $value) => 'postgraduate_diploma',
            (bool) preg_match('/\b(masters?|msc|ma|mres|mphil|meng|llm|postgraduate taught|pgt)\b/', $value) => 'masters',
            (bool) preg_match('/\b(exchange|visiting)\b/', $value) => 'exchange',
            default => preg_replace('/[^a-z0-9]+/', '_', $value),
        };
    }

    /** Comparable platform key ("Common Application" and "Common App" are the same). */
    public static function platformKey(?string $platform): string
    {
        $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $platform)) ?? '';

        return ['commonapplication' => 'commonapp', 'thecommonapplication' => 'commonapp', 'ucasundergraduate' => 'ucas', 'ucashub' => 'ucas'][$key] ?? $key;
    }

    // ------------------------------------------------------------- candidates

    private function offer(string $field, mixed $value, array $source, int $authority, int $priority = 0, int $verified = 0): void
    {
        $value = $this->coerce($field, $value);
        if ($value === null) {
            return;
        }

        $sourceKey = $source['type'].'|'.$source['name'].'|'.($source['url'] ?? '');
        $this->sources[$sourceKey] ??= array_intersect_key($source, array_flip(['name', 'url', 'type', 'checked_at']));

        $this->candidates[$field][] = [
            'value' => $value,
            'source' => $source,
            'authority' => $authority,
            'priority' => $priority,
            'verified' => $verified,
            'seq' => $this->seq++,
        ];
    }

    private function offerRule(RequirementRule $rule): void
    {
        $source = [
            'name' => $rule->source_name ?: $rule->name,
            'url' => $rule->source_url,
            'type' => 'rule:'.$rule->scope,
            'checked_at' => $rule->last_verified_at?->toIso8601String(),
            'rule_id' => $rule->id,
        ];
        $authority = self::AUTHORITY['rule:'.$rule->scope] ?? self::AUTHORITY['rule:country'];
        $verified = (int) $rule->last_verified_at?->getTimestamp();

        foreach (self::FIELDS as $field) {
            $this->offer($field, $rule->getAttribute($field), $source, $authority, (int) $rule->priority, $verified);
        }
    }

    /**
     * Pick the value with the highest authority (then rule priority, then the
     * most recently verified). Ties between different values are ambiguous:
     * the strictest limit wins and the conflict is flagged.
     *
     * @return array{value:mixed, authority:int, type:string, conflict:?array}|null
     */
    private function choose(string $field): ?array
    {
        $candidates = $this->candidates[$field] ?? [];
        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => [$b['authority'], $b['priority'], $b['verified'], $a['seq']] <=> [$a['authority'], $a['priority'], $a['verified'], $b['seq']]);
        $top = $candidates[0];
        $distinct = collect($candidates)->unique(fn ($c) => $this->fingerprint($c['value']))->count();
        if ($distinct === 1) {
            return ['value' => $top['value'], 'authority' => $top['authority'], 'type' => $top['source']['type'], 'conflict' => null];
        }

        $tied = array_values(array_filter($candidates, fn ($c) => $c['authority'] === $top['authority'] && $c['priority'] === $top['priority']));
        $ambiguous = collect($tied)->unique(fn ($c) => $this->fingerprint($c['value']))->count() > 1;
        $winner = $top;

        if ($ambiguous) {
            if (in_array($field, self::MAXIMA, true)) {
                $winner = collect($tied)->sortBy('value')->first();
            } elseif (in_array($field, self::MINIMA, true)) {
                $winner = collect($tied)->sortByDesc('value')->first();
            }
            $reason = sprintf(
                'Sources of equal authority (%s) disagree; %s and flagged for review.',
                $this->tier($top['authority']),
                in_array($field, [...self::MAXIMA, ...self::MINIMA], true) ? 'kept the strictest limit' : 'kept the highest-priority, most recently verified value',
            );
        } else {
            $losers = array_filter($candidates, fn ($c) => $this->fingerprint($c['value']) !== $this->fingerprint($top['value']));
            $reason = 'Chosen from '.$this->tier($top['authority']).' ('.$top['source']['name'].') over '
                .collect($losers)->map(fn ($c) => $this->tier($c['authority']).' ('.$c['source']['name'].')')->unique()->implode(', ').'.';
        }

        return [
            'value' => $winner['value'],
            'authority' => $winner['authority'],
            'type' => $winner['source']['type'],
            'conflict' => [
                'field' => $field,
                'candidates' => array_map(fn ($c) => [
                    'value' => $c['value'],
                    'source' => $c['source']['name'],
                    'type' => $c['source']['type'],
                    'url' => $c['source']['url'] ?? null,
                ], $candidates),
                'chosen' => $winner['value'],
                'reason' => $reason,
                'flagged' => $ambiguous,
            ],
        ];
    }

    /** A minimum above the maximum cannot both apply: keep the higher-authority one (the maximum on a tie). */
    private function reconcileMinimums(array &$chosen, array &$conflicts): void
    {
        foreach (['min_words' => 'max_words', 'min_characters' => 'max_characters'] as $min => $max) {
            if (! isset($chosen[$min], $chosen[$max]) || $chosen[$min]['value'] <= $chosen[$max]['value']) {
                continue;
            }
            $keepMinimum = $chosen[$min]['authority'] > $chosen[$max]['authority'];
            $dropped = $keepMinimum ? $max : $min;
            $conflicts[] = [
                'field' => $dropped,
                'candidates' => [['value' => $chosen[$min]['value'], 'field' => $min], ['value' => $chosen[$max]['value'], 'field' => $max]],
                'chosen' => null,
                'reason' => "The {$min} ({$chosen[$min]['value']}) exceeds the {$max} ({$chosen[$max]['value']}); the lower-authority {$dropped} was dropped.",
                'flagged' => $chosen[$min]['authority'] === $chosen[$max]['authority'],
            ];
            unset($chosen[$dropped]);
        }
    }

    /** prohibited_content accumulates across every source. @return list<string> */
    private function union(string $field): array
    {
        $items = [];
        foreach ($this->candidates[$field] ?? [] as $candidate) {
            foreach ((array) $candidate['value'] as $item) {
                $items[mb_strtolower($item)] ??= $item;
            }
        }

        return array_values($items);
    }

    private function targetWords(ResolvedRequirements $r, Order $order, string $kind): int
    {
        $targets = [];
        if ($r->maxWords) {
            $targets[] = $this->roundTo5($r->maxWords * self::TARGET_SHARE);
        }
        if ($r->maxCharacters) {
            $targets[] = $this->roundTo5($r->maxCharacters * self::TARGET_SHARE / self::CHARS_PER_WORD);
        }

        $default = (int) (data_get($order->service_snapshot, 'default_word_limit') ?: $order->service?->default_word_limit ?: 0);
        if ($targets === []) {
            if ($default > 0) {
                $this->sources['service_default'] ??= ['name' => 'Service default length', 'url' => null, 'type' => 'service_default', 'checked_at' => null];
            }
            $targets[] = $default ?: (self::KIND_DEFAULT_WORDS[$kind] ?? 750);
            if ($r->maxPages) {
                $targets[] = $this->roundTo5($r->maxPages * self::WORDS_PER_PAGE * 0.95);
            }
        }

        $target = min($targets);
        if ($r->minWords) {
            $target = max($target, $r->minWords);
        }
        if ($r->maxWords) {
            $target = min($target, $r->maxWords);
        }

        return max(50, $target);
    }

    // ------------------------------------------------------------------ rules

    /** @return Collection<int, RequirementRule> most specific first */
    private function activeRules(): Collection
    {
        return RequirementRule::query()->where('is_active', true)->get()
            ->sortBy([
                fn (RequirementRule $a, RequirementRule $b) => $b->specificity() <=> $a->specificity(),
                fn (RequirementRule $a, RequirementRule $b) => $b->priority <=> $a->priority,
                fn (RequirementRule $a, RequirementRule $b) => $a->id <=> $b->id,
            ])
            ->values();
    }

    /** Every criterion a rule sets must match a known order value; nothing is matched on an unknown. */
    private function ruleMatches(RequirementRule $rule, array $context, ?string $platform): bool
    {
        $criteria = match ($rule->scope) {
            'country' => filled($rule->country_code),
            'platform' => filled($rule->application_platform),
            'institution', 'scholarship' => filled($rule->institution_name) || filled($rule->institution_domain),
            'programme' => filled($rule->programme_name),
            default => false,
        };
        if (! $criteria) {
            return false; // misconfigured rule: no identifying criterion for its scope
        }

        if (filled($rule->country_code) && strtoupper((string) $rule->country_code) !== $context['country']) {
            return false;
        }
        if (filled($rule->document_kinds) && ! in_array($context['kind'], (array) $rule->document_kinds, true)) {
            return false;
        }
        if (filled($rule->degree_level) && self::degreeLevel($rule->degree_level) !== $context['degree']) {
            return false;
        }
        if ($rule->scope === 'platform' && self::platformKey($rule->application_platform) !== self::platformKey($platform)) {
            return false;
        }
        if ((filled($rule->institution_name) || filled($rule->institution_domain)) && ! $this->institutionMatches($rule, $context)) {
            return false;
        }
        if (filled($rule->programme_name) && ! $this->programmeMatches((string) $rule->programme_name, $context['programme'])) {
            return false;
        }

        return true;
    }

    private function institutionMatches(RequirementRule $rule, array $context): bool
    {
        if (filled($rule->institution_name) && $context['institution'] !== '' && $this->key($rule->institution_name) === $context['institution']) {
            return true;
        }

        $domain = strtolower(trim((string) $rule->institution_domain, ' ./'));
        $domain = preg_replace('#^(https?://)?(www\.)?#', '', $domain) ?? $domain;

        return $domain !== '' && collect($context['hosts'])->contains(fn (string $host) => $host === $domain || str_ends_with($host, '.'.$domain));
    }

    private function programmeMatches(string $ruleProgramme, string $orderProgramme): bool
    {
        $wanted = $this->key($ruleProgramme);
        if ($wanted === '' || $orderProgramme === '') {
            return false;
        }

        return $wanted === $orderProgramme || preg_match('/(^| )'.preg_quote($wanted, '/').'( |$)/', $orderProgramme) === 1;
    }

    // ----------------------------------------------------------------- values

    /** @return array<string, mixed> */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field => $value) {
            if (is_string($field) && ($name = $this->fieldName($field))) {
                $normalized[$name] = $value;
            }
        }

        return $normalized;
    }

    private function fieldName(string $field): ?string
    {
        $field = Str::snake(trim($field));
        $field = self::FIELD_ALIASES[$field] ?? $field;

        return in_array($field, self::FIELDS, true) ? $field : null;
    }

    /** Validate and convert a raw value for a field; null when unusable. */
    private function coerce(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (isset(self::INT_LIMITS[$field])) {
            // "4,000 characters" → 4000, "1.5 pages" → 1, "650" → 650.
            $number = is_numeric($value) ? (int) floor((float) $value)
                : (preg_match('/\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?/', (string) $value, $m) ? (int) floor((float) str_replace(',', '', $m[0])) : 0);

            return $number > 0 && $number <= self::INT_LIMITS[$field] ? $number : null;
        }

        return match ($field) {
            'language_variant' => is_string($value) ? LanguageVariant::normalize($value) : null,
            'page_size' => is_string($value) ? TemplateSnapshot::pageSize($value) : null,
            'font_size' => $this->number($value, 6, 30),
            'margins_mm' => $this->margin($value),
            'line_spacing' => $this->lineSpacing($value),
            'required_sections' => $this->sections($value),
            'prohibited_content' => $this->stringList($value, 500),
            'file_types' => ($types = array_values(array_filter(array_map(fn ($t) => strtolower(ltrim(trim($t), '.')), $this->stringList($value, 10)), fn ($t) => preg_match('/^[a-z0-9]{2,5}$/', $t) === 1))) ? $types : null,
            'date_format' => is_string($value) && preg_match('/^[djDlNSwzFmMnYy\s,.\/-]+$/', $value) && preg_match('/[djY]/', $value) ? trim($value) : null,
            default => is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, self::STRING_FIELDS[$field] ?? 255) : null,
        };
    }

    private function number(mixed $value, float $min, float $max): ?float
    {
        $number = is_numeric($value) ? (float) $value : (preg_match('/\d+(?:\.\d+)?/', (string) $value, $m) ? (float) $m[0] : null);

        return $number !== null && $number >= $min && $number <= $max ? round($number, 2) : null;
    }

    /** "2.54 cm", "1 inch", "25 mm" → millimetres. */
    private function margin(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return $this->number($value, 5, 60);
        }
        if (! is_string($value) || ! preg_match('/(\d+(?:\.\d+)?)\s*(mm|cm|in|inch|inches|")?/i', $value, $m)) {
            return null;
        }
        $mm = (float) $m[1] * match (strtolower($m[2] ?? 'mm')) {
            'cm' => 10,
            'in', 'inch', 'inches', '"' => 25.4,
            default => 1,
        };

        return $this->number($mm, 5, 60);
    }

    private function lineSpacing(mixed $value): ?float
    {
        if (is_string($value)) {
            $text = strtolower($value);
            foreach (['double' => 2.0, 'one and a half' => 1.5, 'one-and-a-half' => 1.5, 'single' => 1.0] as $word => $spacing) {
                if (str_contains($text, $word)) {
                    return $spacing;
                }
            }
        }

        return $this->number($value, 0.8, 3);
    }

    /** @return list<array<string,mixed>>|null */
    private function sections(mixed $value): ?array
    {
        $sections = [];
        foreach ((array) $value as $section) {
            if (is_string($section) && trim($section) !== '') {
                $sections[] = ['heading' => mb_substr(trim($section), 0, 500)];
            } elseif (is_array($section) && filled($section['heading'] ?? $section['question'] ?? null)) {
                $clean = ['heading' => mb_substr(trim((string) ($section['heading'] ?? $section['question'])), 0, 500)];
                if (filled($section['question'] ?? null)) {
                    $clean['question'] = mb_substr(trim((string) $section['question']), 0, 1000);
                }
                foreach (['min_characters', 'max_characters', 'min_words', 'max_words'] as $limit) {
                    if (isset($section[$limit]) && (int) $section[$limit] > 0) {
                        $clean[$limit] = (int) $section[$limit];
                    }
                }
                $sections[] = $clean;
            }
        }

        return $sections ?: null;
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $max): array
    {
        $items = is_string($value) ? [$value] : (is_array($value) ? $value : []);

        return array_values(array_filter(array_map(fn ($item) => is_scalar($item) ? mb_substr(trim((string) $item), 0, $max) : '', $items), fn ($item) => $item !== ''));
    }

    // ---------------------------------------------------------------- helpers

    private function platformMentionedIn(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/\bucas\b/i', $text) => 'UCAS',
            (bool) preg_match('/\bcommon\s*app(lication)?\b/i', $text) => 'Common App',
            default => null,
        };
    }

    private function researchSourceName(string $type, ?string $url): string
    {
        $label = SourceType::tryFrom($type)?->getLabel() ?? Str::headline($type);
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return $host ? "{$label} ({$host})" : $label;
    }

    private function tier(int $authority): string
    {
        return match (true) {
            $authority >= 100 => 'customer-provided official instructions',
            $authority >= 75 => 'official institution guidance',
            $authority >= 55 => 'official application-platform guidance',
            $authority >= 35 => 'country guidance',
            default => 'general convention',
        };
    }

    /** Case-, accent- and punctuation-insensitive key for names ("The University of Oxford" = "University of Oxford"). */
    private function key(?string $value): string
    {
        $value = mb_strtolower(Str::ascii((string) $value));
        $value = trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '');

        return preg_replace('/^the /', '', $value) ?? $value;
    }

    private function fingerprint(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : json_encode($value);
    }

    private function roundTo5(float $value): int
    {
        return (int) (round($value / 5) * 5);
    }
}
