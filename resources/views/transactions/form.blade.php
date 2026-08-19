@extends('layouts.app')
@php($editing = (bool) $transaction)
@section('title', $editing ? 'Editar transação' : 'Nova transação')
@section('eyebrow', 'TRANSAÇÕES')
@section('page_title', $editing ? 'Editar transação' : 'Nova transação')
@section('page_subtitle', 'Preencha os dados da movimentação. Os valores ficam apenas nesta sessão.')
@section('page_actions')<a href="{{ route('transactions.index') }}" class="btn btn-outline-secondary">Cancelar</a>@endsection

@section('page_content')
<div class="row"><div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-4">
<form method="post" action="{{ $editing ? route('transactions.update', $transaction['id']) : route('transactions.store') }}">
    @csrf @if($editing) @method('put') @endif
    <div class="row g-3">
        <div class="col-12"><label for="description" class="form-label">Descrição</label><input id="description" name="description" value="{{ old('description', $transaction['description'] ?? '') }}" class="form-control @error('description') is-invalid @enderror" maxlength="120" required><x-field-error name="description" /></div>
        <div class="col-md-6"><label for="type" class="form-label">Tipo</label><select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required><option value="expense" @selected(old('type', $transaction['type'] ?? 'expense') === 'expense')>Despesa</option><option value="income" @selected(old('type', $transaction['type'] ?? '') === 'income')>Receita</option></select><x-field-error name="type" /></div>
        <div class="col-md-6"><label for="amount" class="form-label">Valor</label><div class="input-group"><span class="input-group-text">R$</span><input id="amount" name="amount" inputmode="decimal" value="{{ old('amount', isset($transaction) ? number_format($transaction['amount']/100, 2, ',', '') : '') }}" class="form-control @error('amount') is-invalid @enderror" placeholder="0,00" required></div><x-field-error name="amount" /></div>
        <div class="col-md-6"><label for="date" class="form-label">Data</label><input id="date" type="date" name="date" value="{{ old('date', $transaction['date'] ?? now()->format('Y-m-d')) }}" class="form-control @error('date') is-invalid @enderror" required><x-field-error name="date" /></div>
        <div class="col-md-6"><label for="account_id" class="form-label">Conta</label><select id="account_id" name="account_id" class="form-select @error('account_id') is-invalid @enderror" required><option value="">Selecione</option>@foreach($accounts as $account)<option value="{{ $account['id'] }}" @selected(old('account_id', $transaction['account_id'] ?? '') == $account['id'])>{{ $account['name'] }} · {{ $account['institution'] }}</option>@endforeach</select><x-field-error name="account_id" /></div>
        <div class="col-12"><label for="category_id" class="form-label">Categoria</label><select id="category_id" name="category_id" class="form-select @error('category_id') is-invalid @enderror" required><option value="">Selecione</option>@foreach($categories->groupBy('type') as $type => $items)<optgroup label="{{ $type === 'income' ? 'Receitas' : 'Despesas' }}">@foreach($items as $category)<option value="{{ $category['id'] }}" data-type="{{ $category['type'] }}" @selected(old('category_id', $transaction['category_id'] ?? '') == $category['id'])>{{ $category['name'] }}</option>@endforeach</optgroup>@endforeach</select><x-field-error name="category_id" /></div>
        <div class="col-12"><label for="notes" class="form-label">Observações <span class="text-body-secondary fw-normal">(opcional)</span></label><textarea id="notes" name="notes" rows="3" maxlength="500" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $transaction['notes'] ?? '') }}</textarea><x-field-error name="notes" /></div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ route('transactions.index') }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>{{ $editing ? 'Salvar alterações' : 'Adicionar transação' }}</button></div>
</form>
</div></div></div></div>
@endsection
