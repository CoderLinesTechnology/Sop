<?php

namespace App\Domain\Documents;

/**
 * The structured, approved content of a document. Both the PDF and the DOCX
 * are rendered from this single model, so the two files always carry
 * identical text.
 *
 * Block types:
 *  - heading:    a section heading (e.g. one UCAS question)
 *  - paragraph:  body text
 *  - salutation: letter opening ("Dear Admissions Committee,")
 *  - closing:    letter closing ("Yours sincerely,")
 *  - signature:  name under a letter closing
 *
 * Length limits apply to the body (all blocks), never to the title area
 * (title, subtitle, applicant name, date).
 */
final class DocumentModel
{
    public const BLOCK_TYPES = ['heading', 'paragraph', 'salutation', 'closing', 'signature'];

    /**
     * @param  list<array{type:string,text:string}>  $blocks
     */
    public function __construct(
        public string $title,
        public ?string $subtitle,
        public ?string $applicantName,
        public array $blocks,
        public string $languageVariant = 'en-GB',
        public ?string $date = null,
    ) {
        $this->blocks = array_values(array_filter(array_map(
            fn (array $block) => [
                'type' => in_array($block['type'] ?? '', self::BLOCK_TYPES, true) ? $block['type'] : 'paragraph',
                'text' => self::cleanText((string) ($block['text'] ?? '')),
            ],
            $blocks,
        ), fn (array $block) => $block['text'] !== ''));
    }

    public static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text; // control chars
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim(preg_replace("/\n{2,}/", "\n", $text) ?? $text);
    }

    /** Body text used for limits and quality checks. */
    public function bodyText(): string
    {
        return implode("\n\n", array_column($this->blocks, 'text'));
    }

    /** Everything visible in the document, in reading order (used for file QA). */
    public function fullText(): string
    {
        return implode("\n\n", array_filter([$this->title, $this->subtitle, $this->applicantName, $this->date, $this->bodyText()]));
    }

    public function wordCount(): int
    {
        return WordCounter::words($this->bodyText());
    }

    public function characterCount(bool $withSpaces = true): int
    {
        return WordCounter::characters($this->bodyText(), $withSpaces);
    }

    /** @return list<string> */
    public function paragraphs(): array
    {
        return array_column(array_filter($this->blocks, fn ($b) => $b['type'] === 'paragraph'), 'text');
    }

    /**
     * Body blocks grouped under their headings (e.g. one group per UCAS
     * question). Blocks before the first heading form a group with a null
     * heading.
     *
     * @return list<array{heading:?string, text:string}>
     */
    public function sections(): array
    {
        $sections = [];
        $current = null;
        foreach ($this->blocks as $block) {
            if ($block['type'] === 'heading') {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['heading' => $block['text'], 'texts' => []];

                continue;
            }
            $current ??= ['heading' => null, 'texts' => []];
            $current['texts'][] = $block['text'];
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        return array_map(fn (array $s) => ['heading' => $s['heading'], 'text' => implode("\n\n", $s['texts'])], $sections);
    }

    /** Body text without headings: what applicants type into a portal whose form already shows the questions. */
    public function answerText(): string
    {
        return implode("\n\n", array_column(array_filter($this->blocks, fn ($b) => $b['type'] !== 'heading'), 'text'));
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'applicant_name' => $this->applicantName,
            'date' => $this->date,
            'language_variant' => $this->languageVariant,
            'blocks' => $this->blocks,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) ($data['title'] ?? ''),
            subtitle: $data['subtitle'] ?? null,
            applicantName: $data['applicant_name'] ?? null,
            blocks: (array) ($data['blocks'] ?? []),
            languageVariant: (string) ($data['language_variant'] ?? 'en-GB'),
            date: $data['date'] ?? null,
        );
    }
}
