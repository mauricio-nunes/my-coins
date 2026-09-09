<div class="d-inline-flex gap-1">
    <form method="post" action="{{ route('transactions.reconciliation', $transaction['id']) }}">
        @csrf
        @method('patch')
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
        <button type="submit" class="btn btn-sm {{ $transaction['reconciled'] ? 'btn-outline-success' : 'btn-outline-primary' }}" aria-label="{{ $transaction['reconciled'] ? 'Desfazer conciliação de' : 'Conciliar' }} {{ $description }}" title="{{ $transaction['reconciled'] ? 'Desfazer conciliação' : 'Conciliar' }}">
            <i class="bi {{ $transaction['reconciled'] ? 'bi-arrow-counterclockwise' : 'bi-check2-circle' }}"></i>
        </button>
    </form>
    @php
        $managedRoute = $transaction['card_installment']
            ? route('card-purchases.show', $transaction['card_installment']['purchase_id'])
            : ($transaction['statement_payment']
                ? route('credit-cards.show', ['credit_card' => $transaction['statement_payment']['credit_card_id'], 'statement' => $transaction['statement_payment']['statement_month']])
                : ($isTransfer ? route('transfers.edit', ['transfer' => $transaction['id'], 'return_to' => $returnTo]) : route('transactions.edit', ['transaction' => $transaction['id'], 'return_to' => $returnTo])));
    @endphp
    <a href="{{ $managedRoute }}" class="btn btn-sm btn-outline-secondary" aria-label="{{ ($transaction['card_installment'] || $transaction['statement_payment']) ? 'Ver origem de' : 'Editar' }} {{ $description }}">
        <i class="bi {{ ($transaction['card_installment'] || $transaction['statement_payment']) ? 'bi-box-arrow-up-right' : 'bi-pencil' }}"></i>
    </a>
    @unless($transaction['card_installment'] || $transaction['statement_payment'])
        <form method="post" action="{{ route('transactions.destroy', $transaction['id']) }}" data-confirm="{{ ($transaction['recurring_transaction_id'] ?? null) ? 'Excluir esta transação recorrente? Somente esta ocorrência será excluída. O registro será preservado para auditoria.' : 'Excluir esta transação? O registro será preservado para auditoria.' }}">
            @csrf
            @method('delete')
            <input type="hidden" name="return_to" value="{{ $returnTo }}">
            @if($transaction['recurring_transaction_id'] ?? null)<input type="hidden" name="recurrence_scope" value="single">@endif
            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Excluir {{ $description }}" title="Excluir">
                <i class="bi bi-trash"></i>
            </button>
        </form>
    @endunless
</div>
