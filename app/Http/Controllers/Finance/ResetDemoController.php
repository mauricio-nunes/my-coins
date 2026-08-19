<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\DemoFinanceStore;
use Illuminate\Http\RedirectResponse;

class ResetDemoController extends Controller
{
    public function __invoke(DemoFinanceStore $store): RedirectResponse
    {
        $store->reset();

        return redirect()->route('dashboard')->with('success', 'Os dados de demonstração foram restaurados.');
    }
}
