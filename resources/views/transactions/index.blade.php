@extends('layouts.app')
@section('title', 'Transações')
@section('eyebrow', 'ORGANIZAÇÃO')
@section('page_title', 'Transações')
@section('page_subtitle', 'Consulte e organize todas as movimentações financeiras.')
@section('page_actions')<a href="{{ route('imports.create') }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-arrow-up me-1"></i> Importar OFX</a><a href="{{ route('transfers.create') }}" class="btn btn-outline-primary"><i class="bi bi-arrow-left-right me-1"></i> Transferir</a><a href="{{ route('transactions.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nova transação</a>@endsection
@php
    $tagFilterConfig = json_encode([
        'plugins' => ['remove_button'],
        'placeholder' => 'Filtrar por tags...',
        'items' => array_map('strval', $filters['tags'] ?? []),
    ]);
@endphp

@section('page_content')
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <form method="get" class="row g-3 align-items-end" data-filter-required>
        <div class="col-lg-3"><label for="search" class="form-label">Buscar</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Descrição ou tag" data-filter-criterion></div>
        <div class="col-sm-6 col-lg-2"><label for="type" class="form-label">Tipo</label><select id="type" name="type" class="form-select" data-filter-criterion><option value="">Todos</option><option value="income" @selected(($filters['type'] ?? '') === 'income')>Receita</option><option value="expense" @selected(($filters['type'] ?? '') === 'expense')>Despesa</option><option value="transfer" @selected(($filters['type'] ?? '') === 'transfer')>Transferência</option></select></div>
        <div class="col-sm-6 col-lg-2"><label for="account_id" class="form-label">Conta</label><select id="account_id" name="account_id" class="form-select" data-filter-criterion><option value="">Todas</option>@foreach($accounts->where('type', '!=', 'credit_card') as $account)<option value="{{ $account['id'] }}" @selected(($filters['account_id'] ?? '') == $account['id'])>{{ $account['name'] }}</option>@endforeach</select></div>
        <div class="col-sm-6 col-lg-2"><label for="category_id" class="form-label">Categoria</label><select id="category_id" name="category_id" class="form-select" data-filter-criterion><option value="">Todas</option>@foreach($categories as $category)<option value="{{ $category['id'] }}" @selected(($filters['category_id'] ?? '') == $category['id'])>{{ $category['name'] }}</option>@endforeach</select></div>
        <div class="col-sm-6 col-lg-2"><label for="reconciled" class="form-label">Conciliada</label><select id="reconciled" name="reconciled" class="form-select" data-filter-criterion><option value="">Todas</option><option value="yes" @selected(($filters['reconciled'] ?? '') === 'yes')>Sim</option><option value="no" @selected(($filters['reconciled'] ?? '') === 'no')>Não</option></select></div>
        <div class="col-md-6 col-lg-3"><label for="tags" class="form-label">Tags <span class="text-body-secondary fw-normal">(todas)</span></label><select id="tags" name="tags[]" multiple aria-label="Filtrar transações por tags" class="form-select" data-tom-select data-tom-select-config="{{ $tagFilterConfig }}" data-filter-criterion><option value=""></option>@foreach($tags as $tag)<option value="{{ $tag['id'] }}" @selected(in_array((string) $tag['id'], array_map('strval', $filters['tags'] ?? []), true))>{{ $tag['name'] }}</option>@endforeach</select></div>
        <div class="col-sm-6 col-lg-2"><label for="from" class="form-label">De</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control" data-filter-criterion></div>
        <div class="col-sm-6 col-lg-2"><label for="to" class="form-label">Até</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control" data-filter-criterion></div>
        <div class="col-sm-6 col-lg-2"><label for="date_order" class="form-label">Ordenar por data</label><select id="date_order" name="date_order" class="form-select"><option value="desc" @selected(($filters['date_order'] ?? 'desc') === 'desc')>Mais recentes</option><option value="asc" @selected(($filters['date_order'] ?? '') === 'asc')>Mais antigas</option></select></div>
        <div class="col-lg-2 d-grid"><button class="btn btn-outline-primary" aria-label="Aplicar filtros" data-filter-submit><i class="bi bi-funnel me-1"></i> Filtrar</button></div>
        <div class="col-12 d-none" data-filter-feedback><p class="small text-danger mb-0">Selecione ao menos um filtro para consultar as transações.</p></div>
    </form>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Movimentações</h2><span class="badge text-bg-light">{{ $transactions->total() }} resultados</span></div>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Descrição</th><th>Conta</th><th>Data</th><th>Tipo</th><th class="text-end">Valor</th><th class="d-none d-lg-table-cell"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>
    @forelse($transactions as $transaction)
        @php
            $isTransfer = $transaction['type'] === 'transfer';
            $account = $isTransfer ? null : $accounts->firstWhere('id', $transaction['account_id']);
            $category = $isTransfer ? null : $categories->firstWhere('id', $transaction['category_id']);
            $source = $isTransfer ? $accounts->firstWhere('id', $transaction['source_account_id']) : null;
            $destination = $isTransfer ? $accounts->firstWhere('id', $transaction['destination_account_id']) : null;
            $description = $transaction['description'] ?: "Transferência de {$source['name']} para {$destination['name']}";
            [$typeLabel, $typeClass] = match ($transaction['type']) {
                'income' => ['Receita', 'text-bg-success-subtle text-success'],
                'expense' => ['Despesa', 'text-bg-danger-subtle text-danger'],
                default => ['Transferência', 'text-bg-primary-subtle text-primary'],
            };
        @endphp
        <tr><td><a href="{{ route('transactions.show', ['transaction' => $transaction['id'], 'return_to' => $returnTo]) }}" class="fw-semibold text-body text-decoration-none">{{ $description }}</a>@if($transaction['recurring_transaction_id'] ?? null)<span class="badge text-bg-primary-subtle text-primary ms-1"><i class="bi bi-arrow-repeat me-1"></i>Recorrente</span>@endif<span class="badge {{ $transaction['reconciled'] ? 'text-bg-success-subtle text-success' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }} ms-1"><i class="bi {{ $transaction['reconciled'] ? 'bi-check2-circle' : 'bi-circle' }} me-1"></i>{{ $transaction['reconciled'] ? 'Conciliada' : 'Pendente' }}</span><div class="small text-body-secondary">@if($isTransfer)<i class="bi bi-arrow-left-right me-1"></i>{{ $source['name'] }} → {{ $destination['name'] }}@else<i class="bi {{ $category['icon'] }} me-1"></i>{{ $category['name'] }}@endif</div>@if($transaction['tag_ids'] ?? [])<div class="d-flex flex-wrap gap-1 mt-2">@foreach($transaction['tag_ids'] as $tagId) @if($tag = $tags->firstWhere('id', $tagId))<span class="badge text-bg-light border fw-normal">#{{ $tag['name'] }}</span>@endif @endforeach</div>@endif<div class="d-lg-none mt-2">@include('transactions._list_actions')</div></td><td>{{ $isTransfer ? $source['name'].' → '.$destination['name'] : $account['name'] }}</td><td>{{ \Carbon\Carbon::parse($transaction['date'])->format('d/m/Y') }}@if(($transaction['recurring_transaction_id'] ?? null) && $transaction['date'] > now()->toDateString())<div class="small text-body-secondary">Agendada</div>@endif</td><td><span class="badge rounded-pill {{ $typeClass }}">{{ $typeLabel }}</span></td><td class="text-end fw-semibold {{ $transaction['type'] === 'income' ? 'text-success' : ($transaction['type'] === 'expense' ? 'text-danger' : 'text-body') }}">@if($isTransfer)<x-money :value="$transaction['amount']" />@else<x-money :value="($transaction['type'] === 'income' ? 1 : -1) * $transaction['amount']" :signed="true" />@endif</td><td class="text-end d-none d-lg-table-cell">@include('transactions._list_actions')</td></tr>
    @empty <tr><td colspan="6" class="text-center py-5"><i class="bi bi-search fs-2 text-body-secondary"></i><p class="mt-2 mb-0">Nenhuma transação encontrada.</p></td></tr> @endforelse
    </tbody></table></div>
    @if($transactions->hasPages())<div class="card-footer bg-transparent">{{ $transactions->links() }}</div>@endif
</div>
@endsection
