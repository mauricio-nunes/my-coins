@extends('layouts.app')
@php($editing = (bool) $account)
@section('title', $editing ? 'Editar conta' : 'Nova conta')
@section('eyebrow', 'CONTAS')
@section('page_title', $editing ? 'Editar conta' : 'Nova conta')
@section('page_subtitle', 'Organize bancos, investimentos e dinheiro em espécie.')
@section('page_actions')<a href="{{ route('accounts.index') }}" class="btn btn-outline-secondary">Cancelar</a>@endsection
@section('page_content')
<div class="row"><div class="col-xl-7"><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="post" action="{{ $editing ? route('accounts.update', $account['id']) : route('accounts.store') }}">@csrf @if($editing)@method('put')@endif
<div class="row g-3"><div class="col-md-6"><label for="name" class="form-label">Nome da conta</label><input id="name" name="name" value="{{ old('name', $account['name'] ?? '') }}" class="form-control @error('name') is-invalid @enderror" required><x-field-error name="name" /></div><div class="col-md-6"><label for="institution" class="form-label">Instituição</label><input id="institution" name="institution" value="{{ old('institution', $account['institution'] ?? '') }}" class="form-control @error('institution') is-invalid @enderror" required><x-field-error name="institution" /></div>
<div class="col-md-6"><label for="type" class="form-label">Tipo</label><select id="type" name="type" class="form-select" required>@foreach(['checking'=>'Conta corrente','savings'=>'Poupança','investment'=>'Investimento','cash'=>'Dinheiro'] as $value=>$label)<option value="{{ $value }}" @selected(old('type', $account['type'] ?? 'checking') === $value)>{{ $label }}</option>@endforeach</select></div><div class="col-md-6"><label for="opening_balance" class="form-label">Saldo inicial</label><div class="input-group"><span class="input-group-text">R$</span><input id="opening_balance" name="opening_balance" value="{{ old('opening_balance', $account ? number_format($account['opening_balance']/100, 2, ',', '') : '0,00') }}" class="form-control @error('opening_balance') is-invalid @enderror" required></div><x-field-error name="opening_balance" /></div>
<div class="col-md-6"><label for="color" class="form-label">Cor de identificação</label><input id="color" type="color" name="color" value="{{ old('color', $account['color'] ?? '#0f766e') }}" class="form-control form-control-color w-100" required></div></div>
<div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ route('accounts.index') }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary" type="submit">{{ $editing ? 'Salvar alterações' : 'Adicionar conta' }}</button></div></form></div></div></div></div>
@endsection
