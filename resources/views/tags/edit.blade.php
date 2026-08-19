@extends('layouts.app')
@section('title', 'Renomear tag')
@section('eyebrow', 'TAGS')
@section('page_title', 'Renomear tag')
@section('page_subtitle', 'O novo nome será exibido em todas as transações associadas.')
@section('page_actions')<a href="{{ route('tags.index') }}" class="btn btn-outline-secondary">Cancelar</a>@endsection
@section('page_content')
<div class="row"><div class="col-xl-7"><div class="card border-0 shadow-sm"><div class="card-body p-4"><form method="post" action="{{ route('tags.update', $tag['id']) }}">@csrf @method('put')<label for="name" class="form-label">Nome</label><div class="input-group"><span class="input-group-text">#</span><input id="name" name="name" value="{{ old('name', $tag['name']) }}" maxlength="30" class="form-control @error('name') is-invalid @enderror" required></div><x-field-error name="name" /><div class="d-flex justify-content-end gap-2 mt-4"><a href="{{ route('tags.index') }}" class="btn btn-light">Cancelar</a><button class="btn btn-primary" type="submit">Salvar alterações</button></div></form></div></div></div></div>
@endsection
