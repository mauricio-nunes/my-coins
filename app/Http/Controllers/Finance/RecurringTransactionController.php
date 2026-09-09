<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceStore;
use App\Services\RecurringTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RecurringTransactionController extends Controller
{
    public function index(FinanceStore $store, RecurringTransactionService $recurrences): View
    {
        $items = $recurrences->latestForUser(auth()->id())->map(fn ($rule): array => [
            'rule' => $rule,
            'next' => $recurrences->nextOccurrence($rule),
        ]);
        $accounts = collect($store->all('accounts'))->where('archived', false)->where('type', '!=', 'credit_card')->values();

        return view('recurrences.index', compact('items', 'accounts'));
    }

    public function edit(int $recurrence, RecurringTransactionService $recurrences): RedirectResponse
    {
        $rule = $recurrences->findForUser(auth()->id(), $recurrence) ?? abort(404);
        $next = $recurrences->nextOccurrence($rule);
        if (! $next) {
            return redirect()->route('recurrences.index')->with('warning', 'Esta recorrência não possui uma próxima ocorrência editável.');
        }

        return redirect()->route('transactions.edit', $next->id);
    }

    public function destroy(int $recurrence, RecurringTransactionService $recurrences): RedirectResponse
    {
        abort_unless($recurrences->cancel(auth()->id(), $recurrence), 404);

        return redirect()->route('recurrences.index')->with('success', 'Recorrência cancelada. Os lançamentos anteriores foram preservados.');
    }

    public function resume(Request $request, int $recurrence, RecurringTransactionService $recurrences): RedirectResponse
    {
        $validated = $request->validate(['account_id' => ['required', 'integer']]);
        if (! $recurrences->resume(auth()->id(), $recurrence, (int) $validated['account_id'])) {
            throw ValidationException::withMessages(['account_id' => 'Selecione uma conta ativa e compatível com o período restante.']);
        }

        return redirect()->route('recurrences.index')->with('success', 'Recorrência retomada e próximas ocorrências agendadas.');
    }
}
