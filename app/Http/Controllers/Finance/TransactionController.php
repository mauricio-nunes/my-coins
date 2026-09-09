<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Services\RecurringTransactionService;
use App\Support\Money;
use App\Support\TransactionListReturn;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function index(Request $request, FinanceStore $store): View|RedirectResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:income,expense,transfer'],
            'account_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'reconciled' => ['nullable', 'in:yes,no'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['integer'],
            'date_order' => ['nullable', 'in:asc,desc'],
        ]);

        $filterKeys = ['search', 'type', 'account_id', 'category_id', 'reconciled', 'from', 'to', 'tags'];
        $hasFilterInput = collect($filterKeys)->contains(fn (string $key): bool => $request->query->has($key));
        $hasCriteria = collect($filterKeys)->contains(function (string $key) use ($filters): bool {
            $value = $filters[$key] ?? null;

            return is_array($value) ? $value !== [] : $value !== null && trim((string) $value) !== '';
        });
        $today = CarbonImmutable::today();
        $currentMonth = [
            'from' => $today->startOfMonth()->toDateString(),
            'to' => $today->endOfMonth()->toDateString(),
        ];

        if ($hasFilterInput && ! $hasCriteria) {
            return redirect()->route('transactions.index', $currentMonth + ['date_order' => 'desc'])
                ->with('warning', 'Selecione ao menos um filtro para consultar as transações.');
        }
        if (! $hasCriteria) {
            $filters = $currentMonth + $filters;
        }
        $filters['date_order'] ??= 'desc';

        $items = $store->transactions($filters + ['tag_ids' => $filters['tags'] ?? []]);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $listQuery = collect($filters)
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->all();
        if ($page > 1) {
            $listQuery['page'] = $page;
        }
        $returnTo = route('transactions.index', $listQuery, false);
        $transactions = new LengthAwarePaginator($items->forPage($page, 8), $items->count(), 8, $page, [
            'path' => $request->url(),
            'query' => collect($listQuery)->except('page')->all(),
        ]);

        return view('transactions.index', $this->formData($store, true) + compact('transactions', 'filters', 'returnTo'));
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

    public function show(
        Request $request,
        int $transaction,
        FinanceStore $store,
        RecurringTransactionService $recurrences,
    ): View {
        $item = $store->find('transactions', $transaction) ?? abort(404);
        $recurrence = $item['recurring_transaction_id']
            ? $recurrences->findForUser(auth()->id(), $item['recurring_transaction_id'])
            : null;

        return view('transactions.show', $this->formData($store, true) + [
            'transaction' => $item,
            'recurrence' => $recurrence,
            'returnTo' => TransactionListReturn::from($request),
        ]);
    }

    public function edit(
        Request $request,
        int $transaction,
        FinanceStore $store,
        RecurringTransactionService $recurrences,
    ): View|RedirectResponse {
        $item = $store->find('transactions', $transaction) ?? abort(404);
        if ($item['card_installment']) {
            return redirect()->route('card-purchases.edit', $item['card_installment']['purchase_id']);
        }
        if ($item['statement_payment']) {
            return redirect()->route('credit-cards.show', [
                'credit_card' => $item['statement_payment']['credit_card_id'],
                'statement' => $item['statement_payment']['statement_month'],
            ]);
        }
        if ($item['type'] === 'transfer') {
            return redirect()->route('transfers.edit', $transaction);
        }

        $recurrence = $item['recurring_transaction_id']
            ? $recurrences->findForUser(auth()->id(), $item['recurring_transaction_id'])
            : null;

        return view('transactions.form', $this->formData($store) + compact('recurrence') + [
            'transaction' => $item,
            'returnTo' => TransactionListReturn::from($request),
        ]);
    }

    public function update(
        Request $request,
        int $transaction,
        FinanceStore $store,
        RecurringTransactionService $recurrences,
    ): RedirectResponse {
        $existing = $store->find('transactions', $transaction) ?? abort(404);
        abort_if($existing['card_installment'] || $existing['statement_payment'], 404);
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

        $returnTo = TransactionListReturn::from($request);

        return ($returnTo ? redirect()->to($returnTo) : redirect()->route('transactions.show', $transaction))
            ->with('success', 'Transação atualizada com sucesso.');
    }

    public function toggleReconciliation(Request $request, int $transaction, FinanceStore $store): RedirectResponse
    {
        $existing = $store->find('transactions', $transaction) ?? abort(404);
        $reconciled = ! $existing['reconciled'];
        $store->update('transactions', $transaction, ['reconciled' => $reconciled]);
        $returnTo = TransactionListReturn::from($request);

        if ($request->boolean('stay_on_detail')) {
            return redirect()->route('transactions.show', array_filter([
                'transaction' => $transaction,
                'return_to' => $returnTo,
            ]))->with('success', $reconciled ? 'Transação conciliada.' : 'Conciliação desfeita.');
        }

        return ($returnTo ? redirect()->to($returnTo) : redirect()->route('transactions.index'))
            ->with('success', $reconciled ? 'Transação conciliada.' : 'Conciliação desfeita.');
    }

    public function destroy(
        Request $request,
        int $transaction,
        FinanceStore $store,
        RecurringTransactionService $recurrences,
    ): RedirectResponse {
        $existing = $store->find('transactions', $transaction) ?? abort(404);
        $returnTo = TransactionListReturn::from($request);
        if ($existing['card_installment'] || $existing['statement_payment']) {
            return redirect()->route('transactions.show', array_filter([
                'transaction' => $transaction,
                'return_to' => $returnTo,
            ]))
                ->with('warning', 'Este lançamento é gerenciado pelo cartão de crédito e não pode ser excluído isoladamente.');
        }
        if ($existing['recurring_transaction_id'] && $request->input('recurrence_scope') === 'future') {
            abort_unless($recurrences->cancelFromOccurrence(auth()->id(), $transaction), 404);

            return ($returnTo ? redirect()->to($returnTo) : redirect()->route('transactions.index'))
                ->with('success', 'Esta ocorrência e as próximas foram canceladas. O histórico foi preservado.');
        }
        abort_unless($store->delete('transactions', $transaction), 404);

        return ($returnTo ? redirect()->to($returnTo) : redirect()->route('transactions.index'))
            ->with('success', 'Transação excluída. O registro foi preservado para auditoria.');
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
            'reconciled' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['required', 'string', 'max:30'],
        ]);
        $account = $store->find('accounts', (int) $validated['account_id']);
        $category = $store->find('categories', (int) $validated['category_id']);
        if (! $account || $account['archived']) {
            throw ValidationException::withMessages(['account_id' => 'Selecione uma conta ativa.']);
        }
        if ($account['type'] === 'credit_card') {
            throw ValidationException::withMessages(['account_id' => 'Registre compras de cartão na área Cartões de crédito.']);
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
        $validated['reconciled'] = $request->boolean('reconciled');
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
                fn ($accounts) => $accounts->where('archived', false)->where('type', '!=', 'credit_card'),
            )->values(),
            'categories' => collect($store->all('categories')),
            'tags' => collect($store->all('tags'))->sortBy('name')->values(),
        ];
    }
}
