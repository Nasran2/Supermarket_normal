<?php

namespace App\Models;

use App\Support\Hr;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'username', 'email', 'password', 'role_id', 'active'];

    protected $attributes = ['active' => true];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['password' => 'hashed', 'active' => 'boolean', 'email_verified_at' => 'datetime'];

    public function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = $value === null ? null : strtolower(trim($value));
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function isAdministrator(): bool
    {
        return $this->active && $this->role?->system && $this->role->name === 'Administrator';
    }

    public function hasPermission(string $permission): bool
    {
        if (str_starts_with($permission, 'hr.') && ! Hr::enabled()) {
            return false;
        }
        if (! $this->active || ! $this->role) {
            return false;
        }
        if ($this->isAdministrator()) {
            return isset(Permissions::all()[$permission]) || Permission::where('name', $permission)->exists();
        }

        return $this->role->permissions->contains('name', $permission);
    }
}
