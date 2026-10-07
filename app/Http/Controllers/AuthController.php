<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\ProfileRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(LoginRequest $request)
    {
        $login = strtolower($request->validated('login'));
        $field = str_contains($login, '@') ? 'email' : 'username';
        $userId = User::where($field, $login)->value('id');
        // Both identifiers share the same account-level throttle.
        $key = 'login:'.($userId ?? hash('sha256', $login)).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! Auth::attempt([$field => $login, 'password' => $request->validated('password'), 'active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => 'Username/email or password is incorrect.']);
        }RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(Navigation::home($request->user()));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function profile()
    {
        return view('auth.profile');
    }

    public function updateProfile(ProfileRequest $request)
    {
        $data = $request->validated();
        unset($data['current_password']);
        $request->user()->update($data);
        $request->session()->put('password_hash_web', $request->user()->getAuthPassword());
        Audit::record('profile.password_changed', $request->user());
        $request->session()->regenerate();

        return back()->with('success', 'Password updated.');
    }
}
