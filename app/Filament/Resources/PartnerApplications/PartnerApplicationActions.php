<?php

namespace App\Filament\Resources\PartnerApplications;

use App\Domain\Referrals\PartnerApplications;
use App\Filament\Resources\Referrers\ReferrerResource;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\AdminContext;
use App\Models\PartnerApplication;
use App\Models\Referrer;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

/**
 * Vetting partner applications (referrals.manage): approve with a code and a
 * commission rate (creates the referrer and emails the links), reject with an
 * optional reason, keep internal notes, or delete on an erasure request.
 * Each is authorised against PartnerApplicationPolicy and audited by the
 * domain service.
 */
final class PartnerApplicationActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('decide')
            ->modalHeading('Approve this partner')
            ->modalDescription(fn (PartnerApplication $record): string => implode(' ', [
                'A referrer is created with this code and rate, and '.$record->email.' receives the share link and the private dashboard link.',
                ...PartnerApplicationResource::duplicateWarnings($record),
            ]))
            ->fillForm(fn (PartnerApplication $record): array => [
                'code' => app(PartnerApplications::class)->suggestCode($record),
                'commission_rate' => '10',
            ])
            ->schema([
                TextInput::make('code')
                    ->label('Referral code')
                    ->required()
                    ->maxLength(40)
                    ->regex('/^\s*[A-Za-z0-9_-]+\s*$/')
                    ->validationMessages(['regex' => 'Use only letters, numbers, dashes and underscores.'])
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $code = Referrer::normalizeCode((string) $value);
                        if ($code !== '' && ! PartnerApplications::codeIsFree($code)) {
                            $fail('This code is already used by a referrer or a coupon (archived ones included).');
                        }
                    }])
                    ->helperText('Prefilled with the applicant’s preferred code when it is free. Saved in upper case.'),
                TextInput::make('commission_rate')
                    ->label('Commission rate')
                    ->suffix('%')
                    ->required()
                    ->inputMode('decimal')
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $value = trim((string) $value);
                        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) || (float) $value > 100) {
                            $fail('Enter a percentage from 0 to 100 with at most two decimals, e.g. 12.5.');
                        }
                    }]),
            ])
            ->modalSubmitActionLabel('Approve and email the partner')
            ->action(function (array $data, PartnerApplication $record, Action $action): void {
                $succeeded = ActionRunner::run(
                    fn () => app(PartnerApplications::class)->approve($record, (string) $data['code'], (int) round(((float) $data['commission_rate']) * 100), AdminContext::require()),
                    'Partner approved',
                    "Couldn't approve the application",
                    fn (Referrer $referrer): string => 'Referrer '.$referrer->code.' was created and the partner has been emailed.',
                    keepOpen: $action,
                );
                Cache::forget('admin:referrals:applications-waiting');
                $record->refresh();
                if ($succeeded && $record->referrer && ReferrerResource::canEdit($record->referrer)) {
                    $action->redirect(ReferrerResource::getUrl('edit', ['record' => $record->referrer]));
                }
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('decide')
            ->modalHeading('Reject this application')
            ->modalDescription('The applicant is emailed. The reason, if you give one, is included in the email.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (optional, sent to the applicant)')
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->modalSubmitActionLabel('Reject application')
            ->action(function (array $data, PartnerApplication $record, Action $action): void {
                ActionRunner::run(
                    fn () => app(PartnerApplications::class)->reject($record, $data['reason'] ?? null, AdminContext::require()),
                    'Application rejected',
                    "Couldn't reject the application",
                    'The applicant has been emailed.',
                    keepOpen: $action,
                );
                Cache::forget('admin:referrals:applications-waiting');
                $record->refresh();
            });
    }

    public static function notes(): Action
    {
        return Action::make('notes')
            ->label('Internal notes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->authorize('update')
            ->fillForm(fn (PartnerApplication $record): array => ['admin_notes' => $record->admin_notes])
            ->schema([
                Textarea::make('admin_notes')
                    ->label('Notes (administrators only)')
                    ->maxLength(5000)
                    ->rows(5),
            ])
            ->modalSubmitActionLabel('Save notes')
            ->action(function (array $data, PartnerApplication $record): void {
                ActionRunner::run(
                    fn () => app(PartnerApplications::class)->updateNotes($record, $data['admin_notes'] ?? null, AdminContext::require()),
                    'Notes saved',
                    "Couldn't save the notes",
                );
                $record->refresh();
            });
    }

    public static function delete(): Action
    {
        return Action::make('deleteApplication')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('delete')
            ->requiresConfirmation()
            ->modalHeading('Delete this application?')
            ->modalDescription('Use this for an erasure request. The application is deleted for good; an approved partner’s referrer record is not affected.')
            ->modalSubmitActionLabel('Delete')
            ->action(function (PartnerApplication $record, Action $action): void {
                $deleted = ActionRunner::run(
                    fn () => app(PartnerApplications::class)->delete($record, AdminContext::require()),
                    'Application deleted',
                    "Couldn't delete the application",
                );
                Cache::forget('admin:referrals:applications-waiting');
                if ($deleted) {
                    $action->redirect(PartnerApplicationResource::getUrl('index'));
                }
            });
    }
}
