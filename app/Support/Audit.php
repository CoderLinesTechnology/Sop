<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Audit trail for sensitive actions: who did what, when, to which record,
 * with before/after state and request metadata.
 */
final class Audit
{
    /** Attribute names that must never be written to the audit log. */
    private const REDACT = [
        'password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes',
        'extracted_text', 'checkout_token_hash', 'access_code', 'verification_data', 'html_body', 'text_body',
    ];

    public static function log(
        string $action,
        Model|string|null $target = null,
        ?array $before = null,
        ?array $after = null,
        array $meta = [],
        ?AdminUser $admin = null,
    ): AuditLog {
        $admin ??= self::currentAdmin();

        [$targetType, $targetId, $targetLabel] = self::describeTarget($target);

        return AuditLog::query()->create([
            'admin_user_id' => $admin?->id,
            'actor_type' => $admin ? 'admin' : (app()->runningInConsole() ? 'system' : 'anonymous'),
            'actor_label' => $admin ? $admin->name.' <'.$admin->email.'>' : null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_label' => $targetLabel,
            'before' => self::redact($before),
            'after' => self::redact($after),
            'meta' => $meta ?: null,
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
            'user_agent' => app()->runningInConsole() ? null : mb_substr((string) Request::userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    /** Log a model change using its dirty attributes (call before save, or pass originals). */
    public static function modelChange(string $action, Model $model, array $original = [], array $meta = []): AuditLog
    {
        $changes = $model->getChanges() ?: $model->getDirty();
        $before = [];
        foreach (array_keys($changes) as $key) {
            if ($key === 'updated_at') {
                continue;
            }
            $before[$key] = $original[$key] ?? $model->getOriginal($key);
        }
        unset($changes['updated_at']);

        return self::log($action, $model, $before, $changes, $meta);
    }

    private static function currentAdmin(): ?AdminUser
    {
        try {
            $user = Auth::guard('admin')->user();

            return $user instanceof AdminUser ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0:?string,1:?string,2:?string} */
    private static function describeTarget(Model|string|null $target): array
    {
        if ($target === null) {
            return [null, null, null];
        }
        if (is_string($target)) {
            return [$target, null, null];
        }

        $label = match (true) {
            isset($target->reference) => (string) $target->reference,
            isset($target->code) => (string) $target->code,
            isset($target->name) => (string) $target->name,
            isset($target->title) => (string) $target->title,
            isset($target->email) => (string) $target->email,
            default => null,
        };

        return [class_basename($target), (string) $target->getKey(), $label];
    }

    private static function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        foreach ($data as $key => $value) {
            if (in_array($key, self::REDACT, true)) {
                $data[$key] = '[redacted]';
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format(DATE_ATOM);
            }
        }

        return $data;
    }
}
