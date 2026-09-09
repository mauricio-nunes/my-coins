<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function index(Request $request, FinanceStore $store): View
    {
        $month = $request->string('month', now()->format('Y-m'))->toString();
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now()->format('Y-m');
        }
        $budgets = $store->budgetsForMonth($month);
        $periodLabel = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->locale('pt_BR')->translatedFormat('F/Y');

        return view('budgets.index', compact('budgets', 'month', 'periodLabel'));
    }

    public function create(Request $request, FinanceStore $store): View
    {
        $requestedMonth = $request->string('month')->toString();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth) ? $requestedMonth : now()->format('Y-m');

        return view('budgets.form', [
            'budget' => null,
            'month' => $month,
            'categories' => collect($store->all('categories'))->where('type', 'expense')->sortBy('name'),
        ]);
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $validated = $this->validated($request, $store);
        $store->createBudget($validated);

        return redirect()->route('budgets.index', ['month' => $validated['month']])->with('success', 'Orçamento criado com sucesso.');
    }

    public function edit(int $budget, FinanceStore $store): View
    {
        $item = $store->budgetDetails($budget) ?? abort(404);

        return view('budgets.form', [
            'budget' => $item,
            'month' => $item['month'],
            'categories' => collect($store->all('categories'))->where('type', 'expense')->sortBy('name'),
        ]);
    }

    public function update(Request $request, int $budget, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->budgetDetails($budget), 404);
        $validated = $this->validated($request, $store, $budget);
        $store->updateBudget($budget, $validated);

        return redirect()->route('budgets.index', ['month' => $validated['month']])->with('success', 'Orçamento atualizado.');
    }

    public function destroy(Request $request, int $budget, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->deleteBudget($budget), 404);

        return redirect()->route('budgets.index', ['month' => $request->input('month', now()->format('Y-m'))])->with('success', 'Orçamento excluído.');
    }

    public function copy(Request $request, int $budget, FinanceStore $store): RedirectResponse
    {
        $source = $store->budgetDetails($budget) ?? abort(404);
        $validated = $request->validate(['destination_month' => ['required', 'date_format:Y-m']]);
        if ($store->budgetNameExists($source['name'], $validated['destination_month'])) {
            throw ValidationException::withMessages(['destination_month' => 'Já existe um orçamento com este nome no mês de destino.']);
        }
        $store->copyBudget($budget, $validated['destination_month']);

        return redirect()->route('budgets.index', ['month' => $validated['destination_month']])->with('success', 'Orçamento copiado com sucesso.');
    }

    private function validated(Request $request, FinanceStore $store, ?int $ignore = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['required', 'integer', 'distinct'],
            'month' => ['required', 'date_format:Y-m'],
            'limit' => ['required', 'regex:/^(?:(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,2})?|\d+(?:\.\d{1,2})?)$/'],
        ]);
        $expenseCategoryIds = collect($store->all('categories'))->where('type', 'expense')->pluck('id')->map(fn (int $id): int => $id);
        $categoryIds = collect($validated['category_ids'])->map(fn (mixed $id): int => (int) $id)->unique()->values();
        if ($categoryIds->count() !== count($validated['category_ids']) || $categoryIds->diff($expenseCategoryIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['category_ids' => 'Selecione ao menos uma categoria de despesa válida.']);
        }
        if ($store->budgetNameExists($validated['name'], $validated['month'], $ignore)) {
            throw ValidationException::withMessages(['name' => 'Este nome já está em uso no mês selecionado.']);
        }
        $validated['category_ids'] = $categoryIds->all();
        $validated['limit'] = Money::toCents($validated['limit']);
        if ($validated['limit'] <= 0) {
            throw ValidationException::withMessages(['limit' => 'O limite deve ser maior que zero.']);
        }

        return $validated;
    }
}
