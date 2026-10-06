<?php

namespace App\Filament\Support\Catalogue;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Renders admin-authored Markdown for previews inside the panel. Raw HTML is
 * escaped and unsafe links are dropped, mirroring how the public site renders
 * CMS content.
 */
final class MarkdownPreview
{
    public static function html(?string $markdown): HtmlString
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return new HtmlString('<p style="color: var(--gray-500);">Nothing to preview yet.</p>');
        }

        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return new HtmlString('<div class="fi-prose" style="max-width: 72ch;">'.$html.'</div>');
    }
}
