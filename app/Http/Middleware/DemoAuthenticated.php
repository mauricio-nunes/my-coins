<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('demo_authenticated', false)) {
            return redirect()->route('login')->with('warning', 'Entre com a conta de demonstração para continuar.');
        }

        return $next($request);
    }
}
