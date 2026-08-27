<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Services\RecurringTransactionService;
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
        return view('transactions.form', $this->formData($store) + ['transaction' => null, 'recurrence' => null]);
    }

    public function store(Request $request, FinanceStore $store, RecurringTransactionService $recurrences): RedirectResponse
    {
        $attributes = $this->validated($request, $store);
        $recurrence = $this->validatedRecurrence($request, false);
        $transaction = $recurrence
            ? $store->find('transactions', $recurrences->create(auth()->id(), $attributes, $recurrence['frequency'], $recurrence['end_date'])->id)
            : $store->create('transactions', $attributes);

        return redirect()->route('transactions.show', $transaction['id'])->with(
            'success',
            $recurrence ? 'Transação recorrente criada e próximas ocorrências agendadas.' : 'Transação adicionada com sucesso.',
        );
    }

    public function show(int $transaction, FinanceStore $store, RecurringTransactionService $recurrences): View
    {
        $item = $store->find('transactions', $transaction) ?? abort(404);
        $recurrence = $item['recurring_transaction_id']
            ? $recurrences->findForUser(auth()->id(), $item['recurring_transaction_id'])
            : null;

        return view('transactions.show', $this->formData($store, true) + ['transaction' => $item, 'recurrence' => $recurrence]);
    }

    public function edit(
        int $transaction,
        FinanceStore $store,
        RecurringTransactionService $recurrences,
    ): View|RedirectResponse {
        $item = $store->find('transactions', $transaction) ?? abort(404);
        if ($item['type'] === 'transfer') {
            return redirect()->route('transfers.edit', $transaction);
        }

        $recurrence = $item['recurring_transaction_id']
            ? $recurrences->findForUser(auth()->id(), $item['recurring_transaction_id'])
            : null;

        return view('transactions.form', $this->formData($store) + compact('recurrence') + ['transaction' => $item]);
    }

    public function update(
        Request $request,
        int $transaction,
        FinanceStore $store,
        RecurringTransactionService $recurrences,
    ): RedirectResponse {
        $existing = $store->find('transactions', $transaction) ?? abort(404);
        abort_if($existing['type'] === 'transfer', 404);
        $attributes = $this->validated($request, $store);
        if ($existing['recurring_transaction_id'] && $request->input('recurrence_scope', 'single') === 'future') {
            $recurrence = $this->validatedRecurrence($request, true);
            $recurrences->updateThisAndFuture(
                auth()->id(),
                $transaction,
                $attributes,
                $recurrence['frequency'],
                $recurrence['end_date'],
            );
        } else {
            $store->update('transactions', $transaction, $attributes);
        }

        return redirect()->route('transactions.show', $transaction)->with('success', 'Transação atualizada com sucesso.');
    }

    public function destroy(
        Request $request,
        int $transaction,
        FinanceStore $store,
        RecurringTransactionService $recurrences,
    ): RedirectResponse {
        $existing = $store->find('transactions', $transaction) ?? abort(404);
        if ($existing['recurring_transaction_id'] && $request->input('recurrence_scope') === 'future') {
            abort_unless($recurrences->cancelFromOccurrence(auth()->id(), $transaction), 404);

            return redirect()->route('transactions.index')->with('success', 'Esta ocorrência e as próximas foram canceladas. O histórico foi preservado.');
        }
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

    private function validatedRecurrence(Request $request, bool $required): ?array
    {
        if (! $required && ! $request->boolean('recurring')) {
            return null;
        }

        $validated = $request->validate([
            'frequency' => ['required', 'in:weekly,monthly,yearly'],
            'recurrence_end_date' => ['nullable', 'date', 'after_or_equal:date'],
            'recurrence_scope' => [$required ? 'required' : 'nullable', 'in:single,future'],
        ]);

        return [
            'frequency' => $validated['frequency'],
            'end_date' => $validated['recurrence_end_date'] ?? null,
        ];
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
