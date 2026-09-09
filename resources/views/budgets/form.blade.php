@extends('layouts.app')
@php($editing = (bool) $budget)
@php($selectedCategories = array_map('intval', old('category_ids', $budget['category_ids'] ?? [])))
@section('title', $editing ? 'Editar orçamento' : 'Novo orçamento')
@section('eyebrow', 'ORÇAMENTOS')
@section('page_title', $editing ? 'Editar orçamento' : 'Novo orçamento')
@section('page_subtitle', 'Agrupe categorias de despesa sob um único limite mensal.')
@section('page_actions')<a href="{{ route('budgets.index', ['month' => old('month', $budget['month'] ?? $month)]) }}" class="btn btn-outline-secondary">Cancelar</a>@endsection
@section('page_content')
<div class="row"><div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-4">
<form method="post" action="{{ $editing ? route('budgets.update', $budget['id']) : route('budgets.store') }}">@csrf @if($editing)@method('put')@endif
    <div class="row g-3">
        <div class="col-md-7"><label for="name" class="form-label">Nome</label><input id="name" name="name" maxlength="100" value="{{ old('name', $budget['name'] ?? '') }}" class="form-control @error('name') is-invalid @enderror" required><x-field-error name="name" /></div>
        <div class="col-md-5"><label for="month" class="form-label">Mês e ano</label><input id="month" type="month" name="month" value="{{ old('month', $budget['month'] ?? $month) }}" class="form-control @error('month') is-invalid @enderror" required><x-field-error name="month" /></div>
        <div class="col-12"><label for="category_ids" class="form-label">Categorias</label><select id="category_ids" name="category_ids[]" multiple class="form-select @error('category_ids') is-invalid @enderror @error('category_ids.*') is-invalid @enderror" data-tom-select data-tom-select-config='{"plugins":{"remove_button":{}},"placeholder":"Selecione uma ou mais categorias"}' required>@foreach($categories as $category)<option value="{{ $category['id'] }}" @selected(in_array($category['id'], $selectedCategories, true))>{{ $category['name'] }}</option>@endforeach</select><div class="form-text">O limite será compartilhado por todas as categorias selecionadas.</div><x-field-error name="category_ids" />@error('category_ids.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror</div>
        <div class="col-md-5"><label for="limit" class="form-label">Limite</label><div class="input-group"><span class="input-group-text">R$</span><input id="limit" name="limit" inputmode="decimal" value="{{ old('limit', $budget ? number_format($budget['limit']/100, 2, ',', '') : '') }}" class="form-control @error('limit') is-invalid @enderror" required></div><x-field-error name="limit" /></div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ route('budgets.index', ['month' => old('month', $budget['month'] ?? $month)]) }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary">{{ $editing ? 'Salvar alterações' : 'Criar orçamento' }}</button></div>
</form>
</div></div></div></div>
@endsection
