<?php

namespace App\Domain\Ai\Writing;

use App\Domain\Documents\DocumentModel;

/** Converts between model "document_draft" output and the DocumentModel. */
final class DraftConverter
{
    public static function toModel(array $data, string $fallbackTitle, string $languageVariant, ?string $applicantName = null): DocumentModel
    {
        $blocks = [];
        foreach ((array) ($data['blocks'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'paragraph');
            $text = self::stripMarkdown((string) ($block['text'] ?? ''));

            // Paragraph blocks must not smuggle in several paragraphs.
            if ($type === 'paragraph' && str_contains($text, "\n\n")) {
                foreach (preg_split("/\n{2,}/", $text) ?: [] as $part) {
                    $blocks[] = ['type' => 'paragraph', 'text' => $part];
                }

                continue;
            }

            $blocks[] = ['type' => $type, 'text' => $text];
        }

        $title = trim(self::stripMarkdown((string) ($data['title'] ?? '')));

        return new DocumentModel(
            title: $title !== '' ? $title : $fallbackTitle,
            subtitle: null,
            applicantName: $applicantName,
            blocks: $blocks,
            languageVariant: $languageVariant,
        );
    }

    /** The draft as shown to a model: numbered blocks and its current counts. */
    public static function forPrompt(DocumentModel $document): array
    {
        $blocks = [];
        foreach ($document->blocks as $i => $block) {
            $blocks[] = ['index' => $i + 1, 'type' => $block['type'], 'text' => $block['text']];
        }

        return [
            'title' => $document->title,
            'blocks' => $blocks,
            'word_count' => $document->wordCount(),
            'character_count' => $document->characterCount(true),
        ];
    }

    private static function stripMarkdown(string $text): string
    {
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;   // headings
        $text = preg_replace('/(\*\*|__)(.+?)\1/su', '$2', $text) ?? $text;  // bold
        $text = preg_replace('/^\s*[-*•]\s+/m', '', $text) ?? $text;         // bullets

        return trim($text);
    }
}
