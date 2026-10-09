<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

final class SalesVisibility
{
    public const OPTIONS = ['ALL' => 'All sales', 'ROLE' => 'Sales from the same role', 'OWN' => 'Only this person’s sales'];

    public static function mode(?User $user = null): string
    {
        $user ??= auth()->user();
        if (! $user || $user->isAdministrator()) {
            return 'ALL';
        }

        $mode = $user->role?->sales_visibility ?? 'ALL';

        return array_key_exists($mode, self::OPTIONS) ? $mode : 'OWN';
    }

    /** Restrict by the original sale owner, never a cashier filter supplied by the browser. */
    public static function apply(Builder|EloquentBuilder $query, string $column = 'sales.user_id', ?User $user = null): Builder|EloquentBuilder
    {
        $user ??= auth()->user();

        return match (self::mode($user)) {
            'OWN' => $query->where($column, $user?->id ?? 0),
            'ROLE' => $query->whereIn($column, User::select('id')->where('role_id', $user?->role_id ?? 0)),
            default => $query,
        };
    }

    public static function allowsOwner(int $ownerId, ?User $user = null): bool
    {
        $user ??= auth()->user();

        return match (self::mode($user)) {
            'OWN' => $ownerId === $user?->id,
            'ROLE' => User::whereKey($ownerId)->where('role_id', $user?->role_id)->exists(),
            default => true,
        };
    }

    public static function canSee(Sale $sale, ?User $user = null): bool
    {
        return self::allowsOwner((int) $sale->user_id, $user);
    }

    public static function canGrant(string $mode, ?Role $target = null): bool
    {
        $user = auth()->user();

        return self::mode($user) === 'ALL' || $mode === 'OWN' || ($mode === 'ROLE' && self::mode($user) === 'ROLE' && $target?->id === $user?->role_id);
    }

    /** Integer-only values, for correlated financial queries that cannot accept scope bindings. */
    public static function ownerSql(string $column): string
    {
        $user = auth()->user();

        return match (self::mode($user)) {
            'OWN' => $column.' = '.(int) $user?->id,
            'ROLE' => $column.' IN (SELECT id FROM users WHERE role_id = '.(int) $user?->role_id.')',
            default => '1 = 1',
        };
    }
}
