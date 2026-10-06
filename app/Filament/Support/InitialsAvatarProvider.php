<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Initials avatars drawn locally as SVG. Filament's default sends every
 * administrator's name to ui-avatars.com; this keeps the panel free of
 * third-party requests (and works under the admin CSP).
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = collect(preg_split('/\s+/u', trim(Filament::getNameForDefaultAvatar($record))) ?: [])
            ->map(fn (string $part) => mb_strtoupper(mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $part), 0, 1)))
            ->filter()
            ->take(2)
            ->implode('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#12403a"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="Inter, system-ui, sans-serif" font-size="26" font-weight="600">'
            .htmlspecialchars($initials !== '' ? $initials : '?', ENT_XML1)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
