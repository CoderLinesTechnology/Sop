<?php

namespace App\Domain\Documents\Renderers;

use App\Domain\Documents\DocumentLayout;
use App\Domain\Documents\FontRegistry;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a DocumentLayout to PDF with mPDF: embedded metric-compatible fonts
 * (subsets), selectable text, exact page size and margins, line pitch equal
 * to Word's for the same spacing, and neutral metadata (title, and the
 * applicant as author only when the document itself shows the name).
 */
final class PdfRenderer
{
    /**
     * mPDF replaces its page-count aliases anywhere on the page, body text
     * included; unusual tokens keep "{nb}" in a customer's text literal.
     */
    private const TOTAL_PAGES_ALIAS = '{st:nb}';

    private const GROUP_PAGES_ALIAS = '{st:nbpg}';

    /** @return array{bytes:string, page_count:int} */
    public function render(DocumentLayout $layout): array
    {
        $s = $layout->snapshot;
        $fontKey = FontRegistry::pdfKey($s['font_family']);

        $mpdf = new Mpdf([
            // A language code as mode sets the document language (/Lang) without changing fonts.
            'mode' => $layout->languageVariant,
            'format' => $s['page_size'] === 'Letter' ? 'Letter' : 'A4',
            'orientation' => 'P',
            'tempDir' => self::tempDir(),
            'fontDir' => FontRegistry::mpdfFontDirs(),
            'fontdata' => FontRegistry::mpdfFontData(),
            'default_font' => $fontKey,
            'default_font_size' => (float) $s['font_size'],
            // Characters missing from the main font are drawn with DejaVu instead of disappearing.
            'useSubstitutions' => true,
            'backupSubsFont' => [FontRegistry::FALLBACK_KEY],
            'backupSIPFont' => '',
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'margin_top' => (float) $s['margin_top_mm'],
            'margin_right' => (float) $s['margin_right_mm'],
            'margin_bottom' => (float) $s['margin_bottom_mm'],
            'margin_left' => (float) $s['margin_left_mm'],
            'margin_header' => $layout->marginalDistance('header'),
            'margin_footer' => $layout->marginalDistance('footer'),
            // Like Word, push the body down rather than overlap when a header/footer outgrows its margin.
            'setAutoTopMargin' => 'stretch',
            'setAutoBottomMargin' => 'stretch',
            // Word adds "space after" and "space before"; so do we.
            'collapseBlockMargins' => false,
            'aliasNbPg' => self::TOTAL_PAGES_ALIAS,
            'aliasNbPgGp' => self::GROUP_PAGES_ALIAS,
            'exposeVersion' => false,
            'debug' => false,
        ]);

        $mpdf->SetTitle($layout->title);
        if ($author = $layout->visibleApplicantName()) {
            $mpdf->SetAuthor($author);
        }

        if ($layout->header !== []) {
            $mpdf->SetHTMLHeader($this->marginalHtml($layout, $layout->header, $fontKey));
        }
        if ($layout->footer !== []) {
            $mpdf->SetHTMLFooter($this->marginalHtml($layout, $layout->footer, $fontKey));
        }

        $mpdf->WriteHTML($this->bodyHtml($layout, $fontKey));
        $bytes = $mpdf->Output('', Destination::STRING_RETURN);

        return ['bytes' => $bytes, 'page_count' => count($mpdf->pages)];
    }

    private function bodyHtml(DocumentLayout $layout, string $fontKey): string
    {
        $family = $layout->snapshot['font_family'];
        $html = '<html><body style="font-family: '.$fontKey.'; color: #000000;">';

        foreach ($layout->items as $index => $item) {
            $f = $layout->format($index);
            $style = [
                'font-family: '.$fontKey,
                sprintf('font-size: %.2Fpt', $f['size']),
                // Word's "multiple" spacing: n × the font's natural line height.
                sprintf('line-height: %.3Fpt', $f['size'] * $f['line'] * FontRegistry::lineFactor($family)),
                'font-weight: '.($f['bold'] ? 'bold' : 'normal'),
                'font-style: '.($f['italic'] ? 'italic' : 'normal'),
                'text-align: '.$f['align'],
                sprintf('text-indent: %.2Fmm', $f['indent_mm']),
                sprintf('margin: %.2Fpt 0 %.2Fpt 0', $f['before'], $f['after']),
                'padding: 0',
            ];
            if ($f['keep_next']) {
                $style[] = 'page-break-after: avoid';
            }

            // {PAGENO} cannot be renamed; a word joiner keeps it literal in body text.
            $text = str_replace('{PAGENO}', '{&#8288;PAGENO}', $this->text($item['text']));
            $html .= '<p style="'.implode('; ', $style).'">'.$text.'</p>';
        }

        return $html.'</body></html>';
    }

    /** @param list<array{text:string,align:string,page_number:bool}> $lines */
    private function marginalHtml(DocumentLayout $layout, array $lines, string $fontKey): string
    {
        $size = $layout->marginalFontSize();
        $lineHeight = $size * FontRegistry::lineFactor($layout->snapshot['font_family']);
        $html = '';

        foreach ($lines as $line) {
            $text = $this->text($line['text']);
            if ($line['page_number']) {
                $text = str_replace(['{PAGE}', '{NUMPAGES}'], ['{PAGENO}', self::TOTAL_PAGES_ALIAS], $text);
            }
            $html .= sprintf(
                '<div style="font-family: %s; font-size: %.2Fpt; line-height: %.3Fpt; text-align: %s; color: #000000; margin: 0; padding: 0;">%s</div>',
                $fontKey, $size, $lineHeight, $line['align'], $text,
            );
        }

        return $html;
    }

    /** Escaped text; soft line breaks inside a block become <br>. */
    private function text(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /** Private scratch directory for mPDF's font cache and temporary files. */
    public static function tempDir(): string
    {
        $directory = storage_path('app/mpdf');
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        return $directory;
    }
}
