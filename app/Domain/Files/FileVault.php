<?php

namespace App\Domain\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Private storage for customer uploads and generated documents.
 *
 * Paths are random (no names, ids or emails), contents are encrypted with
 * FileEncryptor before they reach the disk, and nothing in this vault is ever
 * exposed through a public URL: files are streamed by authorised routes or
 * attached to emails.
 */
final class FileVault
{
    public function __construct(private readonly FileEncryptor $encryptor) {}

    public function diskName(): string
    {
        return (string) config('statementra.storage.private_disk', 'private');
    }

    public function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? $this->diskName());
    }

    /**
     * @return array{disk:string,path:string,size:int,sha256:string,encrypted:bool,key_id:string}
     */
    public function put(string $directory, string $contents, string $extension): array
    {
        $directory = trim($directory, '/');
        $path = sprintf('%s/%s/%s.%s.enc', $directory, now()->format('Y/m'), bin2hex(random_bytes(16)), preg_replace('/[^a-z0-9]/', '', strtolower($extension)));

        $payload = $this->encryptor->encrypt($contents);
        if (! $this->disk()->put($path, $payload)) {
            throw new RuntimeException('Could not write file to private storage.');
        }

        return [
            'disk' => $this->diskName(),
            'path' => $path,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'encrypted' => true,
            'key_id' => $this->encryptor->currentKeyId(),
        ];
    }

    public function get(string $path, ?string $disk = null, bool $encrypted = true): string
    {
        $contents = $this->disk($disk)->get($path);
        if ($contents === null) {
            throw new RuntimeException('File not found in private storage.');
        }

        return $encrypted ? $this->encryptor->decrypt($contents) : $contents;
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        return $this->disk($disk)->exists($path);
    }

    public function delete(?string $path, ?string $disk = null): void
    {
        if ($path) {
            $this->disk($disk)->delete($path);
        }
    }

    /**
     * Decrypt into a short-lived private temp file (for tools such as
     * pdftotext that need a path). The caller must unlink() it.
     */
    public function toTempFile(string $path, string $extension, ?string $disk = null, bool $encrypted = true): string
    {
        $directory = storage_path('app/tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $temp = $directory.'/'.bin2hex(random_bytes(12)).'.'.preg_replace('/[^a-z0-9]/', '', strtolower($extension));
        file_put_contents($temp, $this->get($path, $disk, $encrypted));
        chmod($temp, 0600);

        return $temp;
    }
}
