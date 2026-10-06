<?php

namespace App\Domain\Documents\Qa;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Optional DOCX → PDF conversion with headless LibreOffice, used to prove a
 * generated DOCX opens and lays out in a real word processor, and to produce
 * a faithful PDF from an administrator's final DOCX. Each run uses its own
 * throw-away profile so parallel workers never share LibreOffice state.
 */
class LibreOffice
{
    public function enabled(): bool
    {
        return (bool) config('statementra.documents.verify_docx_with_libreoffice') && $this->binary() !== null;
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /** PDF bytes, or null when conversion is impossible or failed. */
    public function docxToPdf(string $docxBytes, int $timeoutSeconds = 120): ?string
    {
        $binary = $this->binary();
        if ($binary === null) {
            return null;
        }

        $work = storage_path('app/tmp/lo-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($work.'/profile', 0700);
        file_put_contents($work.'/document.docx', $docxBytes);

        try {
            $process = new Process([
                $binary, '-env:UserInstallation=file://'.$work.'/profile', '--headless', '--norestore',
                '--nolockcheck', '--convert-to', 'pdf', '--outdir', $work, $work.'/document.docx',
            ], $work, ['HOME' => $work]);
            $process->setTimeout($timeoutSeconds);
            $process->run();

            $pdf = $work.'/document.pdf';

            return $process->isSuccessful() && is_file($pdf) && filesize($pdf) > 0 ? (string) file_get_contents($pdf) : null;
        } catch (Throwable) {
            return null;
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function binary(): ?string
    {
        $configured = (string) config('statementra.documents.libreoffice_binary', 'soffice');
        if ($configured === '') {
            return null;
        }
        if (str_contains($configured, '/')) {
            return is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }
}
