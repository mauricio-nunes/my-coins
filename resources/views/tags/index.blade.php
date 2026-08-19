@extends('layouts.app')
@section('title', 'Tags')
@section('eyebrow', 'ORGANIZAÇÃO')
@section('page_title', 'Tags')
@section('page_subtitle', 'Crie marcadores flexíveis para agrupar e encontrar suas transações.')

@section('page_content')
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 bg-transparent"><h2 class="h5 mb-0">Nova tag</h2></div>
            <div class="card-body pt-1">
                <form method="post" action="{{ route('tags.store') }}">
                    @csrf
                    <label for="name" class="form-label">Nome</label>
                    <div class="input-group"><span class="input-group-text">#</span><input id="name" name="name" value="{{ old('name') }}" maxlength="30" class="form-control @error('name') is-invalid @enderror" placeholder="Ex.: Viagem" required><button class="btn btn-primary" type="submit">Adicionar</button></div>
                    <x-field-error name="name" />
                    <div class="form-text">Tags também podem ser criadas ao adicionar uma transação.</div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card border-0 shadow-sm mb-4"><div class="card-body"><form method="get" class="row g-3 align-items-end"><div class="col-sm-7"><label for="search" class="form-label">Buscar</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Nome da tag"></div><div class="col-sm-3"><label for="usage" class="form-label">Uso</label><select id="usage" name="usage" class="form-select"><option value="">Todas</option><option value="used" @selected(($filters['usage'] ?? '') === 'used')>Em uso</option><option value="unused" @selected(($filters['usage'] ?? '') === 'unused')>Sem uso</option></select></div><div class="col-sm-2 d-grid"><button class="btn btn-outline-primary">Filtrar</button></div></form></div></div>
        <div class="card border-0 shadow-sm">
            <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Tags cadastradas</h2><span class="badge text-bg-light">{{ $tags->count() }} resultados</span></div>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Tag</th><th>Uso</th><th class="text-end"><span class="visually-hidden">Ações</span></th></tr></thead><tbody>
                @forelse($tags as $tag)
                    <tr><td><span class="badge text-bg-light border fw-normal fs-6">#{{ $tag['name'] }}</span></td><td>{{ trans_choice(':count transação|:count transações', $tag['usage_count'], ['count' => $tag['usage_count']]) }}</td><td class="text-end"><a href="{{ route('tags.edit', $tag['id']) }}" class="btn btn-sm btn-outline-secondary" aria-label="Renomear {{ $tag['name'] }}"><i class="bi bi-pencil"></i></a><form action="{{ route('tags.destroy', $tag['id']) }}" method="post" class="d-inline-block ms-1" data-confirm="Excluir a tag #{{ $tag['name'] }}? Ela será removida de {{ $tag['usage_count'] }} transação(ões).">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger" aria-label="Excluir {{ $tag['name'] }}"><i class="bi bi-trash"></i></button></form></td></tr>
                @empty
                    <tr><td colspan="3" class="text-center py-5"><i class="bi bi-hash fs-2 text-body-secondary"></i><p class="mt-2 mb-0">Nenhuma tag encontrada.</p></td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
</div>
@endsection
