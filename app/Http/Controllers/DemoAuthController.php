<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DemoAuthController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        return $request->session()->get('demo_authenticated')
            ? redirect()->route('dashboard')
            : view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($credentials['email'] !== config('demo.email') || $credentials['password'] !== config('demo.password')) {
            return back()->withErrors(['email' => 'E-mail ou senha de demonstração inválidos.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->put('demo_authenticated', true);

        return redirect()->intended(route('dashboard'))->with('success', 'Bem-vindo ao My Coins!');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Sessão encerrada.');
    }
}
