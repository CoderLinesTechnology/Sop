<?php

namespace App\Domain\Documents;

/**
 * The structured, approved content of a document. Both the PDF and the DOCX
 * are rendered from this single model, so the two files always carry
 * identical text.
 *
 * Block types:
 *  - heading:    a section heading (e.g. one UCAS question, or "Professional Experience")
 *  - subheading: an entry line under a heading, e.g. a CV role
 *                ("Science Teacher | Messiah Baptist JHS | 2023–Present")
 *  - paragraph:  body text
 *  - bullet:     one list item; consecutive bullets form one list
 *  - salutation: letter opening ("Dear Admissions Committee,")
 *  - closing:    letter closing ("Yours sincerely,")
 *  - signature:  name under a letter closing
 * Unknown types are kept as paragraphs.
 *
 * Length limits apply to the body (all blocks), never to the title area
 * (title, subtitle, applicant name, contact line, date, a letter's address
 * blocks). The contact line and the address blocks are added by the document
 * engine from the order (never by, or shown to, a model).
 */
final class DocumentModel
{
    public const BLOCK_TYPES = ['heading', 'subheading', 'paragraph', 'bullet', 'salutation', 'closing', 'signature'];

    /** Blocks that carry running body text (sentences): checked for style, facts and copying like paragraphs. */
    public const BODY_TYPES = ['paragraph', 'bullet'];

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
        public ?string $contact = null,
        public ?string $address = null,
        public ?string $recipient = null,
    ) {
        $this->blocks = array_values(array_filter(array_map(
            function (array $block): array {
                $type = in_array($block['type'] ?? '', self::BLOCK_TYPES, true) ? $block['type'] : 'paragraph';
                $text = self::cleanText((string) ($block['text'] ?? ''));

                // The renderers draw the bullet; a typed marker would show twice.
                return ['type' => $type, 'text' => $type === 'bullet' ? self::withoutListMarker($text) : $text];
            },
            $blocks,
        ), fn (array $block) => $block['text'] !== ''));
        $contact = str_replace("\n", ' ', self::cleanText((string) $contact)); // one line under the name
        $this->contact = $contact === '' ? null : $contact;
        // A letter's address blocks keep their line breaks (one line per address line).
        $this->address = self::cleanText((string) $address) ?: null;
        $this->recipient = self::cleanText((string) $recipient) ?: null;
    }

    public static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text; // control chars
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim(preg_replace("/\n{2,}/", "\n", $text) ?? $text);
    }

    /** "• Led the team" → "Led the team" (also -, *, ▪, ◦ and similar list markers). */
    public static function withoutListMarker(string $text): string
    {
        return trim(preg_replace('/^(?:[•·▪◦‣∙●○■□➢➤►–*-]|\d{1,2}[.)])(?:\s+|$)/u', '', $text) ?? $text);
    }

    /** Body text used for limits and quality checks. */
    public function bodyText(): string
    {
        return implode("\n\n", array_column($this->blocks, 'text'));
    }

    /** Everything visible in the document, in reading order (used for file QA). */
    public function fullText(): string
    {
        return implode("\n\n", array_filter([$this->title, $this->subtitle, $this->applicantName, $this->contact, $this->address, $this->date, $this->recipient, $this->bodyText()]));
    }

    public function wordCount(): int
    {
        return WordCounter::words($this->bodyText());
    }

    public function characterCount(bool $withSpaces = true): int
    {
        return WordCounter::characters($this->bodyText(), $withSpaces);
    }

    /**
     * Running body text in order: paragraphs and bullet items (what style
     * checks read as sentences). Headings, entry lines and letter parts are
     * not included.
     *
     * @return list<string>
     */
    public function paragraphs(): array
    {
        return array_values(array_column(array_filter($this->blocks, fn ($b) => self::isBody($b['type'])), 'text'));
    }

    public static function isBody(string $type): bool
    {
        return in_array($type, self::BODY_TYPES, true);
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
        ] + array_filter(['contact' => $this->contact, 'address' => $this->address, 'recipient' => $this->recipient], fn ($value) => $value !== null);
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
            contact: isset($data['contact']) ? (string) $data['contact'] : null,
            address: isset($data['address']) ? (string) $data['address'] : null,
            recipient: isset($data['recipient']) ? (string) $data['recipient'] : null,
        );
    }
}
