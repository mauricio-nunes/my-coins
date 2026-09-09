@extends('layouts.app')

@section('title', 'Orçamentos')
@section('eyebrow', 'PLANEJAMENTO')
@section('page_title', 'Monitoramento de orçamentos')
@section('page_subtitle', 'Compare limites, gastos e tendências do período selecionado.')
@section('page_actions')
    <a href="{{ route('budgets.create', ['month' => $month]) }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Novo orçamento</a>
@endsection

@section('page_content')
    @php
        $statusStyles = ['within' => 'success', 'attention' => 'warning', 'exceeded' => 'danger'];
    @endphp

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="{{ route('budgets.index') }}" class="row g-3 align-items-end" data-period-form>
                <div class="col-sm-6 col-lg-3"><label for="month" class="form-label">Período de visualização</label><input id="month" type="month" name="month" value="{{ $month }}" class="form-control" data-auto-submit required></div>
                <div class="col-auto"><button class="btn btn-outline-primary">Atualizar</button></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        @if($budgets->isEmpty())
            <div class="empty-state text-center p-5"><i class="bi bi-bullseye display-5 text-body-secondary"></i><h2 class="h5 mt-3">Não há orçamentos criados para {{ $periodLabel }}.</h2><p class="text-body-secondary">Escolha outro período ou crie o primeiro planejamento deste mês.</p><a href="{{ route('budgets.create', ['month' => $month]) }}" class="btn btn-primary">Novo orçamento</a></div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Orçamento</th><th>Valores</th><th style="min-width: 180px">Consumido</th><th>Projeção</th><th>Status</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    @foreach($budgets as $budget)
                        @php($theme = $statusStyles[$budget['status']])
                        <tr>
                            <td><span class="fw-semibold text-body">{{ $budget['name'] }}</span><div class="small text-body-secondary mt-1">{{ collect($budget['categories'])->pluck('name')->join(', ') }}</div></td>
                            <td><div><span class="text-body-secondary small">Limite:</span> <x-money :value="$budget['limit']" /></div><div><span class="text-body-secondary small">Gasto:</span> <x-money :value="$budget['spent']" /></div><div class="{{ $budget['remaining'] < 0 ? 'text-danger' : '' }}"><span class="text-body-secondary small">Restante:</span> <x-money :value="$budget['remaining']" /></div></td>
                            <td><div class="d-flex justify-content-between small mb-2"><span>Utilizado</span><strong>{{ number_format($budget['percentage'], 1, ',', '.') }}%</strong></div><div class="progress" role="progressbar" aria-label="Consumo de {{ $budget['name'] }}" aria-valuenow="{{ $budget['progress'] }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-{{ $theme }}" style="width: {{ $budget['progress'] }}%"></div></div></td>
                            <td><x-money :value="$budget['projection']" /><div class="small text-body-secondary">total registrado no mês</div></td>
                            <td><span class="badge text-bg-{{ $theme }}">{{ $budget['status_label'] }}</span></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('budgets.edit', $budget['id']) }}" class="btn btn-sm btn-outline-secondary" aria-label="Editar {{ $budget['name'] }}"><i class="bi bi-pencil"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-budget-copy data-budget-id="{{ $budget['id'] }}" data-budget-name="{{ $budget['name'] }}" data-budget-copy-url="{{ route('budgets.copy', $budget['id']) }}" data-next-month="{{ \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $budget['month'].'-01')->addMonth()->format('Y-m') }}" aria-label="Copiar {{ $budget['name'] }}"><i class="bi bi-copy"></i></button>
                                <form action="{{ route('budgets.destroy', $budget['id']) }}" method="post" class="d-inline" data-confirm="Excluir o orçamento {{ $budget['name'] }}? Categorias e transações serão preservadas.">@csrf @method('delete')<input type="hidden" name="month" value="{{ $month }}"><button class="btn btn-sm btn-outline-danger" aria-label="Excluir {{ $budget['name'] }}"><i class="bi bi-trash"></i></button></form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="modal fade" id="copyBudgetModal" tabindex="-1" aria-labelledby="copyBudgetTitle" aria-hidden="true" @if($errors->has('destination_month')) data-open-on-load @endif>
        <div class="modal-dialog"><div class="modal-content"><form method="post" data-budget-copy-form>@csrf<input type="hidden" name="source_budget_id" value="{{ old('source_budget_id') }}"><div class="modal-header"><h2 class="modal-title fs-5" id="copyBudgetTitle">Copiar orçamento</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div><div class="modal-body"><p>Copiar <strong data-budget-copy-name></strong> para outro período.</p><label for="destination_month" class="form-label">Mês de destino</label><input id="destination_month" type="month" name="destination_month" value="{{ old('destination_month') }}" class="form-control @error('destination_month') is-invalid @enderror" required><x-field-error name="destination_month" /></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Copiar orçamento</button></div></form></div></div>
    </div>
@endsection
