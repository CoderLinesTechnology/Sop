<?php

namespace App\Filament\Support\Catalogue;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/** Search-engine and social-sharing fields shared by services, articles and pages. */
final class SeoFields
{
    /**
     * @return list<\Filament\Forms\Components\Field>
     */
    public static function make(?string $ogImageField = 'og_image_path', string $mediaDirectory = 'seo'): array
    {
        $fields = [
            TextInput::make('seo_title')
                ->label('SEO title')
                ->maxLength(255)
                ->helperText('Shown in search results and browser tabs. Aim for 50–60 characters; leave blank to use the title.'),
            Textarea::make('seo_description')
                ->label('Meta description')
                ->rows(3)
                ->maxLength(500)
                ->helperText('One or two sentences shown under the title in search results. Aim for 120–160 characters.'),
        ];

        if ($ogImageField !== null) {
            $fields[] = MediaUpload::to($ogImageField, $mediaDirectory)
                ->label('Social sharing image')
                ->helperText('Used when the page is shared on social media (1200 × 630 px recommended). JPEG, PNG or WebP, up to 5 MB. Leave blank to use the site default.');
        }

        return $fields;
    }
}
