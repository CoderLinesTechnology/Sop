<?php

namespace App\Domain\Documents\Qa;

use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads rendered or uploaded PDFs: structure, page sizes, metadata, fonts and
 * per-page text. Uses poppler (pdftotext / pdffonts) when available, with
 * smalot/pdfparser as the pure-PHP fallback.
 */
class PdfInspector
{
    /**
     * @return array{ok:bool, error:?string, pages:int, page_sizes:list<array{0:float,1:float}>, info:array<string,string>}
     */
    public function inspect(string $bytes): array
    {
        $result = ['ok' => false, 'error' => null, 'pages' => 0, 'page_sizes' => [], 'info' => []];

        if (! str_starts_with($bytes, '%PDF-')) {
            return ['error' => 'The file does not start with a PDF header.'] + $result;
        }
        if (! str_contains(substr($bytes, -2048), '%%EOF')) {
            return ['error' => 'The PDF is incomplete (no end-of-file marker).'] + $result;
        }

        try {
            $pdf = (new Parser)->parseContent($bytes);
            $pages = $pdf->getPages();
            $sizes = [];
            foreach ($pages as $page) {
                $box = (array) ($page->getDetails()['MediaBox'] ?? []);
                $sizes[] = count($box) === 4 ? [abs((float) $box[2] - (float) $box[0]), abs((float) $box[3] - (float) $box[1])] : [0.0, 0.0];
            }
            $info = array_map(fn ($value) => is_array($value) ? implode(', ', $value) : (string) $value, array_filter(
                $pdf->getDetails(),
                fn ($value, $key) => in_array($key, ['Title', 'Author', 'Subject', 'Keywords', 'Creator', 'Producer'], true),
                ARRAY_FILTER_USE_BOTH,
            ));
        } catch (Throwable $e) {
            return ['error' => 'The PDF could not be parsed: '.mb_substr($e->getMessage(), 0, 200)] + $result;
        }

        if ($pages === []) {
            return ['error' => 'The PDF has no pages.'] + $result;
        }

        return ['ok' => true, 'error' => null, 'pages' => count($pages), 'page_sizes' => $sizes, 'info' => $info];
    }

    /**
     * Text of each page in reading order, or null if no text could be read.
     *
     * @return list<string>|null
     */
    public function pageTexts(string $path): ?array
    {
        $binary = $this->popplerBinary('pdftotext');
        if ($binary !== null) {
            $process = new Process([$binary, '-enc', 'UTF-8', '-eol', 'unix', $path, '-']);
            $process->setTimeout(60);
            $process->run();
            if ($process->isSuccessful()) {
                $pages = explode("\f", $process->getOutput());
                array_pop($pages); // pdftotext ends every page, including the last, with a form feed

                return $pages;
            }
        }

        try {
            return array_values(array_map(fn ($page) => (string) $page->getText(), (new Parser)->parseFile($path)->getPages()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fonts used by the PDF and whether each is embedded, or null when it
     * cannot be determined.
     *
     * @return list<array{name:string, type:string, embedded:bool}>|null
     */
    public function fonts(string $path, string $bytes): ?array
    {
        $binary = $this->popplerBinary('pdffonts');
        if ($binary !== null) {
            $process = new Process([$binary, $path]);
            $process->setTimeout(30);
            $process->run();
            if ($process->isSuccessful()) {
                $fonts = [];
                foreach (array_slice(explode("\n", trim($process->getOutput())), 2) as $line) {
                    if (preg_match('/^(\S+)\s+(.+?)\s+(\S+)\s+(yes|no)\s+(yes|no)\s+(yes|no)\s+\d+\s+\d+\s*$/', $line, $m) === 1) {
                        $fonts[] = ['name' => $m[1], 'type' => $m[2], 'embedded' => $m[4] === 'yes'];
                    }
                }

                return $fonts;
            }
        }

        // Fallback for uncompressed object dictionaries (as mPDF writes them).
        $descriptors = preg_match_all('#/Type\s*/FontDescriptor#', $bytes);
        if ($descriptors === 0 || $descriptors === false) {
            return null;
        }
        $embedded = preg_match_all('#/FontFile[23]?\s+\d+\s+\d+\s+R#', $bytes);

        return array_map(fn (int $i) => ['name' => 'font'.($i + 1), 'type' => 'unknown', 'embedded' => $i < $embedded], range(0, $descriptors - 1));
    }

    /** poppler tools live next to pdftotext. */
    private function popplerBinary(string $tool): ?string
    {
        $pdftotext = (string) config('statementra.documents.pdftotext_binary');
        if ($pdftotext === '') {
            return null;
        }
        $binary = $tool === 'pdftotext' ? $pdftotext : dirname($pdftotext).'/'.$tool;

        return is_file($binary) && is_executable($binary) ? $binary : null;
    }
}
