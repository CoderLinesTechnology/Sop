<?php

namespace App\Domain\Files;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Application-level encryption for files at rest (AES-256-GCM).
 *
 * Format: "STF1" | key-id length (1 byte) | key id | IV (12 bytes) | tag (16 bytes) | ciphertext.
 * The key id lets keys be rotated: new files use the current key while older
 * files remain readable with keys listed in FILE_ENCRYPTION_PREVIOUS_KEYS.
 */
final class FileEncryptor
{
    private const MAGIC = 'STF1';

    private const CIPHER = 'aes-256-gcm';

    public function encrypt(string $plaintext): string
    {
        [$keyId, $key] = $this->currentKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC.$keyId, 16);

        if ($ciphertext === false) {
            throw new RuntimeException('File encryption failed.');
        }

        return self::MAGIC.chr(strlen($keyId)).$keyId.$iv.$tag.$ciphertext;
    }

    public function decrypt(string $payload): string
    {
        if (! str_starts_with($payload, self::MAGIC)) {
            throw new RuntimeException('Not an encrypted Statementra file.');
        }

        $offset = strlen(self::MAGIC);
        $keyIdLength = ord($payload[$offset]);
        $offset++;
        $keyId = substr($payload, $offset, $keyIdLength);
        $offset += $keyIdLength;
        $iv = substr($payload, $offset, 12);
        $offset += 12;
        $tag = substr($payload, $offset, 16);
        $offset += 16;
        $ciphertext = substr($payload, $offset);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->keyFor($keyId), OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC.$keyId);

        if ($plaintext === false) {
            throw new RuntimeException('File decryption failed (wrong key or tampered file).');
        }

        return $plaintext;
    }

    public function currentKeyId(): string
    {
        return $this->currentKey()[0];
    }

    /** @return array{0:string,1:string} */
    private function currentKey(): array
    {
        $id = (string) config('statementra.storage.encryption_key_id', 'k1');

        return [$id, $this->keyFor($id)];
    }

    private function keyFor(string $keyId): string
    {
        $currentId = (string) config('statementra.storage.encryption_key_id', 'k1');
        $configured = config('statementra.storage.encryption_key');

        if ($keyId === $currentId && filled($configured)) {
            return $this->decodeKey((string) $configured);
        }

        foreach (array_filter(explode(',', (string) config('statementra.storage.previous_keys'))) as $entry) {
            [$id, $value] = array_pad(explode(':', trim($entry), 2), 2, null);
            if ($id === $keyId && $value) {
                return $this->decodeKey($value);
            }
        }

        if ($keyId === $currentId) {
            // No dedicated key configured: derive one from APP_KEY (still unique per install).
            if (app()->isProduction()) {
                Log::warning('FILE_ENCRYPTION_KEY is not set; deriving the file key from APP_KEY.');
            }

            return hash_hkdf('sha256', (string) config('app.key'), 32, 'statementra-file-encryption');
        }

        throw new RuntimeException("Unknown file encryption key id [{$keyId}].");
    }

    private function decodeKey(string $value): string
    {
        $value = str_starts_with($value, 'base64:') ? substr($value, 7) : $value;
        $key = base64_decode($value, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('FILE_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        return $key;
    }
}
