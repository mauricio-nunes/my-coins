<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function index(Request $request, FinanceStore $store): View
    {
        $month = $request->string('month', now()->format('Y-m'))->toString();
        $categories = collect($store->all('categories'))->keyBy('id');
        $budgets = collect($store->all('budgets'))->where('month', $month)->map(fn (array $budget): array => $budget + [
            'spent' => $store->budgetSpent($budget),
            'category' => $categories->get($budget['category_id']),
        ]);

        return view('budgets.index', compact('budgets', 'categories', 'month'));
    }

    public function create(FinanceStore $store): View
    {
        return view('budgets.form', ['budget' => null, 'categories' => collect($store->all('categories'))->where('type', 'expense')]);
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $validated = $this->validated($request, $store);
        $store->create('budgets', $validated);

        return redirect()->route('budgets.index', ['month' => $validated['month']])->with('success', 'Orçamento criado com sucesso.');
    }

    public function edit(int $budget, FinanceStore $store): View
    {
        return view('budgets.form', [
            'budget' => $store->find('budgets', $budget) ?? abort(404),
            'categories' => collect($store->all('categories'))->where('type', 'expense'),
        ]);
    }

    public function update(Request $request, int $budget, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->find('budgets', $budget), 404);
        $validated = $this->validated($request, $store, $budget);
        $store->update('budgets', $budget, $validated);

        return redirect()->route('budgets.index', ['month' => $validated['month']])->with('success', 'Orçamento atualizado.');
    }

    public function destroy(int $budget, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->delete('budgets', $budget), 404);

        return back()->with('success', 'Orçamento excluído.');
    }

    private function validated(Request $request, FinanceStore $store, ?int $ignore = null): array
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer'],
            'month' => ['required', 'date_format:Y-m'],
            'limit' => ['required', 'regex:/^\d{1,9}([\.,]\d{1,2})?$/'],
        ]);
        $category = $store->find('categories', (int) $validated['category_id']);
        if (! $category || $category['type'] !== 'expense') {
            throw ValidationException::withMessages(['category_id' => 'Selecione uma categoria de despesa.']);
        }
        $duplicate = collect($store->all('budgets'))->contains(fn (array $budget): bool => $budget['category_id'] === (int) $validated['category_id']
            && $budget['month'] === $validated['month'] && $budget['id'] !== $ignore);
        if ($duplicate) {
            throw ValidationException::withMessages(['category_id' => 'Já existe um orçamento para esta categoria no mês selecionado.']);
        }
        $validated['category_id'] = (int) $validated['category_id'];
        $validated['limit'] = Money::toCents($validated['limit']);
        if ($validated['limit'] <= 0) {
            throw ValidationException::withMessages(['limit' => 'O limite deve ser maior que zero.']);
        }

        return $validated;
    }
}
