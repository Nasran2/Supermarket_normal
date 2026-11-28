<?php

namespace App\Http\Controllers;

use App\Services\DeveloperAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeveloperController extends Controller
{
    private static function fingerprint(): string
    {
        return hash_hmac('sha256', config('developer.username')."\0".config('developer.password'), (string) config('app.key'));
    }

    public static function authenticated(Request $request): bool
    {
        $session = $request->session()->get('developer_auth', []);

        return config('developer.username') !== '' && config('developer.password') !== ''
            && is_array($session) && is_string($session['token'] ?? null)
            && hash_equals(self::fingerprint(), $session['token'])
            && is_numeric($session['last_seen'] ?? null)
            && $session['last_seen'] > now()->timestamp - (int) config('developer.session_lifetime', 3600);
    }

    public function login(Request $request)
    {
        return self::authenticated($request) ? redirect()->route('developer.controls') : view('developer.login');
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate(['username' => 'required|string|max:100', 'password' => 'required|string|max:255']);
        $key = 'developer-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! config('developer.username') || ! config('developer.password')
            || ! hash_equals(hash('sha256', (string) config('developer.username')), hash('sha256', $data['username']))
            || ! hash_equals(hash('sha256', (string) config('developer.password')), hash('sha256', $data['password']))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'Developer username or password is incorrect.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('developer_auth', ['token' => self::fingerprint(), 'last_seen' => now()->timestamp]);

        return redirect()->route('developer.controls');
    }

    public function index(DeveloperAccess $access)
    {
        $modules = DeveloperAccess::modules();
        $catalog = DeveloperAccess::catalog();

        return view('developer.controls', compact('access', 'modules', 'catalog'));
    }

    public function update(Request $request, DeveloperAccess $access)
    {
        $keys = array_keys(DeveloperAccess::catalog());
        $data = $request->validate([
            'enabled' => ['nullable', 'array', 'max:'.count($keys)],
            'enabled.*' => ['required', 'string', 'distinct', Rule::in($keys)],
            'controls_complete' => ['required', 'accepted'],
        ]);
        $access->save($data['enabled'] ?? []);

        return redirect()->route('developer.controls')->with('success', 'Feature access updated. Changes apply to all store users immediately.');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('developer_auth');
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('developer.login');
    }
}
