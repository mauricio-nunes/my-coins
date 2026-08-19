@extends('layouts.app')
@section('title', 'Orçamentos')
@section('eyebrow', 'PLANEJAMENTO')
@section('page_title', 'Orçamentos mensais')
@section('page_subtitle', 'Defina limites e acompanhe o ritmo dos seus gastos.')
@section('page_actions')<a href="{{ route('budgets.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Novo orçamento</a>@endsection
@section('page_content')
<div class="card border-0 shadow-sm mb-4"><div class="card-body"><form method="get" class="d-flex flex-column flex-sm-row align-items-sm-end gap-3"><div><label for="month" class="form-label">Mês de referência</label><input id="month" type="month" name="month" value="{{ $month }}" class="form-control"></div><button class="btn btn-outline-primary">Atualizar visão</button></form></div></div>
<div class="row g-4">
@forelse($budgets as $budget)
@php($percentRaw = round($budget['spent']/$budget['limit']*100)) @php($percent = min(100,$percentRaw))
<div class="col-md-6 col-xl-4"><div class="card budget-card border-0 shadow-sm h-100"><div class="card-body p-4"><div class="d-flex align-items-center justify-content-between mb-4"><span class="category-icon" style="--category-color: {{ $budget['category']['color'] }}"><i class="bi {{ $budget['category']['icon'] }}"></i></span><div><a href="{{ route('budgets.edit',$budget['id']) }}" class="btn btn-sm btn-light" aria-label="Editar orçamento"><i class="bi bi-pencil"></i></a></div></div><h2 class="h5">{{ $budget['category']['name'] }}</h2><div class="d-flex justify-content-between small text-body-secondary mt-4 mb-2"><span><x-money :value="$budget['spent']" /> gastos</span><span>{{ $percentRaw }}%</span></div><div class="progress mb-3" role="progressbar" aria-label="Orçamento de {{ $budget['category']['name'] }}" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar {{ $percentRaw>=100?'bg-danger':($percentRaw>=80?'bg-warning':'bg-primary') }}" style="width:{{ $percent }}%"></div></div><div class="d-flex justify-content-between"><span class="small text-body-secondary">Limite</span><x-money :value="$budget['limit']" class="fw-semibold" /></div>@if($percentRaw>=100)<div class="alert alert-danger py-2 px-3 mt-3 mb-0 small"><i class="bi bi-exclamation-circle me-1"></i>Limite atingido</div>@endif</div></div></div>
@empty<div class="col-12"><div class="empty-state card border-0 shadow-sm text-center p-5"><i class="bi bi-bullseye display-5 text-body-secondary"></i><h2 class="h5 mt-3">Nenhum orçamento neste mês</h2><p class="text-body-secondary">Crie limites por categoria para acompanhar seus gastos.</p><div><a href="{{ route('budgets.create') }}" class="btn btn-primary">Criar orçamento</a></div></div></div>@endforelse
</div>
@endsection
