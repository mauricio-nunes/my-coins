<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use Carbon\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(FinanceStore $store): View
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
        $dailyCashFlow = $store->dailyCashFlow();
        $dailyCashFlowChart = [
            'chart' => ['type' => 'line', 'height' => 320, 'toolbar' => ['show' => false]],
            'series' => [
                ['name' => 'Receitas', 'type' => 'column', 'data' => $dailyCashFlow->pluck('income')->map(fn (int $value): float => $value / 100)->values()->all()],
                ['name' => 'Despesas', 'type' => 'column', 'data' => $dailyCashFlow->pluck('expense')->map(fn (int $value): float => $value / 100)->values()->all()],
                ['name' => 'Saldo', 'type' => 'line', 'data' => $dailyCashFlow->pluck('balance')->map(fn (int $value): float => $value / 100)->values()->all()],
            ],
            'xaxis' => [
                'categories' => $dailyCashFlow->pluck('date')->map(fn (string $date): string => Carbon::parse($date)->format('d/m'))->values()->all(),
                'labels' => ['rotate' => -45, 'hideOverlappingLabels' => true],
            ],
            'colors' => ['#0f766e', '#ef4444', '#2563eb'],
            'dataLabels' => ['enabled' => false],
            'plotOptions' => ['bar' => ['borderRadius' => 3, 'columnWidth' => '65%']],
            'stroke' => ['curve' => 'smooth', 'width' => [0, 0, 3]],
            'markers' => ['size' => 3],
            'legend' => ['position' => 'top'],
            'responsive' => [[
                'breakpoint' => 576,
                'options' => ['chart' => ['height' => 300], 'markers' => ['size' => 0], 'xaxis' => ['labels' => ['rotate' => -60]]],
            ]],
        ];
        $today = Carbon::today();
        $monthTransactionFilters = [
            'from' => $today->copy()->startOfMonth()->toDateString(),
            'to' => $today->copy()->endOfMonth()->toDateString(),
        ];

        return view('dashboard.index', compact('summary', 'accounts', 'accountMap', 'budgets', 'categories', 'report', 'cashFlowChart', 'dailyCashFlowChart', 'monthTransactionFilters'));
    }
}
