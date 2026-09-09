<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Services\RecurringTransactionService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(FinanceStore $store): View
    {
        $accounts = collect($store->all('accounts'))->where('type', '!=', 'credit_card')
            ->map(fn (array $account): array => $account + ['balance' => $store->balance($account['id'])]);

        return view('accounts.index', compact('accounts'));
    }

    public function create(): View
    {
        return view('accounts.form', ['account' => null]);
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $account = $store->create('accounts', $this->validated($request) + ['archived' => false]);

        return redirect()->route('accounts.show', $account['id'])->with('success', 'Conta adicionada com sucesso.');
    }

    public function show(int $account, FinanceStore $store): View
    {
        $item = $store->find('accounts', $account) ?? abort(404);
        abort_if($item['type'] === 'credit_card', 404);
        $transactions = $store->transactions(['account_id' => $account])->take(10);

        return view('accounts.show', [
            'account' => $item + ['balance' => $store->balance($account)],
            'transactions' => $transactions,
            'accounts' => collect($store->all('accounts'))->where('type', '!=', 'credit_card')->keyBy('id'),
            'categories' => collect($store->all('categories'))->keyBy('id'),
            'hasTransactionsBeforeOpeningBalance' => $store->hasTransactionsBeforeOpeningBalance($account),
        ]);
    }

    public function edit(int $account, FinanceStore $store): View
    {
        $item = $store->find('accounts', $account) ?? abort(404);
        abort_if($item['type'] === 'credit_card', 404);

        return view('accounts.form', ['account' => $item]);
    }

    public function update(Request $request, int $account, FinanceStore $store): RedirectResponse
    {
        $item = $store->find('accounts', $account) ?? abort(404);
        abort_if($item['type'] === 'credit_card', 404);
        $store->update('accounts', $account, $this->validated($request));

        return redirect()->route('accounts.show', $account)->with('success', 'Conta atualizada com sucesso.');
    }

    public function destroy(int $account, FinanceStore $store, RecurringTransactionService $recurrences): RedirectResponse
    {
        $item = $store->find('accounts', $account) ?? abort(404);
        abort_if($item['type'] === 'credit_card', 404);
        $paused = DB::transaction(function () use ($store, $recurrences, $account): int {
            $store->update('accounts', $account, ['archived' => true]);

            return $recurrences->pauseForAccount(auth()->id(), $account);
        });

        $message = 'Conta arquivada. O histórico foi preservado.';
        if ($paused > 0) {
            $message .= " {$paused} recorrência(s) foram pausadas e as ocorrências futuras removidas.";
        }

        return redirect()->route('accounts.index')->with('success', $message);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'institution' => ['nullable', 'string', 'max:80'],
            'type' => ['required', 'in:checking,savings,cash,investment'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'opening_balance' => ['required', 'regex:/^-?\d{1,9}([\.,]\d{1,2})?$/'],
            'opening_balance_date' => ['required', 'date'],
        ]);
        $validated['opening_balance'] = Money::toCents($validated['opening_balance']);
        $validated['institution'] ??= '';

        return $validated;
    }
}
