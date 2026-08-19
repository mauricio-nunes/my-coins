@extends('layouts.app')
@php
    $isTransfer = $transaction['type'] === 'transfer';
    $account = $isTransfer ? null : $accounts->firstWhere('id', $transaction['account_id']);
    $category = $isTransfer ? null : $categories->firstWhere('id', $transaction['category_id']);
    $source = $isTransfer ? $accounts->firstWhere('id', $transaction['source_account_id']) : null;
    $destination = $isTransfer ? $accounts->firstWhere('id', $transaction['destination_account_id']) : null;
    $description = $transaction['description'] ?: "Transferência de {$source['name']} para {$destination['name']}";
    $editRoute = $isTransfer ? route('transfers.edit', $transaction['id']) : route('transactions.edit', $transaction['id']);
@endphp
@section('title', $description)
@section('eyebrow', $isTransfer ? 'TRANSFERÊNCIA' : 'TRANSAÇÃO')
@section('page_title', $description)
@section('page_subtitle', 'Detalhes completos da movimentação.')
@section('page_actions')<a href="{{ $editRoute }}" class="btn btn-primary"><i class="bi bi-pencil me-1"></i> Editar</a>@endsection
@section('page_content')
<div class="row g-4"><div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body p-4">
    <div class="transaction-hero rounded-3 p-4 mb-4"><span class="badge {{ $isTransfer ? 'text-bg-primary' : ($transaction['type'] === 'income' ? 'text-bg-success' : 'text-bg-danger') }} mb-3">{{ $isTransfer ? 'Transferência' : ($transaction['type'] === 'income' ? 'Receita' : 'Despesa') }}</span><div class="display-6 fw-semibold {{ $isTransfer ? 'text-body' : ($transaction['type'] === 'income' ? 'text-success' : 'text-danger') }}">@if($isTransfer)<x-money :value="$transaction['amount']" />@else<x-money :value="($transaction['type'] === 'income' ? 1 : -1) * $transaction['amount']" :signed="true" />@endif</div></div>
    <dl class="row detail-list mb-0">
        <dt class="col-sm-4">Data efetiva</dt><dd class="col-sm-8">{{ \Carbon\Carbon::parse($transaction['date'])->format('d/m/Y') }}</dd>
        @if($isTransfer)
            <dt class="col-sm-4">Conta de origem</dt><dd class="col-sm-8">{{ $source['name'] }} · {{ $source['institution'] }}</dd>
            <dt class="col-sm-4">Conta de destino</dt><dd class="col-sm-8">{{ $destination['name'] }} · {{ $destination['institution'] }}</dd>
            <dt class="col-sm-4">Impacto</dt><dd class="col-sm-8"><span class="text-danger">− <x-money :value="$transaction['amount']" /></span> na origem e <span class="text-success">+ <x-money :value="$transaction['amount']" /></span> no destino</dd>
        @else
            <dt class="col-sm-4">Conta</dt><dd class="col-sm-8">{{ $account['name'] }} · {{ $account['institution'] }}</dd>
            <dt class="col-sm-4">Categoria</dt><dd class="col-sm-8"><i class="bi {{ $category['icon'] }} me-1"></i>{{ $category['name'] }}</dd>
            <dt class="col-sm-4">Tags</dt><dd class="col-sm-8">@forelse($transaction['tag_ids'] ?? [] as $tagId) @if($tag = $tags->firstWhere('id', $tagId))<span class="badge text-bg-light border fw-normal me-1">#{{ $tag['name'] }}</span>@endif @empty<span class="text-body-secondary">Nenhuma tag</span>@endforelse</dd>
            <dt class="col-sm-4">Observações</dt><dd class="col-sm-8">{{ $transaction['notes'] ?: 'Nenhuma observação' }}</dd>
        @endif
    </dl>
</div></div></div><div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Ações</h2><a href="{{ $editRoute }}" class="btn btn-outline-primary w-100 mb-2">Editar {{ $isTransfer ? 'transferência' : 'transação' }}</a><form action="{{ route('transactions.destroy', $transaction['id']) }}" method="post" data-confirm="Excluir esta {{ $isTransfer ? 'transferência' : 'transação' }} da sessão?">@csrf @method('delete')<button type="submit" class="btn btn-outline-danger w-100">Excluir {{ $isTransfer ? 'transferência' : 'transação' }}</button></form></div></div><a href="{{ route('transactions.index') }}" class="btn btn-link mt-2"><i class="bi bi-arrow-left me-1"></i>Voltar para transações</a></div></div>
@endsection
