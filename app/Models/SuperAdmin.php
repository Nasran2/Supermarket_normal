<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class SuperAdmin extends Authenticatable
{
    use Notifiable;

    protected $table = 'super_admins';

    protected $fillable = [
        'username',
        'password',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            // NOTE: Do NOT use 'hashed' cast here — we hash manually with Hash::make()
            // to avoid double-hashing when setting passwords.
            'active' => 'boolean',
        ];
    }

    public function getNameAttribute(): string
    {
        return 'Super Administrator';
    }

    public function isAdministrator(): bool
    {
        return true;
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return true;
    }
}

