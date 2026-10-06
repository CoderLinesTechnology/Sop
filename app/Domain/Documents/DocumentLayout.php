<?php

namespace App\Domain\Documents;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * What a version looks like on the page, computed once from the version's
 * DocumentModel and template snapshot and shared by the PDF renderer, the
 * DOCX renderer and file QA. Because all three read the same item list, both
 * files carry exactly the same text, and QA knows exactly what to expect.
 *
 * Items are the visible paragraphs in reading order. Roles: applicant_name,
 * date, title, subtitle (the "front matter") and the model's block types
 * (heading, paragraph, salutation, closing, signature).
 */
final class DocumentLayout
{
    public const LETTER_KINDS = ['motivation_letter', 'cover_letter'];

    private const FRONT_MATTER = ['applicant_name', 'date', 'title', 'subtitle'];

    /**
     * @param  list<array{role:string,text:string}>  $items
     * @param  list<array{text:string,align:string,page_number:bool}>  $header
     * @param  list<array{text:string,align:string,page_number:bool}>  $footer
     * @param  list<string>  $hidden  model parts this template does not show
     */
    private function __construct(
        public readonly array $items,
        public readonly array $header,
        public readonly array $footer,
        public readonly array $snapshot,
        public readonly bool $isLetter,
        public readonly array $hidden,
        public readonly string $title,
        public readonly ?string $applicantName,
        public readonly string $languageVariant,
    ) {}

    public static function make(DocumentModel $model, array $snapshot): self
    {
        $s = TemplateSnapshot::normalize($snapshot);
        $context = $s['context'];
        $name = self::clean($model->applicantName);
        $values = [
            'applicant_name' => $name,
            'title' => $model->title,
            'document_type' => $context['document_type'],
            'institution' => $context['institution'],
            'programme' => $context['programme'],
        ];

        $title = $s['title_template'] ? self::fill($s['title_template'], $values) : self::clean($model->title);
        $title = $title ?: (string) self::clean($model->title);
        $subtitle = self::clean($model->subtitle);
        $isLetter = self::isLetter($model, (string) $context['document_kind']);
        $date = self::formatDate($model->date, $s['date_format'] ?: LanguageVariant::dateFormat($model->languageVariant));
        $position = $s['applicant_name_position'];
        $showTitle = $s['show_title'] && $title !== '';

        $items = [];
        $hidden = [];
        $push = function (string $role, ?string $text) use (&$items): void {
            if ($text !== null && $text !== '') {
                $items[] = ['role' => $role, 'text' => $text];
            }
        };

        if ($position === 'above_title') {
            $push('applicant_name', $name);
        }
        if ($isLetter) {
            $push('date', $date);
        }
        if ($showTitle) {
            $push('title', $title);
            $push('subtitle', $subtitle);
        } else {
            array_push($hidden, ...array_keys(array_filter(['title' => $title, 'subtitle' => $subtitle])));
        }
        if ($position === 'below_title') {
            $push('applicant_name', $name);
        }
        if (! $isLetter) {
            $push('date', $date);
        }
        if ($name && $position === 'none') {
            $hidden[] = 'applicant_name';
        }
        foreach ($model->blocks as $block) {
            $push($block['type'], $block['text']);
        }

        return new self(
            items: $items,
            header: self::headerLines($s, $values, $name),
            footer: self::footerLines($s, $values, $name),
            snapshot: $s,
            isLetter: $isLetter,
            hidden: $hidden,
            title: $title,
            applicantName: $name,
            languageVariant: LanguageVariant::normalize($model->languageVariant) ?? $s['language_variant'],
        );
    }

    /** Letters (motivation and cover letters, or any model with letter blocks) get a date line and letter spacing. */
    public static function isLetter(DocumentModel $model, string $documentKind = ''): bool
    {
        if (in_array($documentKind, self::LETTER_KINDS, true)) {
            return true;
        }

        return collect($model->blocks)->contains(fn (array $block) => in_array($block['type'], ['salutation', 'closing'], true));
    }

    /** Everything visible in the body, in reading order (what QA expects to extract from both files). */
    public function text(): string
    {
        return implode("\n\n", array_column($this->items, 'text'));
    }

    /**
     * Paragraph formatting for an item, shared by both renderers so the PDF
     * and DOCX are spaced identically. Sizes in points, indent in mm.
     *
     * @return array{size:float,bold:bool,italic:bool,align:string,line:float,before:float,after:float,indent_mm:float,keep_next:bool}
     */
    public function format(int $index): array
    {
        $format = $this->baseFormat($index);

        // Nothing follows the last paragraph: no trailing space that could spill onto an empty page.
        if ($index === count($this->items) - 1) {
            $format['after'] = 0.0;
            $format['keep_next'] = false;
        }

        return $format;
    }

    private function baseFormat(int $index): array
    {
        $s = $this->snapshot;
        $role = $this->items[$index]['role'];
        $next = $this->items[$index + 1]['role'] ?? null;
        $body = (float) $s['font_size'];
        $paragraphGap = (float) $s['paragraph_spacing_pt'];
        $frontAlign = $this->isLetter ? 'left' : $s['title_align'];

        $format = [
            'size' => $body, 'bold' => false, 'italic' => false, 'align' => 'left', 'line' => (float) $s['line_spacing'],
            'before' => 0.0, 'after' => $paragraphGap, 'indent_mm' => 0.0, 'keep_next' => false,
        ];

        if (in_array($role, self::FRONT_MATTER, true)) {
            // The title area is single-spaced; its last line is separated from the body by a wider gap.
            $format = array_merge($format, [
                'line' => 1.0,
                'align' => $role === 'title' ? $s['title_align'] : $frontAlign,
                'after' => in_array($next, self::FRONT_MATTER, true) ? 4.0 : max(12.0, $paragraphGap * 1.5),
                'keep_next' => true,
            ]);
            if ($role === 'title') {
                $format['size'] = (float) $s['title_font_size'];
                $format['bold'] = true;
                $format['after'] = in_array($next, self::FRONT_MATTER, true) ? 6.0 : $format['after'];
            }
            if ($role === 'subtitle') {
                $format['italic'] = true;
            }

            return $format;
        }

        return match ($role) {
            'heading' => array_merge($format, [
                'size' => (float) $s['heading_font_size'], 'bold' => true, 'line' => 1.0,
                'before' => $index > 0 && ! in_array($this->items[$index - 1]['role'], self::FRONT_MATTER, true) ? 6.0 : 0.0,
                'after' => 4.0, 'keep_next' => true,
            ]),
            'paragraph' => array_merge($format, [
                'align' => $s['text_align'],
                'indent_mm' => (float) $s['first_line_indent_mm'],
            ]),
            'salutation' => array_merge($format, ['keep_next' => true]),
            'closing' => array_merge($format, ['after' => max($paragraphGap, 24.0), 'keep_next' => true]),
            'signature' => array_merge($format, ['after' => $next === 'signature' ? 0.0 : $paragraphGap]),
            default => $format,
        };
    }

    /** Font size for header and footer lines. */
    public function marginalFontSize(): float
    {
        return max(8.0, (float) $this->snapshot['font_size'] - 2);
    }

    /**
     * Distance in mm between the page edge and the header/footer: Word's
     * default 12.7 mm, reduced when the margin is too small for its lines so
     * they never overlap the body text.
     */
    public function marginalDistance(string $where): float
    {
        $lines = count($where === 'header' ? $this->header : $this->footer);
        $margin = (float) $this->snapshot[$where === 'header' ? 'margin_top_mm' : 'margin_bottom_mm'];
        $blockMm = $lines * $this->marginalFontSize() * FontRegistry::lineFactor($this->snapshot['font_family']) * 25.4 / 72;

        return round(max(4.0, min(12.7, $margin - $blockMm - 3.0)), 2);
    }

    /** Applicant name, when it appears anywhere in the document (used as the files' author). */
    public function visibleApplicantName(): ?string
    {
        if ($this->applicantName === null) {
            return null;
        }

        $visible = $this->text()."\n".implode("\n", array_column([...$this->header, ...$this->footer], 'text'));

        return str_contains($visible, $this->applicantName) ? $this->applicantName : null;
    }

    /** A page-number line with real numbers ("Page 2 of 3"), as QA expects to read it. */
    public static function pageNumberText(string $format, int $page, int $total): string
    {
        return str_replace(['{PAGE}', '{NUMPAGES}'], [(string) $page, (string) $total], $format);
    }

    /** Header/footer line text for a given page (page-number lines resolved). */
    public static function marginalText(array $line, int $page, int $total): string
    {
        return $line['page_number'] ? self::pageNumberText($line['text'], $page, $total) : $line['text'];
    }

    /**
     * Normalise a page-number format to the {PAGE} / {NUMPAGES} placeholders
     * (accepting common aliases) and make sure it shows the page number.
     */
    public static function pageNumberFormat(string $format): string
    {
        $format = preg_replace(['/\{\s*(PAGE|PAGENO|PAGE_NUMBER|N)\s*\}/i', '/\{\s*(NUMPAGES|PAGES|TOTAL|TOTAL_PAGES|NBPG|NB)\s*\}/i'], ['{PAGE}', '{NUMPAGES}'], trim($format)) ?? '';
        $format = preg_replace('/\{(?!PAGE\}|NUMPAGES\})[^}]*\}/', '', $format) ?? '';
        $format = trim(preg_replace('/\s+/', ' ', $format) ?? '');

        return str_contains($format, '{PAGE}') ? $format : trim($format.' {PAGE}');
    }

    /** Replace {placeholders} and tidy separators left behind by empty values ("Statement of Purpose — "). */
    public static function fill(string $template, array $values): string
    {
        $text = preg_replace_callback('/\{\s*([a-z_]+)\s*\}/i', fn (array $m) => (string) ($values[strtolower($m[1])] ?? ''), $template) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = preg_replace('/(\s*[—–|·•,:]\s*)+(?=[—–|·•,:]\s)/u', ' ', $text) ?? $text;
        $text = preg_replace('/^[\s—–|·•,:-]+|[\s—–|·•,:-]+$/u', '', $text) ?? $text;

        return trim(str_replace(['{', '}'], '', $text));
    }

    /** @return list<array{text:string,align:string,page_number:bool}> */
    private static function headerLines(array $s, array $values, ?string $name): array
    {
        $lines = [];
        $text = self::marginalWithName($s['header_text'], $values, $name, $s['applicant_name_position'] === 'header');
        if ($text !== '') {
            $lines[] = ['text' => $text, 'align' => 'right', 'page_number' => false];
        }
        if (str_starts_with($s['page_numbers'], 'top_')) {
            $lines[] = ['text' => self::pageNumberFormat($s['page_number_format']), 'align' => self::positionAlign($s['page_numbers']), 'page_number' => true];
        }

        return $lines;
    }

    /** @return list<array{text:string,align:string,page_number:bool}> */
    private static function footerLines(array $s, array $values, ?string $name): array
    {
        $lines = [];
        $text = self::marginalWithName($s['footer_text'], $values, $name, $s['applicant_name_position'] === 'footer');
        if ($text !== '') {
            $lines[] = ['text' => $text, 'align' => 'center', 'page_number' => false];
        }
        if (str_starts_with($s['page_numbers'], 'bottom_')) {
            $lines[] = ['text' => self::pageNumberFormat($s['page_number_format']), 'align' => self::positionAlign($s['page_numbers']), 'page_number' => true];
        }
        // Statementra branding only when the template explicitly asks for it.
        if ($s['include_branding'] && filled($s['context']['brand'] ?? null)) {
            $lines[] = ['text' => (string) $s['context']['brand'], 'align' => 'center', 'page_number' => false];
        }

        return $lines;
    }

    private static function marginalWithName(?string $template, array $values, ?string $name, bool $withName): string
    {
        $text = $template ? self::fill($template, $values) : '';
        if ($withName && $name && ! str_contains((string) $template, '{applicant_name}')) {
            $text = $text !== '' ? "{$name} — {$text}" : $name;
        }

        return $text;
    }

    private static function positionAlign(string $position): string
    {
        return match (true) {
            str_ends_with($position, '_left') => 'left',
            str_ends_with($position, '_right') => 'right',
            default => 'center',
        };
    }

    /** ISO dates ("2026-10-06") are formatted with the template/variant format; anything else is shown as written. */
    private static function formatDate(?string $date, string $format): ?string
    {
        $date = self::clean($date);
        if ($date === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ][\d:.]+(?:Z|[+-]\d{2}:?\d{2})?)?$/', $date) === 1) {
            try {
                return CarbonImmutable::parse($date)->format($format);
            } catch (Throwable) {
                return $date;
            }
        }

        return $date;
    }

    private static function clean(?string $text): ?string
    {
        $text = $text === null ? '' : DocumentModel::cleanText($text);

        return $text === '' ? null : $text;
    }
}
