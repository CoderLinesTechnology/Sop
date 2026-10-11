<?php

namespace App\Domain\Documents;

use App\Models\Order;

/**
 * The address blocks of a letter: the applicant's own address above the date,
 * and the recipient (organisation and its address) between the date and
 * "Dear …". (Owned by the document engine.)
 *
 * Built deterministically from the order's answers when the version is
 * created and stored with the version's content, like ContactLine. Each block
 * appears only when the customer gave that address; the recipient block is
 * never shown without an address. Personal data: never sent to a model
 * (StageContext::answers() leaves these fields out) and never logged.
 */
final class LetterAddresses
{
    /** The applicant's own address. */
    public const SENDER_KEYS = ['address', 'your_address', 'home_address', 'postal_address', 'residential_address'];

    /** The address of the organisation the letter goes to. */
    public const RECIPIENT_KEYS = ['company_address', 'employer_address', 'recipient_address', 'organisation_address', 'organization_address', 'institution_address'];

    private const MAX_LINES = 6;

    private const MAX_LINE = 100;

    /** Answer keys that hold an address (excluded from everything sent to a model). */
    public static function answerKeys(): array
    {
        return [...self::SENDER_KEYS, ...self::RECIPIENT_KEYS];
    }

    public static function appliesTo(string $documentKind): bool
    {
        return in_array($documentKind, DocumentLayout::LETTER_KINDS, true);
    }

    /** @return array{address: ?string, recipient: ?string} */
    public static function forOrder(Order $order): array
    {
        if (! self::appliesTo($order->documentKind())) {
            return ['address' => null, 'recipient' => null];
        }

        $answers = $order->answers()->whereIn('field_key', self::answerKeys())->get(['field_key', 'value'])
            ->mapWithKeys(fn ($answer) => [$answer->field_key => $answer->value])
            ->all();

        return self::build($answers, $order->institution);
    }

    /**
     * @param  array<string, mixed>  $answers  answer values keyed by field key
     * @return array{address: ?string, recipient: ?string}
     */
    public static function build(array $answers, ?string $organisation = null): array
    {
        $address = self::lines(self::first($answers, self::SENDER_KEYS));
        $recipientAddress = self::lines(self::first($answers, self::RECIPIENT_KEYS));

        $recipient = null;
        if ($recipientAddress !== []) {
            $organisation = trim((string) $organisation);
            // The organisation heads the block unless the customer already wrote it as the first line.
            $lines = $organisation !== '' && mb_strtolower($recipientAddress[0]) !== mb_strtolower($organisation)
                ? [mb_substr($organisation, 0, self::MAX_LINE), ...$recipientAddress]
                : $recipientAddress;
            $recipient = implode("\n", array_slice($lines, 0, self::MAX_LINES));
        }

        return ['address' => $address === [] ? null : implode("\n", $address), 'recipient' => $recipient];
    }

    /**
     * An address as letter lines: the customer's own line breaks, or, for an
     * address typed on one line, its comma-separated parts
     * ("P.O. Box 12, Mampong, Ghana" → three lines).
     *
     * @return list<string>
     */
    public static function lines(mixed $value): array
    {
        $text = DocumentModel::cleanText(is_scalar($value) ? (string) $value : '');
        if ($text === '') {
            return [];
        }

        $parts = str_contains($text, "\n") ? explode("\n", $text) : explode(',', $text);
        $lines = array_values(array_filter(array_map(
            fn (string $line) => mb_substr(trim($line, " \t,"), 0, self::MAX_LINE),
            $parts,
        ), fn (string $line) => $line !== ''));

        return array_slice($lines, 0, self::MAX_LINES);
    }

    private static function first(array $answers, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (filled($answers[$key] ?? null)) {
                return $answers[$key];
            }
        }

        return null;
    }
}
