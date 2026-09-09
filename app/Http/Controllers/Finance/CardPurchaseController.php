<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use LogicException;

class CardPurchaseController extends Controller
{
    public function create(int $credit_card, FinanceStore $store): View
    {
        $card = $store->creditCard($credit_card) ?? abort(404);
        abort_if($card['archived'], 404);

        return view('credit-cards.purchases.form', $this->formData($store, $card, null));
    }

    public function store(Request $request, int $credit_card, FinanceStore $store): RedirectResponse
    {
        try {
            $purchase = $store->createCardPurchase($credit_card, $this->validated($request, $store));
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['total_amount' => $exception->getMessage()]);
        }

        return redirect()->route('card-purchases.show', $purchase['id'])->with('success', 'Compra e parcelas registradas com sucesso.');
    }

    public function show(int $card_purchase, FinanceStore $store): View
    {
        return view('credit-cards.purchases.show', ['purchase' => $store->cardPurchase($card_purchase) ?? abort(404)]);
    }

    public function edit(int $card_purchase, FinanceStore $store): View|RedirectResponse
    {
        $purchase = $store->cardPurchase($card_purchase) ?? abort(404);
        if (! $purchase['can_change']) {
            return redirect()->route('card-purchases.show', $card_purchase)->with('warning', 'A compra não pode mais ser alterada porque uma fatura já fechou ou recebeu pagamento.');
        }

        return view('credit-cards.purchases.form', $this->formData($store, $store->creditCard($purchase['credit_card_id']), $purchase));
    }

    public function update(Request $request, int $card_purchase, FinanceStore $store): RedirectResponse
    {
        try {
            $purchase = $store->updateCardPurchase($card_purchase, $this->validated($request, $store));
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['total_amount' => $exception->getMessage()]);
        }
        if (! $purchase) {
            return redirect()->route('card-purchases.show', $card_purchase)->with('warning', 'Esta compra não pode mais ser alterada.');
        }

        return redirect()->route('card-purchases.show', $card_purchase)->with('success', 'Compra e parcelas atualizadas.');
    }

    public function destroy(int $card_purchase, FinanceStore $store): RedirectResponse
    {
        $purchase = $store->cardPurchase($card_purchase) ?? abort(404);
        if (! $store->deleteCardPurchase($card_purchase)) {
            return redirect()->route('card-purchases.show', $card_purchase)->with('warning', 'Esta compra não pode mais ser excluída.');
        }

        return redirect()->route('credit-cards.show', $purchase['credit_card_id'])->with('success', 'Compra excluída e limite liberado.');
    }

    private function validated(Request $request, FinanceStore $store): array
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:120'],
            'purchase_date' => ['required', 'date'],
            'total_amount' => ['required', 'regex:/^\d{1,9}([\.,]\d{1,2})?$/'],
            'category_id' => ['required', 'integer'],
            'installments_count' => ['required', 'integer', 'between:1,120'],
        ]);
        $category = $store->find('categories', (int) $validated['category_id']);
        if (! $category || $category['type'] !== 'expense') {
            throw ValidationException::withMessages(['category_id' => 'Selecione uma categoria de despesa.']);
        }
        $validated['total_amount'] = Money::toCents($validated['total_amount']);
        if ($validated['total_amount'] <= 0 || $validated['total_amount'] < (int) $validated['installments_count']) {
            throw ValidationException::withMessages(['total_amount' => 'Informe um valor positivo que possa ser dividido entre as parcelas.']);
        }

        return $validated;
    }

    private function formData(FinanceStore $store, array $card, ?array $purchase): array
    {
        return [
            'card' => $card,
            'purchase' => $purchase,
            'categories' => collect($store->all('categories'))->where('type', 'expense')->sortBy('name'),
        ];
    }
}
