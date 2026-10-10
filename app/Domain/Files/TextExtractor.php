<?php

namespace App\Domain\Files;

use App\Enums\ExtractionStatus;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Extracts plain text from uploads so the AI pipeline can build the
 * applicant profile. Images and scanned PDFs (little or no text layer) are
 * marked NeedsVision and are read by the vision-capable model instead.
 *
 * Extracted text is untrusted data: it is stored encrypted and is always
 * passed to models inside delimited "untrusted" blocks.
 */
final class TextExtractor
{
    public const MAX_CHARS = 60000;

    private const MIN_PDF_TEXT_CHARS = 200;

    public function __construct(private readonly FileVault $vault) {}

    public function extract(UploadedFile $file): void
    {
        if ($file->isImage()) {
            $file->forceFill(['extraction_status' => ExtractionStatus::NeedsVision, 'extracted_text' => null, 'extracted_chars' => 0])->save();

            return;
        }

        try {
            [$text, $pages] = match ($file->extension) {
                'pdf' => $this->fromPdf($file),
                'docx' => [$this->fromDocx($this->vault->get($file->path, $file->disk, $file->is_encrypted)), null],
                'txt' => [$this->vault->get($file->path, $file->disk, $file->is_encrypted), null],
                default => ['', null],
            };
        } catch (Throwable $e) {
            Log::warning('Text extraction failed', ['file' => $file->uuid, 'error' => $e->getMessage()]);
            $file->forceFill(['extraction_status' => $file->extension === 'pdf' ? ExtractionStatus::NeedsVision : ExtractionStatus::Failed])->save();

            return;
        }

        $text = $this->normalize($text);
        $status = ExtractionStatus::Extracted;
        if ($file->extension === 'pdf' && mb_strlen($text) < self::MIN_PDF_TEXT_CHARS) {
            $status = ExtractionStatus::NeedsVision; // scanned document
        }

        $file->forceFill([
            'extraction_status' => $status,
            'extracted_text' => $text !== '' ? mb_substr($text, 0, self::MAX_CHARS) : null,
            'extracted_chars' => mb_strlen($text),
            'page_count' => $pages,
        ])->save();
    }

    /**
     * Plain, normalised text from raw file contents (pdf, docx or txt) that
     * are not stored as an upload, e.g. an administrator's writing sample.
     * Returns an empty string when the file has no readable text.
     */
    public function textFromContents(string $contents, string $extension): string
    {
        try {
            $text = match ($extension) {
                'pdf' => $this->pdfTextFromContents($contents),
                'docx' => $this->fromDocx($contents),
                'txt' => $contents,
                default => '',
            };
        } catch (Throwable $e) {
            // Encrypted or damaged files: report "no text" rather than failing.
            Log::warning('Text extraction failed', ['extension' => $extension, 'error' => $e->getMessage()]);

            return '';
        }

        return $this->normalize($text);
    }

    /** @return array{0:string,1:?int} */
    private function fromPdf(UploadedFile $file): array
    {
        $temp = $this->vault->toTempFile($file->path, 'pdf', $file->disk, $file->is_encrypted);

        try {
            return $this->pdfFromPath($temp);
        } finally {
            @unlink($temp);
        }
    }

    private function pdfTextFromContents(string $contents): string
    {
        $directory = storage_path('app/tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $temp = $directory.'/'.bin2hex(random_bytes(12)).'.pdf';
        file_put_contents($temp, $contents);
        chmod($temp, 0600);

        try {
            return $this->pdfFromPath($temp)[0];
        } finally {
            @unlink($temp);
        }
    }

    /** @return array{0:string,1:?int} */
    private function pdfFromPath(string $temp): array
    {
        $binary = (string) config('statementra.documents.pdftotext_binary');
        if ($binary !== '' && is_executable($binary)) {
            $output = $this->pdftotext($binary, $temp);
            if ($output !== null) {
                // Reading order handles columns well but joins every line ending in "-"
                // with the next line ("2019-" + "2023)" becomes "20192023)"). Raw mode
                // keeps those hyphens; use it to put back the ones that were real.
                $raw = $this->pdftotext($binary, $temp, raw: true);
                if ($raw !== null) {
                    $output = self::restoreLineEndHyphens($output, $raw);
                }

                return [str_replace("\f", "\n\n", $output), substr_count($output, "\f") ?: null];
            }
        }

        $pdf = (new PdfParser)->parseFile($temp);

        return [$pdf->getText(), count($pdf->getPages())];
    }

    private function pdftotext(string $binary, string $path, bool $raw = false): ?string
    {
        $process = new Process([$binary, ...($raw ? ['-raw'] : []), '-enc', 'UTF-8', '-l', '40', $path, '-']);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Re-insert hyphens that pdftotext removed at line ends when they cannot
     * have been word breaks: a digit before the hyphen ("2019-2023") or a digit
     * or capital letter after it ("COVID-19", "Anglo-French").
     */
    public static function restoreLineEndHyphens(string $readingOrder, string $raw): string
    {
        preg_match_all('/(\S+)-\R(\S+)/u', $raw, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $left, $right]) {
            if (preg_match('/\p{N}$/u', $left) || preg_match('/^[\p{Lu}\p{N}]/u', $right)) {
                $readingOrder = str_replace($left.$right, $left.'-'.$right, $readingOrder);
            }
        }

        return $readingOrder;
    }

    public function fromDocx(string $contents): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($temp, $contents);

        try {
            $zip = new ZipArchive;
            if ($zip->open($temp, ZipArchive::RDONLY) !== true) {
                return '';
            }
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();
        } finally {
            @unlink($temp);
        }

        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return '';
        }

        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return '';
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];
        foreach ($xpath->query('//w:body//w:p') as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) as $node) {
                $text .= match ($node->localName) {
                    'tab' => "\t",
                    'br' => "\n",
                    default => $node->textContent,
                };
            }
            $paragraphs[] = $text;
        }

        return implode("\n", $paragraphs);
    }

    private function normalize(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text; // strip control characters
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
