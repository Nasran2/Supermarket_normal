<?php

namespace App\Models;

use App\Services\SmsSettingsService;
use App\Support\Hr;
use App\Support\Permissions;
use App\Support\Units;
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
        if (Units::permission($permission) && ! Units::enabled()) {
            return false;
        }
        if (str_starts_with($permission, 'sms.') && ! config('sms.enabled')) {
            return false;
        }
        if ($permission === 'sms.receipt' && ! app(SmsSettingsService::class)->allows('RECEIPT')) {
            return false;
        }
        if (str_starts_with($permission, 'cheques.') && ! config('features.cheque')) {
            return false;
        }
        if ((str_starts_with($permission, 'expiry.') || $permission === 'purchase.expiry_edit') && ! config('features.expiry')) {
            return false;
        }
        if (str_starts_with($permission, 'barcode.') && ! config('features.barcode')) {
            return false;
        }
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
