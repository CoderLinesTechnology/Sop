<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Responsive, optimised images.
 *
 * Bundled images (public/images/...) ship with pre-generated WebP variants
 * listed in public/images/manifest.json. Images uploaded by administrators
 * (public disk) get WebP variants generated once with GD and cached. Every
 * image is rendered with intrinsic width/height to avoid layout shift.
 */
final class ResponsiveImage
{
    public const WIDTHS = [480, 768, 1080, 1440, 1920];

    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /**
     * @return array{src:string, fallback:string, srcset:string, width:int, height:int}|null
     */
    public static function resolve(?string $path): ?array
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = ltrim($path, '/');

        return str_starts_with($path, 'images/') ? self::bundled($path) : self::uploaded($path);
    }

    private static function bundled(string $path): ?array
    {
        self::$manifest ??= is_file(public_path('images/manifest.json'))
            ? (array) json_decode((string) file_get_contents(public_path('images/manifest.json')), true)
            : [];

        $entry = self::$manifest[$path] ?? null;
        if (! $entry) {
            if (! is_file(public_path($path))) {
                return null;
            }
            [$width, $height] = @getimagesize(public_path($path)) ?: [1200, 800];

            return ['src' => asset($path), 'fallback' => asset($path), 'srcset' => '', 'width' => (int) $width, 'height' => (int) $height];
        }

        return self::describe($entry, fn (string $p) => asset($p), asset($path));
    }

    private static function uploaded(string $path): ?array
    {
        $diskName = (string) config('statementra.storage.public_disk', 'public');

        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)) {
                return null;
            }

            $entry = Cache::rememberForever('responsive-image:'.sha1($diskName.'|'.$path.'|'.$disk->lastModified($path)), function () use ($disk, $path) {
                return self::generate($disk, $path);
            });

            return self::describe($entry, fn (string $p) => $disk->url($p), $disk->url($path));
        } catch (Throwable $e) {
            Log::warning('Responsive image failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private static function generate($disk, string $path): array
    {
        $image = @imagecreatefromstring((string) $disk->get($path));
        if ($image === false) {
            return ['width' => 1200, 'height' => 800, 'variants' => []];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $path);
        $variants = [];

        foreach ([...array_filter(self::WIDTHS, fn ($w) => $w < $width), $width] as $target) {
            $resized = $target === $width ? $image : imagescale($image, $target, (int) round($height * $target / $width), IMG_BICUBIC);
            imagepalettetotruecolor($resized);
            imagealphablending($resized, true);
            imagesavealpha($resized, true);

            ob_start();
            imagewebp($resized, null, 80);
            $variantPath = $target === $width ? "{$base}.webp" : "{$base}-{$target}.webp";
            $disk->put($variantPath, (string) ob_get_clean(), ['visibility' => 'public']);
            $variants[] = ['width' => $target, 'path' => $variantPath];

            if ($resized !== $image) {
                imagedestroy($resized);
            }
        }

        imagedestroy($image);

        return ['width' => $width, 'height' => $height, 'variants' => $variants];
    }

    private static function describe(array $entry, callable $url, string $fallback): array
    {
        $variants = collect($entry['variants'] ?? [])->sortBy('width')->values();
        $largest = $variants->last();

        return [
            'src' => $largest ? $url($largest['path']) : $fallback,
            'fallback' => $fallback,
            'srcset' => $variants->map(fn ($v) => $url($v['path']).' '.$v['width'].'w')->implode(', '),
            'width' => (int) ($entry['width'] ?? 1200),
            'height' => (int) ($entry['height'] ?? 800),
        ];
    }
}
