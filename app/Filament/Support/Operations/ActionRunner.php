<?php

namespace App\Filament\Support\Operations;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Runs a domain operation from an admin action and turns the outcome into a
 * Filament notification. Domain failures (invalid transitions, refund rules,
 * operations that are not available yet, ...) are shown to the administrator
 * instead of crashing the page; unexpected errors are reported and summarised.
 */
final class ActionRunner
{
    /**
     * @param  Closure(): mixed  $operation
     * @param  string|Closure(mixed): ?string|null  $successBody
     * @param  Action|null  $keepOpen  when given, a failed operation keeps this action's modal (and form input) open
     * @return bool whether the operation succeeded
     */
    public static function run(
        Closure $operation,
        string $successTitle,
        string $failureTitle,
        string|Closure|null $successBody = null,
        ?Action $keepOpen = null,
    ): bool {
        try {
            $result = $operation();
        } catch (Halt|Cancel|ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            self::notifyFailure($failureTitle, $e);

            $keepOpen?->halt();

            return false;
        }

        Notification::make()
            ->success()
            ->title($successTitle)
            ->body($successBody instanceof Closure ? $successBody($result) : $successBody)
            ->send();

        return true;
    }

    public static function notifyFailure(string $title, Throwable $e): void
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body(self::message($e))
            ->persistent()
            ->send();
    }

    /** A message that is safe and useful for an administrator. */
    public static function message(Throwable $e): string
    {
        $isDomainError = ($e instanceof RuntimeException || $e instanceof LogicException || $e instanceof InvalidArgumentException || $e instanceof AuthorizationException)
            && ! $e instanceof QueryException;

        if ($isDomainError && trim($e->getMessage()) !== '') {
            Log::info('Admin operation refused', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $e->getMessage() === 'Not implemented yet.'
                ? 'This operation is not available yet (the service has not been implemented).'
                : $e->getMessage();
        }

        report($e);

        return 'An unexpected error occurred. The details have been logged.';
    }
}
