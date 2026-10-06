<?php

namespace App\Filament\Resources\PromptVersions\Schemas;

use App\Models\PromptVersion;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\HtmlString;

class PromptVersionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Version')
                ->schema([
                    TextEntry::make('prompt_key')->label('Prompt key')->fontFamily(FontFamily::Mono),
                    TextEntry::make('version')->prefix('v'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => ucfirst($state))
                        ->color(fn (string $state): string => self::statusColor($state)),
                    TextEntry::make('label')->placeholder('—'),
                    TextEntry::make('model')->label('Model fallback')->placeholder('Not set'),
                    TextEntry::make('reasoning_effort')->label('Reasoning fallback')->placeholder('Not set'),
                    TextEntry::make('createdBy.name')->label('Created by')->placeholder('System'),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('activatedBy.name')->label('Activated by')->placeholder('—'),
                    TextEntry::make('activated_at')->label('Activated')->dateTime()->placeholder('—'),
                    TextEntry::make('description')->label('What changed and why')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('System prompt')
                ->schema([
                    TextEntry::make('system_prompt')
                        ->hiddenLabel()
                        ->formatStateUsing(fn (?string $state): HtmlString => self::pre($state)),
                ])
                ->collapsible(),
            Section::make('User message template')
                ->schema([
                    TextEntry::make('user_template')
                        ->hiddenLabel()
                        ->placeholder('No template')
                        ->formatStateUsing(fn (?string $state): HtmlString => self::pre($state)),
                ])
                ->collapsible(),
        ]);
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            PromptVersion::STATUS_ACTIVE => 'success',
            PromptVersion::STATUS_DRAFT => 'warning',
            default => 'gray',
        };
    }

    private static function pre(?string $text): HtmlString
    {
        return new HtmlString('<pre style="white-space: pre-wrap; word-break: break-word; font-size: .8125rem; line-height: 1.55; margin: 0;">'.e((string) $text).'</pre>');
    }
}
