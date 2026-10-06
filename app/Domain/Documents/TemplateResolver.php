<?php

namespace App\Domain\Documents;

use App\Models\DocumentTemplate;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Picks the most appropriate formatting template for an order. (Owned by the document engine.)
 *
 * A template matches when every criterion in its match_rules matches the
 * order (case-insensitive): "services" (ids), "document_kinds", "countries"
 * (ISO 3166-1 alpha-2), "institutions" (names), and also "platforms" (the
 * resolved application platform, e.g. UCAS) and "degree_levels". A service's
 * assigned template counts as a service match. The most specific match wins
 * (institution > platform > service > document kind > country / degree
 * level), then the higher priority; with no match the default template is
 * used. Requirement overrides (paper, font, size, margins, spacing) are
 * applied later, at snapshot time, by TemplateSnapshot.
 */
class TemplateResolver
{
    private const WEIGHTS = [
        'institutions' => 16,
        'platforms' => 8,
        'services' => 4,
        'document_kinds' => 2,
        'countries' => 1,
        'degree_levels' => 1,
    ];

    public function resolve(Order $order, ResolvedRequirements $requirements): DocumentTemplate
    {
        $templates = DocumentTemplate::query()->where('is_active', true)->orderBy('id')->get();
        $serviceTemplateId = (int) (data_get($order->service_snapshot, 'document_template_id') ?: $order->service?->document_template_id);

        $best = null;
        $bestKey = null;
        foreach ($templates as $template) {
            $score = $this->score($template, $order, $requirements, $template->id === $serviceTemplateId);
            if ($score === null) {
                continue;
            }
            $key = [$score, (int) $template->priority, (int) $template->is_default, -$template->id];
            if ($bestKey === null || ($key <=> $bestKey) > 0) {
                [$best, $bestKey] = [$template, $key];
            }
        }

        return $best
            ?? $templates->sortByDesc(fn (DocumentTemplate $t) => [(int) $t->is_default, -$t->id])->first()
            ?? TemplateSnapshot::toTemplate(TemplateSnapshot::defaults() + ['template_name' => 'Standard application (A4)', 'template_slug' => 'standard-a4']);
    }

    /** Specificity of a matching template; null when it does not match or has nothing to match on. */
    public function score(DocumentTemplate $template, Order $order, ResolvedRequirements $requirements, bool $assignedToService = false): ?int
    {
        $rules = array_filter((array) $template->match_rules, fn ($values) => filled($values));
        $score = $assignedToService ? self::WEIGHTS['services'] : 0;

        foreach ($rules as $criterion => $values) {
            $values = array_values(array_filter(array_map(
                fn ($value) => trim((string) $value),
                is_string($values) ? explode(',', $values) : (array) $values,
            ), fn (string $value) => $value !== ''));

            $matched = match ($criterion) {
                'services' => in_array((int) $order->service_id, array_map('intval', $values), true),
                'document_kinds' => in_array(strtolower($order->documentKind()), array_map('strtolower', $values), true),
                'countries' => in_array(CountryConventions::normalizeCountry($order->country_code), array_map(CountryConventions::normalizeCountry(...), $values), true),
                'institutions' => filled($order->institution) && in_array($this->key($order->institution), array_map($this->key(...), $values), true),
                'platforms' => filled($requirements->applicationPlatform) && in_array(RequirementResolver::platformKey($requirements->applicationPlatform), array_map(RequirementResolver::platformKey(...), $values), true),
                'degree_levels' => filled($order->degree_level) && in_array(RequirementResolver::degreeLevel($order->degree_level), array_map(RequirementResolver::degreeLevel(...), $values), true),
                default => null, // unknown criteria are ignored
            };

            if ($matched === false) {
                return null;
            }
            if ($matched === true) {
                $score += self::WEIGHTS[$criterion];
            }
        }

        return $score > 0 ? $score : null;
    }

    private function key(?string $value): string
    {
        $value = trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(Str::ascii((string) $value))) ?? '');

        return preg_replace('/^the /', '', $value) ?? $value;
    }
}
