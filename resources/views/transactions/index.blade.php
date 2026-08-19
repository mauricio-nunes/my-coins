@extends('layouts.app')
@section('title', 'Transações')
@section('eyebrow', 'ORGANIZAÇÃO')
@section('page_title', 'Transações')
@section('page_subtitle', 'Consulte e organize todas as movimentações financeiras.')
@section('page_actions')<a href="{{ route('transactions.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nova transação</a>@endsection
@php($tagFilterConfig = json_encode(['plugins' => ['remove_button'], 'placeholder' => 'Filtrar por tags...', 'items' => array_map('strval', $filters['tags'] ?? [])]))

@section('page_content')
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-lg-3"><label for="search" class="form-label">Buscar</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Descrição ou tag"></div>
        <div class="col-sm-6 col-lg-2"><label for="type" class="form-label">Tipo</label><select id="type" name="type" class="form-select"><option value="">Todos</option><option value="income" @selected(($filters['type'] ?? '') === 'income')>Receita</option><option value="expense" @selected(($filters['type'] ?? '') === 'expense')>Despesa</option></select></div>
        <div class="col-sm-6 col-lg-2"><label for="account_id" class="form-label">Conta</label><select id="account_id" name="account_id" class="form-select"><option value="">Todas</option>@foreach($accounts as $account)<option value="{{ $account['id'] }}" @selected(($filters['account_id'] ?? '') == $account['id'])>{{ $account['name'] }}</option>@endforeach</select></div>
        <div class="col-lg-4"><label for="tags" class="form-label">Tags <span class="text-body-secondary fw-normal">(todas)</span></label><select id="tags" name="tags[]" multiple aria-label="Filtrar transações por tags" class="form-select" data-tom-select data-tom-select-config="{{ $tagFilterConfig }}"><option value=""></option>@foreach($tags as $tag)<option value="{{ $tag['id'] }}" @selected(in_array((string) $tag['id'], array_map('strval', $filters['tags'] ?? []), true))>{{ $tag['name'] }}</option>@endforeach</select></div>
        <div class="col-sm-6 col-lg-2"><label for="from" class="form-label">De</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
        <div class="col-sm-6 col-lg-2"><label for="to" class="form-label">Até</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
        <div class="col-lg-1 d-grid"><button class="btn btn-outline-primary" aria-label="Aplicar filtros"><i class="bi bi-funnel"></i></button></div>
    </form>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Movimentações</h2><span class="badge text-bg-light">{{ $transactions->total() }} resultados</span></div>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Descrição</th><th>Conta</th><th>Data</th><th>Tipo</th><th class="text-end">Valor</th><th><span class="visually-hidden">Ações</span></th></tr></thead><tbody>
    @forelse($transactions as $transaction)
        @php($category = $categories->firstWhere('id', $transaction['category_id']))
        @php($account = $accounts->firstWhere('id', $transaction['account_id']))
        <tr><td><a href="{{ route('transactions.show', $transaction['id']) }}" class="fw-semibold text-body text-decoration-none">{{ $transaction['description'] }}</a><div class="small text-body-secondary"><i class="bi {{ $category['icon'] }} me-1"></i>{{ $category['name'] }}</div>@if($transaction['tag_ids'] ?? [])<div class="d-flex flex-wrap gap-1 mt-2">@foreach($transaction['tag_ids'] as $tagId) @if($tag = $tags->firstWhere('id', $tagId))<span class="badge text-bg-light border fw-normal">#{{ $tag['name'] }}</span>@endif @endforeach</div>@endif</td><td>{{ $account['name'] }}</td><td>{{ \Carbon\Carbon::parse($transaction['date'])->format('d/m/Y') }}</td><td><span class="badge rounded-pill {{ $transaction['type'] === 'income' ? 'text-bg-success-subtle text-success' : 'text-bg-danger-subtle text-danger' }}">{{ $transaction['type'] === 'income' ? 'Receita' : 'Despesa' }}</span></td><td class="text-end fw-semibold {{ $transaction['type'] === 'income' ? 'text-success' : 'text-danger' }}"><x-money :value="($transaction['type'] === 'income' ? 1 : -1) * $transaction['amount']" :signed="true" /></td><td class="text-end"><a href="{{ route('transactions.edit', $transaction['id']) }}" class="btn btn-sm btn-outline-secondary" aria-label="Editar {{ $transaction['description'] }}"><i class="bi bi-pencil"></i></a></td></tr>
    @empty <tr><td colspan="6" class="text-center py-5"><i class="bi bi-search fs-2 text-body-secondary"></i><p class="mt-2 mb-0">Nenhuma transação encontrada.</p></td></tr> @endforelse
    </tbody></table></div>
    @if($transactions->hasPages())<div class="card-footer bg-transparent">{{ $transactions->links() }}</div>@endif
</div>
@endsection
