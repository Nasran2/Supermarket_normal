<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

final class Audit
{
    private static function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['password', 'password_confirmation', 'current_password', 'remember_token', 'checkout_token', 'quote_hash'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::clean($value);
            }
        }

        return $data;
    }

    public static function record(string $action, Model $subject, array $before = [], array $after = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'before' => self::clean($before), 'after' => self::clean($after)]);
    }
}
