@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_title', 'Sua vida financeira em um só lugar')
@section('page_subtitle', 'Acompanhe o mês atual e tome decisões com clareza.')
@section('page_actions')
    <a href="{{ route('transactions.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nova transação</a>
@endsection

@section('page_content')
    <div class="row g-3 mb-4">
        @foreach ([
            ['Saldo total', $summary['balance'], 'bi-wallet2', 'primary'],
            ['Receitas do mês', $summary['income'], 'bi-arrow-up-right', 'success'],
            ['Despesas do mês', $summary['expenses'], 'bi-arrow-down-right', 'danger'],
            ['Resultado do mês', $summary['result'], 'bi-graph-up-arrow', $summary['result'] >= 0 ? 'info' : 'warning'],
        ] as [$label, $value, $icon, $theme])
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="metric-card card h-100 border-0 shadow-sm">
                    <div class="card-body d-flex align-items-start justify-content-between">
                        <div><span class="text-body-secondary small">{{ $label }}</span><div class="h4 mt-2 mb-0"><x-money :value="$value" /></div></div>
                        <span class="metric-icon text-bg-{{ $theme }}-subtle text-{{ $theme }}"><i class="bi {{ $icon }}"></i></span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center">
                    <div><h2 class="h5 mb-1">Fluxo de caixa</h2><span class="small text-body-secondary">Receitas e despesas por mês</span></div>
                    <a href="{{ route('reports.index') }}" class="btn btn-sm btn-outline-secondary">Ver relatório</a>
                </div>
                <div class="card-body">
                    <div data-apexchart data-apexchart-config="{{ json_encode($cashFlowChart) }}"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Contas</h2><a href="{{ route('accounts.index') }}" class="small">Ver todas</a></div>
                <div class="card-body pt-2">
                    @foreach ($accounts as $account)
                        <a href="{{ route('accounts.show', $account['id']) }}" class="account-row d-flex align-items-center text-decoration-none py-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                            <span class="account-dot me-3" style="background: {{ $account['color'] }}"></span>
                            <span class="flex-grow-1"><span class="d-block fw-semibold text-body">{{ $account['name'] }}</span><small class="text-body-secondary">{{ $account['institution'] }}</small></span>
                            <x-money :value="$account['balance']" class="fw-semibold text-body" />
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Transações recentes</h2><a href="{{ route('transactions.index') }}" class="small">Ver todas</a></div>
                <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Descrição</th><th>Data</th><th class="text-end">Valor</th></tr></thead><tbody>
                    @foreach ($summary['recent'] as $transaction)
                        <tr><td><a class="text-decoration-none fw-medium text-body" href="{{ route('transactions.show', $transaction['id']) }}">{{ $transaction['description'] }}</a><div class="small text-body-secondary">{{ $categories[$transaction['category_id']]['name'] }}</div></td><td>{{ \Carbon\Carbon::parse($transaction['date'])->format('d/m/Y') }}</td><td class="text-end fw-semibold {{ $transaction['type'] === 'income' ? 'text-success' : 'text-danger' }}"><x-money :value="($transaction['type'] === 'income' ? 1 : -1) * $transaction['amount']" :signed="true" /></td></tr>
                    @endforeach
                </tbody></table></div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Orçamentos do mês</h2><a href="{{ route('budgets.index') }}" class="small">Gerenciar</a></div>
                <div class="card-body">
                    @forelse ($budgets as $budget)
                        @php($percent = min(100, round($budget['spent'] / $budget['limit'] * 100)))
                        <div class="mb-4 {{ $loop->last ? 'mb-0' : '' }}"><div class="d-flex justify-content-between mb-2"><span class="fw-medium">{{ $budget['category']['name'] }}</span><span class="small text-body-secondary"><x-money :value="$budget['spent']" /> de <x-money :value="$budget['limit']" /></span></div><div class="progress" role="progressbar" aria-label="Orçamento de {{ $budget['category']['name'] }}" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar {{ $percent >= 90 ? 'bg-danger' : ($percent >= 70 ? 'bg-warning' : 'bg-primary') }}" style="width: {{ $percent }}%"></div></div></div>
                    @empty <p class="text-body-secondary mb-0">Nenhum orçamento para este mês.</p> @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
