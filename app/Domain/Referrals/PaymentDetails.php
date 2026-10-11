<?php

namespace App\Domain\Referrals;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * How a referral partner is paid: mobile money (network, number, account
 * name) or a bank account (bank, account number, account name). Stored as an
 * encrypted array on the referrer and snapshotted on each payout request.
 * Outside the admin's payout screens it is only ever shown masked.
 */
final class PaymentDetails
{
    public const NETWORKS = [
        'mtn' => 'MTN MoMo',
        'telecel' => 'Telecel Cash',
        'airteltigo' => 'AirtelTigo Money',
    ];

    /** Validation rules for the dashboard form (field names as posted). */
    public static function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', 'in:momo,bank'],
            'momo_network' => ['nullable', 'required_if:payment_method,momo', 'string', Rule::in(array_keys(self::NETWORKS))],
            'momo_number' => ['nullable', 'required_if:payment_method,momo', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 \-]{7,18}$/'],
            'bank_name' => ['nullable', 'required_if:payment_method,bank', 'string', 'max:120'],
            'account_number' => ['nullable', 'required_if:payment_method,bank', 'string', 'max:34', 'regex:/^[0-9A-Za-z][0-9A-Za-z \-]{4,33}$/'],
            'account_name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'momo_network.required_if' => 'Choose your mobile money network.',
            'momo_number.required_if' => 'Enter your mobile money number.',
            'momo_number.regex' => 'Enter the mobile money number with digits only (spaces are fine).',
            'bank_name.required_if' => 'Enter the name of your bank.',
            'account_number.required_if' => 'Enter your account number.',
            'account_number.regex' => 'Enter the account number with letters and digits only.',
            'account_name.required' => 'Enter the name on the account.',
        ];
    }

    /**
     * The stored shape of validated input (only the chosen method's fields).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function fromInput(array $input): array
    {
        $clean = fn (mixed $value): string => trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? '');

        if (($input['payment_method'] ?? null) === 'bank') {
            return [
                'method' => 'bank',
                'bank_name' => $clean($input['bank_name'] ?? ''),
                'account_number' => $clean($input['account_number'] ?? ''),
                'account_name' => $clean($input['account_name'] ?? ''),
            ];
        }

        return [
            'method' => 'momo',
            'network' => (string) ($input['momo_network'] ?? ''),
            'number' => $clean($input['momo_number'] ?? ''),
            'account_name' => $clean($input['account_name'] ?? ''),
        ];
    }

    /** e.g. "MTN MoMo •••• 4567, A. Mensah" / "GCB Bank •••• 1234, A. Mensah". */
    public static function mask(?array $details): ?string
    {
        if (! is_array($details) || $details === []) {
            return null;
        }

        $label = ($details['method'] ?? null) === 'bank'
            ? (string) ($details['bank_name'] ?? 'Bank account')
            : (self::NETWORKS[$details['network'] ?? ''] ?? 'Mobile money');
        $digits = preg_replace('/[^0-9A-Za-z]/', '', (string) ($details['number'] ?? $details['account_number'] ?? '')) ?? '';

        return $label.' •••• '.substr($digits, -4).', '.self::maskName((string) ($details['account_name'] ?? ''));
    }

    /**
     * Every field, for administrators who handle payouts.
     *
     * @return array<string, string>
     */
    public static function describe(?array $details): array
    {
        if (! is_array($details) || $details === []) {
            return [];
        }

        return ($details['method'] ?? null) === 'bank'
            ? [
                'Method' => 'Bank transfer',
                'Bank' => (string) ($details['bank_name'] ?? ''),
                'Account number' => (string) ($details['account_number'] ?? ''),
                'Account name' => (string) ($details['account_name'] ?? ''),
            ]
            : [
                'Method' => 'Mobile money',
                'Network' => self::NETWORKS[$details['network'] ?? ''] ?? (string) ($details['network'] ?? ''),
                'Number' => (string) ($details['number'] ?? ''),
                'Account name' => (string) ($details['account_name'] ?? ''),
            ];
    }

    /** "Ama Serwaa Mensah" → "A. Mensah"; a single name keeps only its first letter. */
    private static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) >= 2) {
            return Str::upper(mb_substr($parts[0], 0, 1)).'. '.end($parts);
        }

        return $parts !== [] && $parts[0] !== '' ? Str::upper(mb_substr($parts[0], 0, 1)).'.' : '';
    }
}
