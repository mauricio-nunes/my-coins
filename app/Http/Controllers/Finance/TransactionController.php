<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request, FinanceStore $store): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:income,expense,transfer'],
            'account_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['integer'],
        ]);
        $items = $store->transactions($filters + ['tag_ids' => $filters['tags'] ?? []]);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $transactions = new LengthAwarePaginator($items->forPage($page, 8), $items->count(), 8, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('transactions.index', $this->formData($store, true) + compact('transactions', 'filters'));
    }

    public function create(FinanceStore $store): View
    {
        return view('transactions.form', $this->formData($store) + ['transaction' => null]);
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $transaction = $store->create('transactions', $this->validated($request, $store));

        return redirect()->route('transactions.show', $transaction['id'])->with('success', 'Transação adicionada com sucesso.');
    }

    public function show(int $transaction, FinanceStore $store): View
    {
        $item = $store->find('transactions', $transaction) ?? abort(404);

        return view('transactions.show', $this->formData($store, true) + ['transaction' => $item]);
    }

    public function edit(int $transaction, FinanceStore $store): View|RedirectResponse
    {
        $item = $store->find('transactions', $transaction) ?? abort(404);
        if ($item['type'] === 'transfer') {
            return redirect()->route('transfers.edit', $transaction);
        }

        return view('transactions.form', $this->formData($store) + ['transaction' => $item]);
    }

    public function update(Request $request, int $transaction, FinanceStore $store): RedirectResponse
    {
        $existing = $store->find('transactions', $transaction) ?? abort(404);
        abort_if($existing['type'] === 'transfer', 404);
        $store->update('transactions', $transaction, $this->validated($request, $store));

        return redirect()->route('transactions.show', $transaction)->with('success', 'Transação atualizada com sucesso.');
    }

    public function destroy(int $transaction, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->delete('transactions', $transaction), 404);

        return redirect()->route('transactions.index')->with('success', 'Transação excluída. O registro foi preservado para auditoria.');
    }

    private function validated(Request $request, FinanceStore $store): array
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'regex:/^\d{1,9}([\.,]\d{1,2})?$/'],
            'date' => ['required', 'date'],
            'account_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['required', 'string', 'max:30'],
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
        $tagNames = collect($validated['tags'] ?? [])->unique(fn (string $name): string => mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name),
        ))->values();
        if ($tagNames->count() > 10) {
            throw ValidationException::withMessages(['tags' => 'Selecione no máximo 10 tags.']);
        }
        $validated['tag_ids'] = $store->resolveTagIds($tagNames->all());
        unset($validated['tags']);

        return $validated;
    }

    private function formData(FinanceStore $store, bool $includeArchived = false): array
    {
        return [
            'accounts' => collect($store->all('accounts'))->when(
                ! $includeArchived,
                fn ($accounts) => $accounts->where('archived', false),
            )->values(),
            'categories' => collect($store->all('categories')),
            'tags' => collect($store->all('tags'))->sortBy('name')->values(),
        ];
    }
}
