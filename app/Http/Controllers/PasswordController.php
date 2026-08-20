<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $user = $request->user();
        $user->update(['password' => $validated['password'], 'must_change_password' => false, 'password_changed_at' => now()]);
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Senha atualizada com sucesso.');
    }
}
