<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransferController extends Controller
{
    public function create(Request $request, FinanceStore $store): View
    {
        $defaultSourceId = $request->integer('source_account_id');
        $defaultSource = $store->find('accounts', $defaultSourceId);

        return view('transfers.form', [
            'transfer' => null,
            'accounts' => $this->accounts($store),
            'defaultSourceId' => $defaultSource && ! $defaultSource['archived'] ? $defaultSourceId : null,
        ]);
    }

    public function store(Request $request, FinanceStore $store): RedirectResponse
    {
        $transfer = $store->create('transactions', $this->validated($request, $store));

        return redirect()->route('transactions.show', $transfer['id'])->with('success', 'Transferência registrada com sucesso.');
    }

    public function edit(int $transfer, FinanceStore $store): View
    {
        $item = $store->find('transactions', $transfer) ?? abort(404);
        abort_unless($item['type'] === 'transfer', 404);

        return view('transfers.form', [
            'transfer' => $item,
            'accounts' => $this->accounts($store, $item),
            'defaultSourceId' => null,
        ]);
    }

    public function update(Request $request, int $transfer, FinanceStore $store): RedirectResponse
    {
        $item = $store->find('transactions', $transfer) ?? abort(404);
        abort_unless($item['type'] === 'transfer', 404);
        $store->update('transactions', $transfer, $this->validated($request, $store, $item));

        return redirect()->route('transactions.show', $transfer)->with('success', 'Transferência atualizada com sucesso.');
    }

    private function validated(Request $request, FinanceStore $store, ?array $existing = null): array
    {
        $validated = $request->validate([
            'source_account_id' => ['required', 'integer', 'different:destination_account_id'],
            'destination_account_id' => ['required', 'integer'],
            'amount' => ['required', 'regex:/^\d{1,9}([\.,]\d{1,2})?$/'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:120'],
        ]);
        $sourceId = (int) $validated['source_account_id'];
        $destinationId = (int) $validated['destination_account_id'];
        $source = $store->find('accounts', $sourceId);
        $destination = $store->find('accounts', $destinationId);

        if (! $source || ($source['archived'] && $sourceId !== ($existing['source_account_id'] ?? null))) {
            throw ValidationException::withMessages(['source_account_id' => 'Selecione uma conta de origem ativa.']);
        }
        if (! $destination || ($destination['archived'] && $destinationId !== ($existing['destination_account_id'] ?? null))) {
            throw ValidationException::withMessages(['destination_account_id' => 'Selecione uma conta de destino ativa.']);
        }

        $amount = Money::toCents($validated['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'O valor deve ser maior que zero.']);
        }

        return [
            'description' => trim($validated['description'] ?? ''),
            'type' => 'transfer',
            'amount' => $amount,
            'date' => $validated['date'],
            'source_account_id' => $sourceId,
            'destination_account_id' => $destinationId,
            'account_id' => null,
            'category_id' => null,
            'notes' => '',
            'tag_ids' => [],
        ];
    }

    private function accounts(FinanceStore $store, ?array $transfer = null): Collection
    {
        $referenced = $transfer ? [$transfer['source_account_id'], $transfer['destination_account_id']] : [];

        return collect($store->all('accounts'))->filter(
            fn (array $account): bool => ! $account['archived'] || in_array($account['id'], $referenced, true),
        )->values();
    }
}
