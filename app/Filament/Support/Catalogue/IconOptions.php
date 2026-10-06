<?php

namespace App\Filament\Support\Catalogue;

/**
 * Icon names understood by the public site's <x-icon> component, and the
 * tints available for service icons.
 */
final class IconOptions
{
    /** Icons offered for services and article categories. */
    public const SERVICE_ICONS = [
        'document' => 'Document',
        'graduation-cap' => 'Graduation cap',
        'envelope' => 'Envelope',
        'star' => 'Star',
        'pen' => 'Pen',
        'briefcase' => 'Briefcase',
        'globe' => 'Globe',
        'user' => 'User',
        'book' => 'Book',
        'lightbulb' => 'Light bulb',
        'heart' => 'Heart',
        'sparkle' => 'Sparkle',
    ];

    public const SERVICE_COLORS = [
        'green' => 'Green',
        'blue' => 'Blue',
        'purple' => 'Purple',
        'yellow' => 'Yellow',
        'teal' => 'Teal',
        'rose' => 'Rose',
    ];

    /** Icons for page building blocks (steps, features). */
    public const CONTENT_ICONS = self::SERVICE_ICONS + [
        'search' => 'Search',
        'mail' => 'Mail',
        'shield' => 'Shield',
        'shield-check' => 'Shield with check',
        'check-circle' => 'Check circle',
        'clock' => 'Clock',
        'lock' => 'Lock',
        'message' => 'Message',
        'calendar' => 'Calendar',
        'send' => 'Send',
        'trophy' => 'Trophy',
        'upload-cloud' => 'Upload',
    ];

    /** Filament colour used to preview a service tint in tables. */
    public static function filamentColor(?string $tint): string
    {
        return match ($tint) {
            'green', 'teal' => 'success',
            'blue' => 'info',
            'yellow' => 'warning',
            'rose' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Options for a select, keeping an unknown stored value selectable so
     * editing a record never silently drops it.
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public static function withCurrent(array $options, mixed $current): array
    {
        if (is_string($current) && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }
}
