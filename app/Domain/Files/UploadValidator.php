<?php

namespace App\Domain\Files;

use App\Support\Settings;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use ZipArchive;

/**
 * Validates and sanitises customer uploads before anything is stored.
 *
 * Checks extension and size, then the real content type from magic bytes
 * (a renamed executable is rejected even with a .pdf name). DOCX files are
 * inspected for zip bombs, macros and DTDs; images are re-encoded, which
 * strips EXIF metadata (e.g. GPS location) and defuses polyglot files.
 */
final class UploadValidator
{
    private const MAX_DOCX_ENTRIES = 2000;

    private const MAX_DOCX_UNCOMPRESSED = 60 * 1024 * 1024;

    private const MAX_IMAGE_EDGE = 3000;

    /**
     * @param  list<string>  $allowedExtensions
     * @return array{contents:string, extension:string, mime:string, name:string}
     *
     * @throws UploadRejected
     */
    public function validate(HttpUploadedFile $file, array $allowedExtensions): array
    {
        if (! $file->isValid()) {
            throw new UploadRejected('The upload did not complete. Please try again.', 'incomplete');
        }

        $maxBytes = Settings::maxUploadBytes();
        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new UploadRejected('This file is empty.', 'empty');
        }
        if ($size > $maxBytes) {
            throw new UploadRejected('This file is larger than '.intdiv($maxBytes, 1048576).' MB.', 'too_large');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $allowed = array_map(fn ($e) => $e === 'jpeg' ? 'jpg' : $e, $allowedExtensions);
        if (! in_array($extension, $allowed, true)) {
            throw new UploadRejected('Please upload a '.$this->humanList($allowed).' file.', 'extension');
        }

        $contents = (string) file_get_contents($file->getRealPath());
        if (strlen($contents) !== $size) {
            throw new UploadRejected('The upload did not complete. Please try again.', 'size_mismatch');
        }

        $detected = $this->sniff($contents);
        $expected = ['pdf' => 'pdf', 'docx' => 'zip', 'jpg' => 'jpeg', 'png' => 'png', 'txt' => 'text'][$extension];
        if ($detected !== $expected) {
            throw new UploadRejected("This file's contents don't match its .{$extension} extension.", 'content_mismatch');
        }

        [$contents, $mime] = match ($extension) {
            'pdf' => [$this->checkPdf($contents), 'application/pdf'],
            'docx' => [$this->checkDocx($contents, $file->getRealPath()), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'txt' => [$this->checkText($contents), 'text/plain'],
            'jpg', 'png' => $this->reencodeImage($contents, $extension),
        };

        return [
            'contents' => $contents,
            'extension' => $extension,
            'mime' => $mime,
            'name' => $this->safeName((string) $file->getClientOriginalName(), $extension),
        ];
    }

    /** Detect the real type from magic bytes. */
    public function sniff(string $contents): string
    {
        return match (true) {
            str_starts_with($contents, '%PDF-') => 'pdf',
            str_starts_with($contents, "PK\x03\x04") => 'zip',
            str_starts_with($contents, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($contents, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($contents, 'MZ'), str_starts_with($contents, "\x7FELF"), str_starts_with($contents, '#!') => 'executable',
            $this->looksLikeText($contents) => 'text',
            default => 'unknown',
        };
    }

    private function looksLikeText(string $contents): bool
    {
        $sample = substr($contents, 0, 8192);
        if (str_contains($sample, "\0")) {
            return false;
        }

        // Strip a UTF-8 BOM and accept valid UTF-8 or Latin-1 style text.
        $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample) ?? $sample;

        return mb_check_encoding($sample, 'UTF-8') || preg_match('/^[\x09\x0A\x0D\x20-\x7E\x80-\xFF]*$/', $sample) === 1;
    }

    private function checkPdf(string $contents): string
    {
        if (! str_contains(substr($contents, -2048), '%%EOF')) {
            throw new UploadRejected('This PDF appears to be damaged or incomplete.', 'pdf_truncated');
        }

        return $contents;
    }

    private function checkDocx(string $contents, string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new UploadRejected('This Word document could not be opened.', 'docx_unreadable');
        }

        try {
            if ($zip->numFiles > self::MAX_DOCX_ENTRIES) {
                throw new UploadRejected('This Word document is too complex to process.', 'docx_entries');
            }

            $uncompressed = 0;
            $hasDocument = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                $uncompressed += (int) ($stat['size'] ?? 0);

                if ($uncompressed > self::MAX_DOCX_UNCOMPRESSED) {
                    throw new UploadRejected('This Word document is too large to process.', 'docx_bomb');
                }
                if (str_contains($name, '..') || str_starts_with($name, '/')) {
                    throw new UploadRejected('This Word document is not valid.', 'docx_path');
                }
                if (str_ends_with(strtolower($name), 'vbaproject.bin')) {
                    throw new UploadRejected('Documents containing macros are not accepted. Please save as a regular .docx.', 'docx_macro');
                }
                $hasDocument = $hasDocument || $name === 'word/document.xml';
            }

            if (! $hasDocument) {
                throw new UploadRejected('This file is not a valid Word (.docx) document.', 'docx_invalid');
            }

            $xml = (string) $zip->getFromName('word/document.xml');
            if (stripos(substr($xml, 0, 4096), '<!DOCTYPE') !== false) {
                throw new UploadRejected('This Word document is not valid.', 'docx_dtd');
            }
        } finally {
            $zip->close();
        }

        return $contents;
    }

    private function checkText(string $contents): string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $contents);
    }

    /** @return array{0:string,1:string} */
    private function reencodeImage(string $contents, string $extension): array
    {
        $info = @getimagesizefromstring($contents);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 50_000_000) {
            throw new UploadRejected('This image could not be read.', 'image_invalid');
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new UploadRejected('This image could not be read.', 'image_invalid');
        }

        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, self::MAX_IMAGE_EDGE / max($width, $height));
        if ($scale < 1) {
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        if ($extension === 'png') {
            imagepng($image, null, 6);
            $mime = 'image/png';
        } else {
            imagejpeg($image, null, 88);
            $mime = 'image/jpeg';
        }
        $clean = (string) ob_get_clean();
        imagedestroy($image);

        return [$clean, $mime];
    }

    private function safeName(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN ._()-]+/u', '', $base) ?? '';
        $base = trim(mb_substr(preg_replace('/\s+/', ' ', $base) ?? '', 0, 120), ' .');

        return ($base !== '' ? $base : 'document').'.'.$extension;
    }

    /** @param list<string> $extensions */
    private function humanList(array $extensions): string
    {
        $labels = array_map('strtoupper', $extensions);
        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }
        $last = array_pop($labels);

        return implode(', ', $labels).' or '.$last;
    }
}
