<?php

namespace App\Domain\Documents;

use App\Enums\DocumentKind;
use App\Models\Order;
use App\Support\Countries;

/**
 * The customer's own contact line shown under the name on a CV, e.g.
 * "ama@example.com | +233 24 123 4567 | Accra, Ghana | linkedin.com/in/ama".
 * (Owned by the document engine.)
 *
 * Built deterministically from the order when the version is created and
 * stored with the version's content, so re-renders and file QA see the same
 * line. Personal data: it is added only to the customer's own CV, never sent
 * to a model (StageContext::answers() leaves these fields out, and drafts
 * reach models without it) and never logged.
 *
 * Sources: the delivery email and phone number from the order, and, when the
 * service form has such fields, the answers listed in the *_KEYS constants.
 * The order's country is not used: it is the destination country (where the
 * customer is applying), not where they live.
 *
 * Templates choose how the stored line is shown (runs()): as stored on one
 * line, or as a two-line block with "Tel:" / "LinkedIn:" labels, links in
 * colour. Only the presentation changes; the stored line stays the same.
 */
final class ContactLine
{
    /** Document kinds whose documents carry a contact line. */
    public const KINDS = [DocumentKind::Resume];

    public const LOCATION_KEYS = ['location', 'current_location', 'city', 'city_country', 'based_in', 'residence', 'country_of_residence', 'current_country'];

    public const LINKEDIN_KEYS = ['linkedin', 'linkedin_url', 'linkedin_profile'];

    public const WEBSITE_KEYS = ['website', 'website_url', 'personal_website', 'portfolio', 'portfolio_url'];

    private const SEPARATOR = ' | ';

    private const MAX_PART = 100;

    /** Parts drawn in a template's link colour. */
    private const LINK_KINDS = ['email', 'linkedin', 'website'];

    /** Labels of the two-line contact block. */
    private const LABELS = ['phone' => 'Tel: ', 'linkedin' => 'LinkedIn: ', 'website' => 'Website: '];

    private const LABEL_PATTERN = '/^(tel|telephone|phone|mobile|e-?mail|linkedin|website|web|portfolio)\s*:\s*(\S.*)$/iu';

    /** Answer keys that hold contact details (excluded from everything sent to a model). */
    public static function answerKeys(): array
    {
        return [...self::LOCATION_KEYS, ...self::LINKEDIN_KEYS, ...self::WEBSITE_KEYS];
    }

    public static function appliesTo(string $documentKind): bool
    {
        return in_array(DocumentKind::tryFrom($documentKind), self::KINDS, true);
    }

    /** The order's contact line, or null when the document kind has none or nothing is known. */
    public static function forOrder(Order $order): ?string
    {
        if (! self::appliesTo($order->documentKind())) {
            return null;
        }

        $answers = $order->answers()->whereIn('field_key', self::answerKeys())->get(['field_key', 'type', 'value'])
            ->mapWithKeys(fn ($answer) => [$answer->field_key => ['type' => (string) $answer->type, 'value' => $answer->value]])
            ->all();

        return self::build($order->email, $order->customer_phone, $answers);
    }

    /**
     * @param  array<string, array{type:string, value:mixed}>  $answers  answers keyed by field key
     */
    public static function build(?string $email, ?string $phone, array $answers = []): ?string
    {
        $parts = array_filter([
            self::email($email),
            self::phone($phone),
            self::location($answers),
            self::link($answers, self::LINKEDIN_KEYS, linkedin: true),
            self::link($answers, self::WEBSITE_KEYS, linkedin: false),
        ]);

        return $parts === [] ? null : implode(self::SEPARATOR, $parts);
    }

    /**
     * The stored line as a template shows it, in runs of text; "link" marks the
     * parts drawn in the template's link colour (email, LinkedIn, website).
     *
     * single_line: exactly the stored text. two_lines: "email | Tel: phone |
     * location", a line break, then "LinkedIn: … | Website: …" (either line is
     * left out when empty). Nothing is dropped: unrecognised parts stay on the
     * first line.
     *
     * @return list<array{text:string, link:bool}>
     */
    public static function runs(string $line, string $layout = 'single_line'): array
    {
        $runs = [];
        if ($layout !== 'two_lines') {
            foreach (preg_split('/(\s*\|\s*)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $runs[] = ['text' => $part, 'link' => in_array(self::part($part)['kind'], self::LINK_KINDS, true)];
            }

            return self::merge($runs);
        }

        $parts = self::parts($line);
        $first = array_values(array_filter($parts, fn (array $p) => ! in_array($p['kind'], ['linkedin', 'website'], true)));
        $second = array_values(array_filter($parts, fn (array $p) => in_array($p['kind'], ['linkedin', 'website'], true)));

        foreach ([$first, $second] as $lineParts) {
            if ($lineParts === []) {
                continue;
            }
            if ($runs !== []) {
                $runs[] = ['text' => "\n", 'link' => false];
            }
            foreach ($lineParts as $i => $part) {
                if ($i > 0) {
                    $runs[] = ['text' => self::SEPARATOR, 'link' => false];
                }
                $runs[] = ['text' => (self::LABELS[$part['kind']] ?? '').$part['text'], 'link' => in_array($part['kind'], self::LINK_KINDS, true)];
            }
        }

        return self::merge($runs);
    }

    /**
     * A contact block read back from a file (DocxImporter) as one stored line:
     * its lines joined like its parts ("a@b.co | Tel: 024…\nLinkedIn: …" →
     * "a@b.co | Tel: 024… | LinkedIn: …"). Labels stay, as in the file; a
     * two-line layout recognises them and does not repeat them.
     */
    public static function fromDisplay(string $text): string
    {
        return trim(preg_replace('/\s*\n\s*/u', self::SEPARATOR, trim($text)) ?? $text);
    }

    /** @return list<array{kind:string, text:string}> parts of a stored or displayed line, labels removed */
    private static function parts(string $line): array
    {
        $parts = [];
        foreach (preg_split('/\s*[|\n]\s*/u', $line) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $parts[] = self::part($part);
            }
        }

        return $parts;
    }

    /** @return array{kind:string, text:string} kind: email, phone, linkedin, website or other (e.g. the location) */
    private static function part(string $part): array
    {
        $original = trim($part);
        $label = null;
        if (preg_match(self::LABEL_PATTERN, $part, $m) === 1) {
            [$label, $part] = [strtolower($m[1]), trim($m[2])];
        }

        $kind = match (true) {
            self::email($part) !== null => 'email',
            preg_match('#(?:^|[/.])linkedin\.com(?:/|$)#i', $part) === 1 => 'linkedin',
            self::phone($part) !== null => 'phone',
            in_array($label, ['website', 'web', 'portfolio'], true),
            preg_match('#^(?:https?://)?(?:[a-z0-9-]+\.)+[a-z]{2,}(?:[/?\#]\S*)?$#i', $part) === 1 => 'website',
            default => 'other',
        };

        // A label is only dropped when the layout shows its own (unrecognised text keeps it).
        return ['kind' => $kind, 'text' => $kind === 'other' ? $original : trim($part)];
    }

    /** @param list<array{text:string, link:bool}> $runs */
    private static function merge(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last]['link'] === $run['link']) {
                $merged[$last]['text'] .= $run['text'];
            } else {
                $merged[] = $run;
            }
        }

        return $merged;
    }

    private static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && mb_strlen($email) <= self::MAX_PART && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Digits, spaces and + ( ) - . only (as the order form accepts them). */
    private static function phone(?string $phone): ?string
    {
        $phone = trim(preg_replace('/\s+/u', ' ', (string) $phone) ?? '');

        return preg_match('/^[0-9 ()+\-.]{5,40}$/', $phone) === 1 && preg_match_all('/\d/', $phone) >= 5 ? $phone : null;
    }

    /** "Accra, Ghana" from a city and/or a country of residence (ISO codes become country names). */
    private static function location(array $answers): ?string
    {
        $parts = [];
        foreach (self::LOCATION_KEYS as $key) {
            $value = self::text($answers[$key]['value'] ?? null);
            if ($value === null) {
                continue;
            }
            if (($answers[$key]['type'] ?? '') === 'country' || preg_match('/^[A-Za-z]{2}$/', $value) === 1) {
                $value = Countries::name($value) ?? (($answers[$key]['type'] ?? '') === 'country' ? null : $value);
            }
            if ($value !== null && ! in_array(mb_strtolower($value), array_map('mb_strtolower', $parts), true)) {
                $parts[] = $value;
            }
        }

        $location = implode(', ', $parts);

        return $location !== '' && mb_strlen($location) <= self::MAX_PART ? $location : null;
    }

    /**
     * A LinkedIn profile or personal website written as an address alone
     * ("https://www.linkedin.com/in/ama/?trk=x" → "linkedin.com/in/ama").
     * Anything else in those fields (sentences, other sites as LinkedIn) is ignored.
     */
    private static function link(array $answers, array $keys, bool $linkedin): ?string
    {
        foreach ($keys as $key) {
            $value = self::text($answers[$key]['value'] ?? null);
            if ($value === null || preg_match('/\s/u', $value) === 1) {
                continue;
            }

            $url = preg_match('#^https?://#i', $value) === 1 ? $value : 'https://'.$value;
            $parts = parse_url($url);
            if ($parts === false || ! filter_var($url, FILTER_VALIDATE_URL) || isset($parts['user']) || isset($parts['port'])) {
                continue;
            }
            $host = mb_strtolower((string) ($parts['host'] ?? ''));
            if (preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $host) !== 1) {
                continue;
            }

            $isLinkedin = $host === 'linkedin.com' || str_ends_with($host, '.linkedin.com');
            if ($isLinkedin !== $linkedin) {
                continue;
            }

            // Shown without scheme, "www.", query or fragment (no tracking parameters on a CV).
            $display = preg_replace('/^www\./', '', $host).rtrim((string) ($parts['path'] ?? ''), '/');
            if ($linkedin) {
                $display = preg_replace('/^[a-z]{2}\.linkedin\.com/', 'linkedin.com', $display) ?? $display;
            }

            return mb_strlen($display) <= self::MAX_PART ? $display : null;
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', DocumentModel::cleanText((string) $value)) ?? '');

        return $text === '' ? null : $text;
    }
}
