<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CreditCardController extends Controller
{
    public function index(FinanceStore $store): View
    {
        return view('credit-cards.index', ['cards' => $store->creditCards()]);
    }

    public function create(FinanceStore $store): View
    {
        return view('credit-cards.form', ['card' => null, 'paymentAccounts' => $store->paymentAccounts()]);
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $card = $store->createCreditCard($this->validated($request, $store));

        return redirect()->route('credit-cards.show', $card['id'])->with('success', 'Cartão cadastrado com sucesso.');
    }

    public function show(Request $request, int $credit_card, FinanceStore $store): View
    {
        $month = $request->validate(['statement' => ['nullable', 'date_format:Y-m']])['statement'] ?? null;
        $card = $store->creditCardDetails($credit_card, $month) ?? abort(404);

        return view('credit-cards.show', ['card' => $card, 'paymentAccounts' => $store->paymentAccounts()]);
    }

    public function edit(int $credit_card, FinanceStore $store): View
    {
        return view('credit-cards.form', [
            'card' => $store->creditCard($credit_card) ?? abort(404),
            'paymentAccounts' => $store->paymentAccounts(),
        ]);
    }

    public function update(Request $request, int $credit_card, FinanceStore $store): RedirectResponse
    {
        $attributes = $this->validated($request, $store);
        if ($attributes['credit_limit'] < $store->cardUsedLimit($credit_card)) {
            throw ValidationException::withMessages(['credit_limit' => 'O limite não pode ser menor que o valor já utilizado.']);
        }
        abort_unless($store->updateCreditCard($credit_card, $attributes), 404);

        return redirect()->route('credit-cards.show', $credit_card)->with('success', 'Cartão atualizado com sucesso.');
    }

    public function destroy(int $credit_card, FinanceStore $store): RedirectResponse
    {
        abort_unless($store->archiveCreditCard($credit_card), 404);

        return redirect()->route('credit-cards.index')->with('success', 'Cartão arquivado. Compras foram bloqueadas e o histórico foi preservado.');
    }

    private function validated(Request $request, FinanceStore $store): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'network' => ['required', 'string', 'max:40'],
            'credit_limit' => ['required', 'regex:/^\d{1,9}([\.,]\d{1,2})?$/'],
            'closing_day' => ['required', 'integer', 'between:1,31'],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'default_payment_account_id' => ['required', 'integer'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        if (! $store->paymentAccounts()->contains('id', (int) $validated['default_payment_account_id'])) {
            throw ValidationException::withMessages(['default_payment_account_id' => 'Selecione uma conta corrente ou poupança ativa.']);
        }
        $validated['credit_limit'] = Money::toCents($validated['credit_limit']);
        if ($validated['credit_limit'] <= 0) {
            throw ValidationException::withMessages(['credit_limit' => 'O limite deve ser maior que zero.']);
        }

        return $validated;
    }
}
