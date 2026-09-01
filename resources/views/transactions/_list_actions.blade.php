<div class="d-inline-flex gap-1">
    <form method="post" action="{{ route('transactions.reconciliation', $transaction['id']) }}">
        @csrf
        @method('patch')
        <input type="hidden" name="return_to" value="{{ $returnTo }}">
        <button type="submit" class="btn btn-sm {{ $transaction['reconciled'] ? 'btn-outline-success' : 'btn-outline-primary' }}" aria-label="{{ $transaction['reconciled'] ? 'Desfazer conciliação de' : 'Conciliar' }} {{ $description }}" title="{{ $transaction['reconciled'] ? 'Desfazer conciliação' : 'Conciliar' }}">
            <i class="bi {{ $transaction['reconciled'] ? 'bi-arrow-counterclockwise' : 'bi-check2-circle' }}"></i>
        </button>
    </form>
    <a href="{{ $isTransfer ? route('transfers.edit', ['transfer' => $transaction['id'], 'return_to' => $returnTo]) : route('transactions.edit', ['transaction' => $transaction['id'], 'return_to' => $returnTo]) }}" class="btn btn-sm btn-outline-secondary" aria-label="Editar {{ $description }}">
        <i class="bi bi-pencil"></i>
    </a>
</div>
