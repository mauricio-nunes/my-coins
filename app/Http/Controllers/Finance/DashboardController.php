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
        $accounts = collect($store->all('accounts'))->where('archived', false)->where('type', '!=', 'credit_card')->map(
            fn (array $account): array => $account + ['balance' => $store->balance($account['id'])],
        );
        $accountMap = collect($store->all('accounts'))->keyBy('id');
        $categories = collect($store->all('categories'))->keyBy('id');
        $budgets = $store->budgetsForMonth(now()->format('Y-m'));
        $commitments = $store->cardCommitments();
        $cardCommitments = $store->cardCommitmentSummary($commitments);
        $report = $store->report([]);
        $cashFlow = $store->cashFlowByMonth($commitments);
        $cashFlowChart = [
            'chart' => ['type' => 'bar', 'height' => 300, 'toolbar' => ['show' => false]],
            'series' => [
                ['name' => 'Entradas', 'data' => $cashFlow->pluck('income')->map(fn (int $value): float => $value / 100)->values()],
                ['name' => 'Saídas registradas', 'data' => $cashFlow->pluck('expense')->map(fn (int $value): float => $value / 100)->values()],
                ['name' => 'Faturas previstas', 'data' => $cashFlow->pluck('card_statements')->map(fn (int $value): float => $value / 100)->values()],
            ],
            'xaxis' => ['categories' => $cashFlow->pluck('month')->map(fn (string $month): string => Carbon::createFromFormat('Y-m', $month)->translatedFormat('M/y'))->values()],
            'colors' => ['#0f766e', '#ef4444', '#f59e0b'],
            'dataLabels' => ['enabled' => false],
            'plotOptions' => ['bar' => ['borderRadius' => 5]],
            'legend' => ['position' => 'top'],
        ];
        $dailyCashFlow = $store->dailyCashFlow(commitments: $commitments);
        $dailyCashFlowChart = [
            'chart' => ['type' => 'line', 'height' => 320, 'toolbar' => ['show' => false]],
            'series' => [
                ['name' => 'Entradas', 'type' => 'column', 'data' => $dailyCashFlow->pluck('income')->map(fn (int $value): float => $value / 100)->values()->all()],
                ['name' => 'Saídas registradas', 'type' => 'column', 'data' => $dailyCashFlow->pluck('expense')->map(fn (int $value): float => $value / 100)->values()->all()],
                ['name' => 'Faturas previstas', 'type' => 'column', 'data' => $dailyCashFlow->pluck('card_statements')->map(fn (int $value): float => $value / 100)->values()->all()],
                ['name' => 'Saldo em conta', 'type' => 'line', 'data' => $dailyCashFlow->pluck('balance')->map(fn (int $value): float => $value / 100)->values()->all()],
                ['name' => 'Saldo após faturas', 'type' => 'line', 'data' => $dailyCashFlow->pluck('balance_after_cards')->map(fn (int $value): float => $value / 100)->values()->all()],
            ],
            'xaxis' => [
                'categories' => $dailyCashFlow->pluck('date')->map(fn (string $date): string => Carbon::parse($date)->format('d/m'))->values()->all(),
                'labels' => ['rotate' => -45, 'hideOverlappingLabels' => true],
            ],
            'colors' => ['#0f766e', '#ef4444', '#f59e0b', '#2563eb', '#7c3aed'],
            'dataLabels' => ['enabled' => false],
            'plotOptions' => ['bar' => ['borderRadius' => 3, 'columnWidth' => '65%']],
            'stroke' => ['curve' => 'smooth', 'width' => [0, 0, 0, 3, 3], 'dashArray' => [0, 0, 0, 0, 6]],
            'markers' => ['size' => [0, 0, 0, 3, 3]],
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

        return view('dashboard.index', compact('summary', 'accounts', 'accountMap', 'budgets', 'cardCommitments', 'categories', 'report', 'cashFlowChart', 'dailyCashFlowChart', 'monthTransactionFilters'));
    }
}
