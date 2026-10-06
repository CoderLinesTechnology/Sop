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

    /** @return array{0:string,1:?int} */
    private function fromPdf(UploadedFile $file): array
    {
        $temp = $this->vault->toTempFile($file->path, 'pdf', $file->disk, $file->is_encrypted);

        try {
            $binary = (string) config('statementra.documents.pdftotext_binary');
            if ($binary !== '' && is_executable($binary)) {
                $process = new Process([$binary, '-enc', 'UTF-8', '-l', '40', $temp, '-']);
                $process->setTimeout(30);
                $process->run();
                if ($process->isSuccessful()) {
                    $output = $process->getOutput();

                    return [str_replace("\f", "\n\n", $output), substr_count($output, "\f") ?: null];
                }
            }

            $pdf = (new PdfParser)->parseFile($temp);

            return [$pdf->getText(), count($pdf->getPages())];
        } finally {
            @unlink($temp);
        }
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
