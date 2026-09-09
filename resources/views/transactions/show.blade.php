@extends('layouts.app')
@php
    $isTransfer = $transaction['type'] === 'transfer';
    $account = $isTransfer ? null : $accounts->firstWhere('id', $transaction['account_id']);
    $category = $isTransfer ? null : $categories->firstWhere('id', $transaction['category_id']);
    $source = $isTransfer ? $accounts->firstWhere('id', $transaction['source_account_id']) : null;
    $destination = $isTransfer ? $accounts->firstWhere('id', $transaction['destination_account_id']) : null;
    $description = $transaction['description'] ?: "Transferência de {$source['name']} para {$destination['name']}";
    $managedByCard = (bool) ($transaction['card_installment'] || $transaction['statement_payment']);
    $backRoute = $returnTo ?: route('transactions.index');
    $editRoute = $transaction['card_installment']
        ? route('card-purchases.show', $transaction['card_installment']['purchase_id'])
        : ($transaction['statement_payment']
            ? route('credit-cards.show', ['credit_card' => $transaction['statement_payment']['credit_card_id'], 'statement' => $transaction['statement_payment']['statement_month']])
            : ($isTransfer
                ? route('transfers.edit', array_filter(['transfer' => $transaction['id'], 'return_to' => $returnTo]))
                : route('transactions.edit', array_filter(['transaction' => $transaction['id'], 'return_to' => $returnTo]))));
@endphp
@section('title', $description)
@section('eyebrow', $isTransfer ? 'TRANSFERÊNCIA' : 'TRANSAÇÃO')
@section('page_title', $description)
@section('page_subtitle', 'Detalhes completos da movimentação.')
@section('page_content')
<div class="row g-4"><div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body p-4">
    <div class="transaction-hero rounded-3 p-4 mb-4"><span class="badge {{ $isTransfer ? 'text-bg-primary' : ($transaction['type'] === 'income' ? 'text-bg-success' : 'text-bg-danger') }} mb-3">{{ $isTransfer ? 'Transferência' : ($transaction['type'] === 'income' ? 'Receita' : 'Despesa') }}</span><div class="display-6 fw-semibold {{ $isTransfer ? 'text-body' : ($transaction['type'] === 'income' ? 'text-success' : 'text-danger') }}">@if($isTransfer)<x-money :value="$transaction['amount']" />@else<x-money :value="($transaction['type'] === 'income' ? 1 : -1) * $transaction['amount']" :signed="true" />@endif</div></div>
    <dl class="row detail-list mb-0">
        <dt class="col-sm-4">Data efetiva</dt><dd class="col-sm-8">{{ \Carbon\Carbon::parse($transaction['date'])->format('d/m/Y') }}</dd>
        <dt class="col-sm-4">Conciliação</dt><dd class="col-sm-8"><span class="badge {{ $transaction['reconciled'] ? 'text-bg-success-subtle text-success' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }}"><i class="bi {{ $transaction['reconciled'] ? 'bi-check2-circle' : 'bi-circle' }} me-1"></i>{{ $transaction['reconciled'] ? 'Conciliada' : 'Pendente' }}</span></dd>
        @if($isTransfer)
            <dt class="col-sm-4">Conta de origem</dt><dd class="col-sm-8">{{ $source['name'] }} · {{ $source['institution'] }}</dd>
            <dt class="col-sm-4">Conta de destino</dt><dd class="col-sm-8">{{ $destination['name'] }} · {{ $destination['institution'] }}</dd>
            <dt class="col-sm-4">Impacto</dt><dd class="col-sm-8"><span class="text-danger">− <x-money :value="$transaction['amount']" /></span> na origem e <span class="text-success">+ <x-money :value="$transaction['amount']" /></span> no destino</dd>
        @else
            <dt class="col-sm-4">Conta</dt><dd class="col-sm-8">{{ $account['name'] }} · {{ $account['institution'] }}</dd>
            <dt class="col-sm-4">Categoria</dt><dd class="col-sm-8"><i class="bi {{ $category['icon'] }} me-1"></i>{{ $category['name'] }}</dd>
            @if($recurrence)<dt class="col-sm-4">Recorrência</dt><dd class="col-sm-8"><span class="badge text-bg-primary-subtle text-primary">{{ ['weekly' => 'Semanal', 'monthly' => 'Mensal', 'yearly' => 'Anual'][$recurrence->frequency] }}</span> <a href="{{ route('recurrences.index') }}" class="small ms-1">Gerenciar</a></dd>@endif
            <dt class="col-sm-4">Tags</dt><dd class="col-sm-8">@forelse($transaction['tag_ids'] ?? [] as $tagId) @if($tag = $tags->firstWhere('id', $tagId))<span class="badge text-bg-light border fw-normal me-1">#{{ $tag['name'] }}</span>@endif @empty<span class="text-body-secondary">Nenhuma tag</span>@endforelse</dd>
            <dt class="col-sm-4">Observações</dt><dd class="col-sm-8">{{ $transaction['notes'] ?: 'Nenhuma observação' }}</dd>
        @endif
    </dl>
</div></div></div><div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Ações</h2><form method="post" action="{{ route('transactions.reconciliation', $transaction['id']) }}" class="mb-2">@csrf @method('patch')<input type="hidden" name="stay_on_detail" value="1">@if($returnTo)<input type="hidden" name="return_to" value="{{ $returnTo }}">@endif<button type="submit" class="btn {{ $transaction['reconciled'] ? 'btn-outline-success' : 'btn-outline-primary' }} w-100"><i class="bi {{ $transaction['reconciled'] ? 'bi-arrow-counterclockwise' : 'bi-check2-circle' }} me-1"></i>{{ $transaction['reconciled'] ? 'Desfazer conciliação' : 'Conciliar transação' }}</button></form><a href="{{ $editRoute }}" class="btn btn-outline-primary w-100 mb-2">{{ $managedByCard ? 'Ver origem no cartão' : 'Editar '.($isTransfer ? 'transferência' : 'transação') }}</a>@if($managedByCard)<div class="alert alert-secondary mb-0"><i class="bi bi-lock me-1"></i>Este lançamento é gerenciado pelo cartão e não pode ser alterado isoladamente.</div>@else<form action="{{ route('transactions.destroy', $transaction['id']) }}" method="post" data-confirm="{{ $recurrence ? 'Excluir esta transação recorrente? Somente esta ocorrência será excluída. O registro será preservado para auditoria.' : 'Excluir esta transação? O registro será preservado para auditoria.' }}" @if($recurrence) data-confirm-future="Excluir esta transação recorrente e as próximas ocorrências? O histórico será preservado para auditoria." @endif>@csrf @method('delete')@if($returnTo)<input type="hidden" name="return_to" value="{{ $returnTo }}">@endif @if($recurrence)<label for="recurrence_scope" class="form-label small">Excluir</label><select id="recurrence_scope" name="recurrence_scope" class="form-select form-select-sm mb-2"><option value="single">Somente esta ocorrência</option><option value="future">Esta ocorrência e as próximas</option></select>@endif<button type="submit" class="btn btn-outline-danger w-100">Excluir {{ $isTransfer ? 'transferência' : 'transação' }}</button></form>@endif</div></div><a href="{{ $backRoute }}" class="btn btn-link mt-2"><i class="bi bi-arrow-left me-1"></i>Voltar para transações</a></div></div>
@endsection
