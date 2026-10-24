<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SecurityCheck extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Read-only checks of application settings and active accounts using the seeded password';

    public function handle(): int
    {
        $weak = User::where('active', true)->select('id', 'password')->lazy()->filter(fn ($user) => Hash::check('password', $user->password))->count();
        $checks = [
            'Debug output disabled' => ! config('app.debug'),
            'Session HttpOnly enabled' => (bool) config('session.http_only'),
            'Session SameSite protection enabled' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'No active accounts using the seeded password' => $weak === 0,
        ];
        if (str_starts_with((string) config('app.url'), 'https://') || app()->isProduction()) {
            if (config('session.secure') === null) {
                $this->line('Secure-cookie setting: automatic. Verify the Secure flag on the live HTTPS response.');
            } else {
                $checks['HTTPS-only session cookies enabled'] = (bool) config('session.secure');
            }
        }
        $this->line('Application settings and credential checks only; no files, accounts or records are changed.');
        $this->table(['Check', 'Result'], collect($checks)->map(fn ($safe, $label) => [$label, $safe ? 'PASS' : 'REVIEW REQUIRED'])->all());
        $this->line('Active seeded-password accounts: '.$weak);
        $this->line('Environment: '.app()->environment().'; CLI PHP: '.PHP_VERSION.'. Verify web PHP and Composer separately.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
