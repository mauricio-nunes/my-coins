<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = Str::transliterate(mb_strtolower($credentials['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($key).' segundos.']);
        }
        if (! Auth::attempt($credentials, false)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))->with('success', 'Bem-vindo ao My Coins!');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Sessão encerrada.');
    }
}
