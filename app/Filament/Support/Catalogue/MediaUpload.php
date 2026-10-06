<?php

namespace App\Filament\Support\Catalogue;

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Arr;
use League\Flysystem\UnableToCheckFileExistence;

/**
 * Image upload for public CMS media (service images, article covers, page
 * heroes, logos...). Files are stored on the public media disk
 * (config statementra.storage.public_disk) in the given directory.
 *
 * Values may also be bundled images shipped with the site
 * ("images/home/hero.jpg" under public/), which the stock FileUpload would
 * silently drop because they are not on the disk. They are kept and
 * previewed from public/ so editing a page never loses its default imagery.
 *
 * Only JPEG, PNG and WebP are accepted: SVG can carry scripts and the public
 * disk is served from the site's own origin.
 */
class MediaUpload extends FileUpload
{
    public const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_KB = 5120;

    protected string $mediaDirectory = 'media';

    public static function to(string $name, string $directory): static
    {
        return static::make($name)->mediaDirectory($directory);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk(fn (): string => self::diskName())
            ->directory(fn (): string => $this->mediaDirectory)
            ->visibility('public')
            ->image()
            ->maxSize(self::MAX_KB)
            ->imagePreviewHeight('160')
            ->openable()
            ->preventFilePathTampering(
                allowFilePathUsing: fn (string $file): bool => self::isBundledAsset($file)
                    || str_starts_with($file, $this->mediaDirectory.'/'),
            )
            ->helperText('JPEG, PNG or WebP, up to 5 MB.');
    }

    /** Images only, and never SVG (see class docblock), even after avatar(). */
    public function image(): static
    {
        $this->acceptedFileTypes(self::ACCEPTED_TYPES);

        return $this;
    }

    public function mediaDirectory(string $directory): static
    {
        $this->mediaDirectory = trim($directory, '/');

        return $this;
    }

    public static function diskName(): string
    {
        return (string) config('statementra.storage.public_disk', 'public');
    }

    /** A bundled site image such as "images/home/hero.jpg" (served from public/). */
    public static function isBundledAsset(mixed $path): bool
    {
        return is_string($path)
            && ! str_contains($path, '..')
            && (bool) preg_match('#^images/[A-Za-z0-9/_\-.]+\.(jpe?g|png|webp|avif|gif)$#i', $path)
            && is_file(public_path($path));
    }

    public function hydrateFiles(): void
    {
        $shouldFetchFileInformation = $this->shouldFetchFileInformation();

        $this->rawState(array_filter(Arr::wrap($this->getRawState()), function (mixed $file) use ($shouldFetchFileInformation): bool {
            if (! is_string($file) || blank($file)) {
                return false;
            }

            if (self::isBundledAsset($file) || ! $shouldFetchFileInformation) {
                return true;
            }

            try {
                return $this->getDisk()->exists($file);
            } catch (UnableToCheckFileExistence) {
                return false;
            }
        }));
    }

    public function getUploadedFile(string $file, string|array|null $storedFileNames): ?array
    {
        if (self::isBundledAsset($file)) {
            $absolute = public_path($file);

            return [
                'name' => basename($file),
                'size' => (int) (@filesize($absolute) ?: 0),
                'type' => @mime_content_type($absolute) ?: null,
                'url' => asset($file),
            ];
        }

        return parent::getUploadedFile($file, $storedFileNames);
    }
}
