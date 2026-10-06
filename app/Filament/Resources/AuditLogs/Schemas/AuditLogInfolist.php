<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Models\AuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\HtmlString;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Entry')
                ->schema([
                    TextEntry::make('action')->badge()->fontFamily(FontFamily::Mono),
                    TextEntry::make('created_at')->label('When')->dateTime('j M Y H:i:s'),
                    TextEntry::make('actor')
                        ->label('By')
                        ->state(fn (AuditLog $record): string => $record->actor_label ?: ($record->admin?->name ?? ucfirst((string) $record->actor_type))),
                    TextEntry::make('target')
                        ->label('Target')
                        ->state(fn (AuditLog $record): ?string => $record->target_type
                            ? trim($record->target_type.($record->target_id ? ' #'.$record->target_id : '').($record->target_label ? ' — '.$record->target_label : ''))
                            : null)
                        ->placeholder('—'),
                    TextEntry::make('ip_address')->label('IP address')->placeholder('—'),
                    TextEntry::make('user_agent')->label('Browser')->placeholder('—')->limit(120)->columnSpan(3),
                ])
                ->columns(4)
                ->columnSpanFull(),
            Grid::make(2)
                ->schema([
                    Section::make('Before')
                        ->schema([
                            TextEntry::make('before')->hiddenLabel()->state(fn (AuditLog $record): HtmlString => self::json($record->before)),
                        ]),
                    Section::make('After')
                        ->schema([
                            TextEntry::make('after')->hiddenLabel()->state(fn (AuditLog $record): HtmlString => self::json($record->after)),
                        ]),
                ])
                ->columnSpanFull(),
            Section::make('Details')
                ->schema([
                    TextEntry::make('meta')->hiddenLabel()->state(fn (AuditLog $record): HtmlString => self::json($record->meta)),
                ])
                ->visible(fn (AuditLog $record): bool => filled($record->meta))
                ->collapsible()
                ->columnSpanFull(),
        ]);
    }

    public static function json(?array $data): HtmlString
    {
        if ($data === null || $data === []) {
            return new HtmlString('<span style="color: var(--gray-500);">—</span>');
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return new HtmlString('<pre style="white-space: pre-wrap; word-break: break-word; font-size: .75rem; line-height: 1.5; margin: 0; max-height: 32rem; overflow: auto;">'.e((string) $json).'</pre>');
    }
}
