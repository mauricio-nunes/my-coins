<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

class CardStatementPaymentController extends Controller
{
    public function store(Request $request, int $card_statement, FinanceStore $store): RedirectResponse
    {
        $statement = $store->cardStatement($card_statement) ?? abort(404);
        $validated = $request->validate([
            'source_account_id' => ['required', 'integer'],
            'amount' => ['required', 'regex:/^\d{1,9}([\.,]\d{1,2})?$/'],
            'payment_date' => ['required', 'date'],
        ]);
        if (! $store->paymentAccounts()->contains('id', (int) $validated['source_account_id'])) {
            throw ValidationException::withMessages(['source_account_id' => 'Selecione uma conta corrente ou poupança ativa.']);
        }
        $amount = Money::toCents($validated['amount']);
        try {
            $store->payCardStatement($card_statement, (int) $validated['source_account_id'], $amount, $validated['payment_date']);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return redirect()->route('credit-cards.show', ['credit_card' => $statement['credit_card_id'], 'statement' => $statement['month']])
            ->with('success', 'Pagamento registrado como transferência, sem duplicar a despesa.');
    }

    public function destroy(int $card_statement_payment, FinanceStore $store): RedirectResponse
    {
        $payment = $store->cardStatementPayment($card_statement_payment) ?? abort(404);
        abort_unless($store->deleteCardStatementPayment($card_statement_payment), 404);

        return redirect()->route('credit-cards.show', ['credit_card' => $payment['credit_card_id'], 'statement' => $payment['statement_month']])
            ->with('success', 'Pagamento removido e transferência estornada.');
    }
}
