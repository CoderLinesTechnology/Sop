<?php

namespace App\Domain\Ai\Samples;

use App\Domain\Ai\Prompts\UntrustedData;
use App\Domain\Documents\WordCounter;
use App\Domain\Files\MalwareScanner;
use App\Domain\Files\TextExtractor;
use App\Domain\Files\UploadRejected;
use App\Domain\Files\UploadValidator;
use App\Support\SecurityLog;
use Illuminate\Http\UploadedFile as HttpUploadedFile;

/**
 * Turns an administrator's upload (PDF, DOCX or TXT) or pasted text into the
 * text of a writing sample. Uploads pass the same validation and malware scan
 * as customer files; only the extracted text is returned, the file itself is
 * never stored. Email addresses, phone numbers and links are redacted so
 * contact details cannot travel into prompts.
 */
final class WritingSampleImporter
{
    public const EXTENSIONS = ['pdf', 'docx', 'txt'];

    /** Text kept per sample (well above a long SOP; prompts use a shorter excerpt). */
    public const MAX_CHARS = 30000;

    public const MIN_WORDS = 80;

    /** Domain endings recognised in links written without "http" or "www". */
    private const LINK_ENDINGS = 'com|org|net|io|dev|me|co|edu|gov|info|app|ai|ly|uk|gh|ng|ke|za|us|ca|de|fr|in';

    private const SUSPICIOUS_REJECTIONS = ['content_mismatch', 'docx_macro', 'docx_bomb', 'docx_dtd', 'docx_path'];

    public function __construct(
        private readonly UploadValidator $validator,
        private readonly MalwareScanner $scanner,
        private readonly TextExtractor $extractor,
    ) {}

    /**
     * @return array{content:string, word_count:int, redactions:array<string,int>}
     *
     * @throws UploadRejected
     */
    public function fromUpload(HttpUploadedFile $file): array
    {
        try {
            $clean = $this->validator->validate($file, self::EXTENSIONS);
        } catch (UploadRejected $e) {
            if (in_array($e->reason, self::SUSPICIOUS_REJECTIONS, true)) {
                SecurityLog::record('upload_rejected', 'medium', ['reason' => $e->reason, 'context' => 'writing_sample', 'name' => mb_substr((string) $file->getClientOriginalName(), 0, 120)]);
            }
            throw $e;
        }

        $scan = $this->scanner->scan($clean['contents']);
        if ($scan['status'] === 'infected') {
            SecurityLog::record('malware_upload', 'high', ['signature' => $scan['result'], 'name' => $clean['name'], 'context' => 'writing_sample']);
            throw new UploadRejected('This file was blocked by the security scan.', 'infected');
        }
        if ($scan['status'] === 'error' && config('statementra.scanning.fail_closed')) {
            throw new UploadRejected('The file could not be scanned right now. Please try again in a moment.', 'scan_unavailable');
        }

        $text = $this->extractor->textFromContents($clean['contents'], $clean['extension']);
        if ($text === '') {
            throw new UploadRejected('No text could be read from this file (it may be scanned, password-protected or damaged). Paste the text instead.', 'no_text');
        }

        return $this->fromText($text);
    }

    /**
     * @return array{content:string, word_count:int, redactions:array<string,int>}
     *
     * @throws UploadRejected
     */
    public function fromText(string $text): array
    {
        [$text, $redactions] = self::redact(self::truncate(self::normalize($text)));

        $words = WordCounter::words($text);
        if ($words < self::MIN_WORDS) {
            throw new UploadRejected("This sample is too short ({$words} words). Use a complete document of at least ".self::MIN_WORDS.' words.', 'too_short');
        }

        // Samples are sent with many orders: text that reads like instructions to an AI
        // would raise a security alert on every one of them. Have it removed up front.
        if (UntrustedData::looksLikeInjection($text)) {
            throw new UploadRejected('This text contains phrases that read like instructions to an AI (for example "ignore previous instructions"). Remove them and try again.', 'instructions');
        }

        return ['content' => $text, 'word_count' => $words, 'redactions' => $redactions];
    }

    /**
     * Replace email addresses, links and phone numbers with neutral markers.
     *
     * @return array{0:string, 1:array<string,int>} the text and the number of replacements per kind
     *
     * @throws UploadRejected when a pattern cannot run (never return the text unredacted)
     */
    public static function redact(string $text): array
    {
        $counts = [];

        $replace = function (string $pattern, string $marker, string $kind, ?callable $accept = null) use (&$text, &$counts): void {
            $result = preg_replace_callback($pattern, function (array $m) use ($marker, $kind, $accept, &$counts) {
                if ($accept && ! $accept($m[0])) {
                    return $m[0];
                }
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;

                return $marker;
            }, $text);

            if ($result === null) {
                throw new UploadRejected('This text could not be checked for contact details. Remove unusual characters and try again.', 'redaction_failed');
            }
            $text = $result;
        };

        $replace('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}{2,}/u', '[email]', 'emails');
        // Links end before trailing punctuation ("see https://x.example/a." keeps its full stop).
        $replace('~\b(?:https?://|www\.)[^\s<>()]*[^\s<>().,;:!?\'"]~iu', '[link]', 'links');
        // Bare profile links in CVs ("linkedin.com/in/someone", "github.com/someone"). Only common
        // lower-case domain endings, so "B.Sc/M.Sc", "Node.js/React" and "ASP.NET/C#" stay as written.
        $replace('~\b[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.(?:'.self::LINK_ENDINGS.')/(?:[^\s<>()]*[^\s<>().,;:!?\'"])?~u', '[link]', 'links');
        // International numbers, local numbers with a trunk prefix, and 3-3-4 groupings,
        // within one line (a number never continues onto the next line).
        $replace(
            '/(?<![\p{L}\p{N}])(?:(?:\+|00)\d[\d \t().-]{6,20}\d|\(?0\d[\d \t().-]{6,20}\d|\(?\d{3}\)?[ \t.-]\d{3}[ \t.-]\d{4})(?![\p{L}\p{N}])/u',
            '[phone]',
            'phones',
            function (string $match): bool {
                $digits = strlen(preg_replace('/\D+/', '', $match) ?? '');

                // Date ranges such as "05.2019 - 06.2021" are not phone numbers.
                return $digits >= 9 && $digits <= 15 && preg_match_all('/(?<!\d)(?:19|20)\d{2}(?!\d)/', $match) < 2;
            },
        );

        return [$text, $counts];
    }

    /** Cut to MAX_CHARS at a word boundary, so no half email address or number is left at the end. */
    private static function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_CHARS);

        return rtrim(preg_replace('/\S+$/u', '', $cut) ?? $cut);
    }

    private static function normalize(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text; // control / format characters
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
