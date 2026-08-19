<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\DemoFinanceStore;
use Carbon\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DemoFinanceStore $store): View
    {
        $summary = $store->dashboard();
        $accounts = collect($store->all('accounts'))->where('archived', false)->map(
            fn (array $account): array => $account + ['balance' => $store->balance($account['id'])],
        );
        $accountMap = collect($store->all('accounts'))->keyBy('id');
        $categories = collect($store->all('categories'))->keyBy('id');
        $budgets = collect($store->all('budgets'))->where('month', now()->format('Y-m'))->map(
            fn (array $budget): array => $budget + [
                'spent' => $store->budgetSpent($budget),
                'category' => $categories->get($budget['category_id']),
            ],
        );
        $report = $store->report([]);
        $cashFlowChart = [
            'chart' => ['type' => 'bar', 'height' => 300, 'toolbar' => ['show' => false]],
            'series' => [
                ['name' => 'Receitas', 'data' => $report['byMonth']->pluck('income')->map(fn (int $value): float => $value / 100)->values()],
                ['name' => 'Despesas', 'data' => $report['byMonth']->pluck('expense')->map(fn (int $value): float => $value / 100)->values()],
            ],
            'xaxis' => ['categories' => $report['byMonth']->pluck('month')->map(fn (string $month): string => Carbon::createFromFormat('Y-m', $month)->translatedFormat('M/y'))->values()],
            'colors' => ['#0f766e', '#ef4444'],
            'dataLabels' => ['enabled' => false],
            'plotOptions' => ['bar' => ['borderRadius' => 5]],
            'legend' => ['position' => 'top'],
        ];

        return view('dashboard.index', compact('summary', 'accounts', 'accountMap', 'budgets', 'categories', 'report', 'cashFlowChart'));
    }
}
