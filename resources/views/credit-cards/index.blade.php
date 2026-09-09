@extends('layouts.app')
@section('title', 'Cartões de crédito')
@section('eyebrow', 'ORGANIZAÇÃO')
@section('page_title', 'Cartões de crédito')
@section('page_subtitle', 'Acompanhe limites, faturas e compras parceladas.')
@section('page_actions')<a href="{{ route('credit-cards.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Novo cartão</a>@endsection
@section('page_content')
<div class="row g-4">
@forelse($cards as $card)
    <div class="col-md-6 col-xl-4"><div class="card border-0 shadow-sm h-100 {{ $card['archived'] ? 'opacity-75' : '' }}"><div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start mb-3"><div><span class="d-inline-block rounded-circle me-2" style="width:.75rem;height:.75rem;background:{{ $card['color'] }}"></span><a class="h5 text-body text-decoration-none" href="{{ route('credit-cards.show', $card['id']) }}">{{ $card['name'] }}</a><div class="small text-body-secondary">{{ $card['network'] }}</div></div>@if($card['archived'])<span class="badge text-bg-secondary">Arquivado</span>@else<span class="badge text-bg-primary-subtle text-primary">{{ $card['statement']['status_label'] }}</span>@endif</div>
        <div class="row g-3 mb-3"><div class="col-6"><div class="small text-body-secondary">Limite total</div><strong><x-money :value="$card['credit_limit']" /></strong></div><div class="col-6"><div class="small text-body-secondary">Disponível</div><strong class="text-success"><x-money :value="$card['available_limit']" /></strong></div><div class="col-6"><div class="small text-body-secondary">Limite usado</div><strong><x-money :value="$card['used_limit']" /></strong></div><div class="col-6"><div class="small text-body-secondary">Fatura atual</div><strong><x-money :value="$card['statement']['total']" /></strong></div></div>
        <div class="progress mb-3" role="progressbar" aria-label="Limite utilizado"><div class="progress-bar" style="width:{{ min(100, $card['credit_limit'] ? ($card['used_limit'] / $card['credit_limit']) * 100 : 0) }}%;background:{{ $card['color'] }}"></div></div>
        <div class="small text-body-secondary">Fecha em {{ \Carbon\Carbon::parse($card['statement']['closing_date'])->format('d/m') }} · vence em {{ \Carbon\Carbon::parse($card['statement']['due_date'])->format('d/m') }}</div>
    </div><div class="card-footer bg-transparent border-0 px-4 pb-4"><a href="{{ route('credit-cards.show', $card['id']) }}" class="btn btn-outline-primary w-100">Ver fatura</a></div></div></div>
@empty
    <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center py-5"><i class="bi bi-credit-card-2-front fs-1 text-body-secondary"></i><h2 class="h5 mt-3">Nenhum cartão cadastrado</h2><p class="text-body-secondary">Cadastre um cartão para controlar compras, parcelas, faturas e limite.</p><a href="{{ route('credit-cards.create') }}" class="btn btn-primary">Cadastrar cartão</a></div></div></div>
@endforelse
</div>
@endsection
