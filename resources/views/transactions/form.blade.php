@extends('layouts.app')
@php
    $editing = (bool) $transaction;
    $selectedTags = old('tags', collect($transaction['tag_ids'] ?? [])
        ->map(function ($id) use ($tags) {
            return $tags->firstWhere('id', $id)['name'] ?? null;
        })
        ->filter()
        ->values()
        ->all());
    $tagSelectConfig = json_encode([
        'create' => true,
        'persist' => false,
        'maxItems' => 10,
        'plugins' => ['remove_button'],
        'placeholder' => 'Selecione ou digite para criar...',
        'items' => $selectedTags,
    ]);
@endphp
@section('title', $editing ? 'Editar transação' : 'Nova transação')
@section('eyebrow', 'TRANSAÇÕES')
@section('page_title', $editing ? 'Editar transação' : 'Nova transação')
@section('page_subtitle', 'Preencha os dados da movimentação financeira.')
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
        <div class="col-12"><label for="tags" class="form-label">Tags <span class="text-body-secondary fw-normal">(opcional)</span></label><select id="tags" name="tags[]" multiple aria-label="Tags da transação" class="form-select @error('tags') is-invalid @enderror @error('tags.*') is-invalid @enderror" data-tom-select data-tom-select-config="{{ $tagSelectConfig }}"><option value=""></option>@foreach($tags as $tag)<option value="{{ $tag['name'] }}" @selected(in_array($tag['name'], $selectedTags, true))>{{ $tag['name'] }}</option>@endforeach @foreach($selectedTags as $selectedTag) @if(!$tags->contains('name', $selectedTag))<option value="{{ $selectedTag }}" selected>{{ $selectedTag }}</option>@endif @endforeach</select><div class="form-text">Use até 10 tags. Digite um nome e pressione Enter para criar.</div><x-field-error name="tags" />@error('tags.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
        <div class="col-12"><label for="notes" class="form-label">Observações <span class="text-body-secondary fw-normal">(opcional)</span></label><textarea id="notes" name="notes" rows="3" maxlength="500" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $transaction['notes'] ?? '') }}</textarea><x-field-error name="notes" /></div>
        @if(!$editing)
            <div class="col-12"><div class="form-check form-switch"><input id="recurring" name="recurring" value="1" type="checkbox" class="form-check-input" @checked(old('recurring')) data-recurrence-toggle><label for="recurring" class="form-check-label fw-semibold">Repetir transação</label></div><div class="form-text">As próximas ocorrências serão criadas automaticamente para os próximos 12 meses.</div></div>
            <div class="col-12 {{ old('recurring') ? '' : 'd-none' }}" data-recurrence-fields>
                <div class="row g-3 rounded-3 border bg-body-tertiary p-3">
                    <div class="col-md-6"><label for="frequency" class="form-label">Frequência</label><select id="frequency" name="frequency" class="form-select @error('frequency') is-invalid @enderror" @disabled(!old('recurring')) data-recurrence-input><option value="weekly" @selected(old('frequency', 'monthly') === 'weekly')>Semanal</option><option value="monthly" @selected(old('frequency', 'monthly') === 'monthly')>Mensal</option><option value="yearly" @selected(old('frequency', 'monthly') === 'yearly')>Anual</option></select><x-field-error name="frequency" /></div>
                    <div class="col-md-6"><label for="recurrence_end_date" class="form-label">Data final <span class="text-body-secondary fw-normal">(opcional)</span></label><input id="recurrence_end_date" name="recurrence_end_date" type="date" value="{{ old('recurrence_end_date') }}" class="form-control @error('recurrence_end_date') is-invalid @enderror" @disabled(!old('recurring')) data-recurrence-input><x-field-error name="recurrence_end_date" /></div>
                    <div class="col-12"><div class="form-text">Em meses mais curtos, ocorrências dos dias 29, 30 ou 31 serão agendadas no último dia disponível.</div></div>
                </div>
            </div>
        @elseif($recurrence)
            <div class="col-12"><div class="rounded-3 border bg-body-tertiary p-3"><h2 class="h6">Alteração de recorrência</h2><p class="small text-body-secondary">Escolha se a alteração vale somente para este lançamento ou também para os próximos.</p><div class="row g-3">
                <div class="col-12"><label for="recurrence_scope" class="form-label">Aplicar alteração</label><select id="recurrence_scope" name="recurrence_scope" class="form-select @error('recurrence_scope') is-invalid @enderror"><option value="single" @selected(old('recurrence_scope', 'single') === 'single')>Somente esta ocorrência</option><option value="future" @selected(old('recurrence_scope') === 'future')>Esta ocorrência e as próximas</option></select><x-field-error name="recurrence_scope" /></div>
                <div class="col-md-6"><label for="frequency" class="form-label">Frequência das próximas</label><select id="frequency" name="frequency" class="form-select @error('frequency') is-invalid @enderror"><option value="weekly" @selected(old('frequency', $recurrence->frequency) === 'weekly')>Semanal</option><option value="monthly" @selected(old('frequency', $recurrence->frequency) === 'monthly')>Mensal</option><option value="yearly" @selected(old('frequency', $recurrence->frequency) === 'yearly')>Anual</option></select><x-field-error name="frequency" /></div>
                <div class="col-md-6"><label for="recurrence_end_date" class="form-label">Data final <span class="text-body-secondary fw-normal">(opcional)</span></label><input id="recurrence_end_date" name="recurrence_end_date" type="date" value="{{ old('recurrence_end_date', $recurrence->end_date?->format('Y-m-d')) }}" class="form-control @error('recurrence_end_date') is-invalid @enderror"><x-field-error name="recurrence_end_date" /></div>
            </div></div></div>
        @endif
    </div>
    <div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ route('transactions.index') }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>{{ $editing ? 'Salvar alterações' : 'Adicionar transação' }}</button></div>
</form>
</div></div></div></div>
@endsection
