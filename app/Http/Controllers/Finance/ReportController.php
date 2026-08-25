<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __invoke(Request $request, FinanceStore $store): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'account_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['integer'],
        ]);

        $report = $store->report($filters + ['tag_ids' => $filters['tags'] ?? []]);
        $trendChart = [
            'chart' => ['type' => 'area', 'height' => 320, 'toolbar' => ['show' => false]],
            'series' => [
                ['name' => 'Receitas', 'data' => $report['byMonth']->pluck('income')->map(fn (int $value): float => $value / 100)->values()],
                ['name' => 'Despesas', 'data' => $report['byMonth']->pluck('expense')->map(fn (int $value): float => $value / 100)->values()],
            ],
            'xaxis' => ['categories' => $report['byMonth']->pluck('month')->values()],
            'colors' => ['#0f766e', '#ef4444'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth'],
        ];
        $categoryChart = [
            'chart' => ['type' => 'donut', 'height' => 320],
            'series' => $report['byCategory']->pluck('amount')->map(fn (int $value): float => $value / 100)->values(),
            'labels' => $report['byCategory']->pluck('name')->values(),
            'colors' => ['#0f766e', '#2563eb', '#d97706', '#db2777', '#7c3aed'],
            'legend' => ['position' => 'bottom'],
        ];

        return view('reports.index', [
            'report' => $report,
            'filters' => $filters,
            'accounts' => collect($store->all('accounts')),
            'categories' => collect($store->all('categories')),
            'tags' => collect($store->all('tags'))->sortBy('name')->values(),
            'trendChart' => $trendChart,
            'categoryChart' => $categoryChart,
        ]);
    }
}
