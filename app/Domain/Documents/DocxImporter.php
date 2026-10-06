<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Qa\DocxInspector;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Builds a DocumentModel from a Word document's body paragraphs (used when
 * an administrator uploads the final DOCX). Paragraph styles identify the
 * title, subtitle and headings; letter parts (date, salutation, closing,
 * signature) are recognised by their wording and position. Headers and
 * footers are not content and are ignored.
 */
class DocxImporter
{
    public function __construct(private readonly DocxInspector $inspector) {}

    /**
     * @return array{model: DocumentModel, has_title: bool, has_applicant_name: bool}
     *
     * @throws RuntimeException when the file is not a readable DOCX or has no text
     */
    public function import(string $bytes, string $fallbackTitle, string $languageVariant, ?string $applicantName = null): array
    {
        $opened = $this->inspector->open($bytes);
        if (! $opened['ok']) {
            throw new RuntimeException('The Word document could not be read: '.$opened['error']);
        }

        $paragraphs = [];
        foreach ($this->inspector->paragraphs($opened['parts']['word/document.xml']) as $paragraph) {
            $text = DocumentModel::cleanText($paragraph['text']);
            if ($text !== '') {
                $paragraphs[] = ['text' => $text, 'style' => strtolower((string) $paragraph['style'])] + $paragraph;
            }
        }
        if ($paragraphs === []) {
            throw new RuntimeException('The Word document contains no text.');
        }

        $title = $subtitle = $date = $name = null;
        $blocks = [];
        $closed = false;

        foreach ($paragraphs as $i => $p) {
            $text = $p['text'];
            $front = $blocks === [];

            if ($front && $title === null && $i <= 1 && ($p['style'] === 'title' || ($i === 0 && $this->looksLikeTitle($p, count($paragraphs))))) {
                $title = $text;
            } elseif ($front && $title !== null && $subtitle === null && $p['style'] === 'subtitle') {
                $subtitle = $text;
            } elseif ($front && $name === null && $applicantName !== null && mb_strtolower($text) === mb_strtolower(trim($applicantName))) {
                $name = $text;
            } elseif ($front && $date === null && $this->looksLikeDate($text)) {
                $date = $text;
            } elseif (preg_match('/^heading\s?\d$/', $p['style'])) {
                $blocks[] = ['type' => 'heading', 'text' => $text];
            } elseif ($closed) {
                $blocks[] = ['type' => 'signature', 'text' => $text];
            } elseif ($this->isSalutation($text) && ! collect($blocks)->contains('type', 'salutation')) {
                $blocks[] = ['type' => 'salutation', 'text' => $text];
            } elseif ($this->isClosing($text)) {
                $blocks[] = ['type' => 'closing', 'text' => $text];
                $closed = true;
            } else {
                $blocks[] = ['type' => 'paragraph', 'text' => $text];
            }
        }

        return [
            'model' => new DocumentModel(
                title: $title ?? $fallbackTitle,
                subtitle: $subtitle,
                applicantName: $name ?? $applicantName,
                blocks: $blocks,
                languageVariant: LanguageVariant::normalize($languageVariant) ?? LanguageVariant::DEFAULT,
                date: $date,
            ),
            'has_title' => $title !== null,
            'has_applicant_name' => $name !== null,
        ];
    }

    /** A short, bold or centred first line followed by more text. */
    private function looksLikeTitle(array $paragraph, int $count): bool
    {
        return $count > 1
            && mb_strlen($paragraph['text']) <= 120
            && ! str_ends_with($paragraph['text'], '.')
            && (in_array($paragraph['style'], ['heading1', 'heading 1'], true) || $paragraph['align'] === 'center' || $paragraph['bold']);
    }

    private function looksLikeDate(string $text): bool
    {
        if (mb_strlen($text) > 40 || preg_match('/\d{4}/', $text) !== 1) {
            return false;
        }

        try {
            CarbonImmutable::parse(preg_replace('/^[\pL\s]+,\s*(?=\d)/u', '', $text) ?? $text); // "Berlin, 6 October 2026"

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isSalutation(string $text): bool
    {
        return mb_strlen($text) <= 100 && preg_match('/^(dear|to whom it may concern|hello)\b/i', $text) === 1;
    }

    private function isClosing(string $text): bool
    {
        return preg_match('/^(yours (sincerely|faithfully|truly)|sincerely( yours)?|(kind|best|warm|warmest) regards|regards|respectfully( yours)?|with (best|kind) regards)[,.!]?$/i', $text) === 1;
    }
}
