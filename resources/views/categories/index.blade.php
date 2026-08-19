@extends('layouts.app')
@section('title', 'Categorias')
@section('eyebrow', 'ORGANIZAÇÃO')
@section('page_title', 'Categorias')
@section('page_subtitle', 'Crie uma estrutura simples para entender para onde vai seu dinheiro.')
@section('page_actions')<a href="{{ route('categories.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nova categoria</a>@endsection
@section('page_content')
<div class="row g-4">
@foreach(['expense'=>'Despesas','income'=>'Receitas'] as $type=>$title)
<div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-header border-0 bg-transparent"><h2 class="h5 mb-0">{{ $title }}</h2></div><div class="list-group list-group-flush">
@forelse($categories->get($type, collect()) as $category)
<div class="list-group-item d-flex align-items-center py-3"><span class="category-icon me-3" style="--category-color: {{ $category['color'] }}"><i class="bi {{ $category['icon'] }}"></i></span><span class="flex-grow-1 fw-medium">{{ $category['name'] }}</span><a href="{{ route('categories.edit', $category['id']) }}" class="btn btn-sm btn-outline-secondary" aria-label="Editar {{ $category['name'] }}"><i class="bi bi-pencil"></i></a><form action="{{ route('categories.destroy', $category['id']) }}" method="post" class="ms-2" data-confirm="Excluir esta categoria?">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger" aria-label="Excluir {{ $category['name'] }}"><i class="bi bi-trash"></i></button></form></div>
@empty<div class="p-4 text-body-secondary">Nenhuma categoria.</div>@endforelse
</div></div></div>
@endforeach
</div>
@endsection
