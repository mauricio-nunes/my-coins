<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\DemoFinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(DemoFinanceStore $store): View
    {
        $accounts = collect($store->all('accounts'))->map(fn (array $account): array => $account + ['balance' => $store->balance($account['id'])]);

        return view('accounts.index', compact('accounts'));
    }

    public function create(): View
    {
        return view('accounts.form', ['account' => null]);
    }

    public function store(Request $request, DemoFinanceStore $store): RedirectResponse
    {
        $account = $store->create('accounts', $this->validated($request) + ['archived' => false]);

        return redirect()->route('accounts.show', $account['id'])->with('success', 'Conta adicionada com sucesso.');
    }

    public function show(int $account, DemoFinanceStore $store): View
    {
        $item = $store->find('accounts', $account) ?? abort(404);
        $transactions = $store->transactions(['account_id' => $account])->take(10);

        return view('accounts.show', ['account' => $item + ['balance' => $store->balance($account)], 'transactions' => $transactions, 'categories' => collect($store->all('categories'))->keyBy('id')]);
    }

    public function edit(int $account, DemoFinanceStore $store): View
    {
        return view('accounts.form', ['account' => $store->find('accounts', $account) ?? abort(404)]);
    }

    public function update(Request $request, int $account, DemoFinanceStore $store): RedirectResponse
    {
        abort_unless($store->find('accounts', $account), 404);
        $store->update('accounts', $account, $this->validated($request));

        return redirect()->route('accounts.show', $account)->with('success', 'Conta atualizada com sucesso.');
    }

    public function destroy(int $account, DemoFinanceStore $store): RedirectResponse
    {
        abort_unless($store->find('accounts', $account), 404);
        $store->update('accounts', $account, ['archived' => true]);

        return redirect()->route('accounts.index')->with('success', 'Conta arquivada. O histórico foi preservado.');
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'institution' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:checking,savings,cash,investment'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'opening_balance' => ['required', 'regex:/^-?\d{1,9}([\.,]\d{1,2})?$/'],
        ]);
        $validated['opening_balance'] = Money::toCents($validated['opening_balance']);

        return $validated;
    }
}
