@extends('layouts.app')
@php($editing = (bool) $category)
@section('title', $editing ? 'Editar categoria' : 'Nova categoria')
@section('eyebrow', 'CATEGORIAS')
@section('page_title', $editing ? 'Editar categoria' : 'Nova categoria')
@section('page_subtitle', 'Escolha um nome, tipo e identidade visual.')
@section('page_actions')<a href="{{ route('categories.index') }}" class="btn btn-outline-secondary">Cancelar</a>@endsection
@section('page_content')
<div class="row"><div class="col-xl-7"><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="post" action="{{ $editing ? route('categories.update', $category['id']) : route('categories.store') }}">@csrf @if($editing)@method('put')@endif
<div class="row g-3"><div class="col-md-7"><label for="name" class="form-label">Nome</label><input id="name" name="name" value="{{ old('name', $category['name'] ?? '') }}" class="form-control @error('name') is-invalid @enderror" required><x-field-error name="name" /></div><div class="col-md-5"><label for="type" class="form-label">Tipo</label><select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required><option value="expense" @selected(old('type', $category['type'] ?? 'expense')==='expense')>Despesa</option><option value="income" @selected(old('type', $category['type'] ?? '')==='income')>Receita</option></select><x-field-error name="type" /></div>
<div class="col-md-7"><label for="icon" class="form-label">Ícone</label><select id="icon" name="icon" class="form-select">@foreach(['bi-basket'=>'Cesta','bi-house'=>'Casa','bi-car-front'=>'Carro','bi-heart-pulse'=>'Saúde','bi-controller'=>'Lazer','bi-briefcase'=>'Trabalho','bi-laptop'=>'Computador','bi-piggy-bank'=>'Economia'] as $value=>$label)<option value="{{ $value }}" @selected(old('icon', $category['icon'] ?? 'bi-basket')===$value)>{{ $label }}</option>@endforeach</select></div><div class="col-md-5"><label for="color" class="form-label">Cor</label><input id="color" type="color" name="color" value="{{ old('color', $category['color'] ?? '#0f766e') }}" class="form-control form-control-color w-100" required></div></div>
<div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ route('categories.index') }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary">{{ $editing ? 'Salvar alterações' : 'Adicionar categoria' }}</button></div></form></div></div></div></div>
@endsection
