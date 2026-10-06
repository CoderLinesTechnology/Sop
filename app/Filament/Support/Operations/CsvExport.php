<?php

namespace App\Filament\Support\Operations;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams CSV exports row by row (no temporary files, constant memory) and
 * neutralises spreadsheet formula injection in every cell.
 */
final class CsvExport
{
    /**
     * @param  list<string>  $headings
     * @param  Closure(): iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headings, Closure $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet apps detect the encoding
            fputcsv($out, $headings, escape: '');

            foreach ($rows() as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Converts a value to a CSV-safe string (values starting with =, +, -, @ are prefixed with a quote). */
    public static function cell(mixed $value): string
    {
        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
