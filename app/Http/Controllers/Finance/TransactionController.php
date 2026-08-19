<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\DemoFinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request, DemoFinanceStore $store): View
    {
        $filters = $request->only(['search', 'type', 'account_id', 'category_id', 'from', 'to']);
        $items = $store->transactions($filters);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $transactions = new LengthAwarePaginator($items->forPage($page, 8), $items->count(), 8, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('transactions.index', $this->formData($store) + compact('transactions', 'filters'));
    }

    public function create(DemoFinanceStore $store): View
    {
        return view('transactions.form', $this->formData($store) + ['transaction' => null]);
    }

    public function store(Request $request, DemoFinanceStore $store): RedirectResponse
    {
        $transaction = $store->create('transactions', $this->validated($request, $store));

        return redirect()->route('transactions.show', $transaction['id'])->with('success', 'Transação adicionada com sucesso.');
    }

    public function show(int $transaction, DemoFinanceStore $store): View
    {
        $item = $store->find('transactions', $transaction) ?? abort(404);

        return view('transactions.show', $this->formData($store) + ['transaction' => $item]);
    }

    public function edit(int $transaction, DemoFinanceStore $store): View
    {
        $item = $store->find('transactions', $transaction) ?? abort(404);

        return view('transactions.form', $this->formData($store) + ['transaction' => $item]);
    }

    public function update(Request $request, int $transaction, DemoFinanceStore $store): RedirectResponse
    {
        abort_unless($store->find('transactions', $transaction), 404);
        $store->update('transactions', $transaction, $this->validated($request, $store));

        return redirect()->route('transactions.show', $transaction)->with('success', 'Transação atualizada com sucesso.');
    }

    public function destroy(int $transaction, DemoFinanceStore $store): RedirectResponse
    {
        abort_unless($store->delete('transactions', $transaction), 404);

        return redirect()->route('transactions.index')->with('success', 'Transação excluída da sessão.');
    }

    private function validated(Request $request, DemoFinanceStore $store): array
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'regex:/^\d{1,9}([\.,]\d{1,2})?$/'],
            'date' => ['required', 'date'],
            'account_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $account = $store->find('accounts', (int) $validated['account_id']);
        $category = $store->find('categories', (int) $validated['category_id']);
        if (! $account || $account['archived']) {
            throw ValidationException::withMessages(['account_id' => 'Selecione uma conta ativa.']);
        }
        if (! $category || $category['type'] !== $validated['type']) {
            throw ValidationException::withMessages(['category_id' => 'A categoria deve corresponder ao tipo da transação.']);
        }
        $validated['amount'] = Money::toCents($validated['amount']);
        if ($validated['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'O valor deve ser maior que zero.']);
        }
        $validated['account_id'] = (int) $validated['account_id'];
        $validated['category_id'] = (int) $validated['category_id'];
        $validated['notes'] ??= '';

        return $validated;
    }

    private function formData(DemoFinanceStore $store): array
    {
        return [
            'accounts' => collect($store->all('accounts'))->where('archived', false),
            'categories' => collect($store->all('categories')),
        ];
    }
}
