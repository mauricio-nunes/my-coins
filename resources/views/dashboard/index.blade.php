@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_title', 'Sua vida financeira em um só lugar')
@section('page_subtitle', 'Acompanhe o mês atual e tome decisões com clareza.')
@section('page_actions')
    <a href="{{ route('transfers.create') }}" class="btn btn-outline-primary"><i class="bi bi-arrow-left-right me-1"></i> Transferir</a>
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
                    <div><h2 class="h5 mb-1">Fluxo de caixa</h2><span class="small text-body-secondary">Entradas, saídas registradas e faturas previstas por mês</span></div>
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

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header border-0 bg-transparent">
            <h2 id="daily-cash-flow-title" class="h5 mb-1">Fluxo de caixa diário</h2>
            <span class="small text-body-secondary">Movimentações registradas e impacto previsto das faturas no saldo</span>
        </div>
        <div class="card-body">
            <figure class="mb-0" aria-labelledby="daily-cash-flow-title">
                <div data-apexchart data-apexchart-currency="BRL" data-apexchart-config="{{ json_encode($dailyCashFlowChart) }}"></div>
                <figcaption class="visually-hidden">Gráfico diário de entradas, saídas registradas, faturas previstas, saldo em conta e saldo após faturas no mês atual.</figcaption>
            </figure>
            <div class="small text-body-secondary mt-3"><i class="bi bi-info-circle me-1"></i>Faturas previstas são compromissos calculados e não novas despesas. Quando uma fatura é paga, sua previsão é substituída pela saída registrada.</div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header border-0 bg-transparent d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
            <div><h2 class="h5 mb-1">Próximas faturas</h2><span class="small text-body-secondary">Comprometido em cartões: <strong class="text-body"><x-money :value="$cardCommitments['total']" /></strong></span></div>
            <a href="{{ route('credit-cards.index') }}" class="btn btn-sm btn-outline-secondary">Ver cartões</a>
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Cartão</th><th>Fatura</th><th>Vencimento</th><th>Situação</th><th class="text-end">Pendente</th></tr></thead><tbody>
            @forelse ($cardCommitments['items'] as $statement)
                @php
                    $statementMonth = \Carbon\Carbon::createFromFormat('Y-m-d', $statement['month'].'-01');
                @endphp
                <tr><td><a class="fw-semibold text-body text-decoration-none" href="{{ route('credit-cards.show', ['credit_card' => $statement['credit_card_id'], 'statement' => $statement['month']]) }}"><span class="account-dot d-inline-block me-2" style="background: {{ $statement['card_color'] }}"></span>{{ $statement['card_name'] }}</a>@if($statement['card_archived'])<span class="badge text-bg-secondary ms-1">Arquivado</span>@endif</td><td>{{ $statementMonth->translatedFormat('F/Y') }}</td><td>{{ \Carbon\Carbon::parse($statement['due_date'])->format('d/m/Y') }}</td><td>@if($statement['overdue'])<span class="badge text-bg-danger">Vencida</span>@else<span class="badge {{ $statement['status'] === 'partially_paid' ? 'text-bg-info' : ($statement['status'] === 'closed' ? 'text-bg-warning' : 'text-bg-primary') }}">{{ $statement['status_label'] }}</span>@endif</td><td class="text-end fw-semibold"><x-money :value="$statement['outstanding']" /></td></tr>
            @empty
                <tr><td colspan="5" class="text-center py-4 text-body-secondary">Nenhuma fatura pendente.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Próximas transações</h2><a href="{{ route('transactions.index', $monthTransactionFilters) }}" class="small">Ver todas do mês</a></div>
                <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Descrição</th><th>Data</th><th class="text-end">Valor</th></tr></thead><tbody>
                    @forelse ($summary['upcoming'] as $transaction)
                        @php
                            $isTransfer = $transaction['type'] === 'transfer';
                            $source = $isTransfer ? $accountMap[$transaction['source_account_id']] : null;
                            $destination = $isTransfer ? $accountMap[$transaction['destination_account_id']] : null;
                            $description = $transaction['description'] ?: "Transferência de {$source['name']} para {$destination['name']}";
                        @endphp
                        <tr><td><a class="text-decoration-none fw-medium text-body" href="{{ route('transactions.show', $transaction['id']) }}">{{ $description }}</a><div class="small text-body-secondary">{{ $isTransfer ? $source['name'].' → '.$destination['name'] : $categories[$transaction['category_id']]['name'] }}</div></td><td>{{ \Carbon\Carbon::parse($transaction['date'])->format('d/m/Y') }}</td><td class="text-end fw-semibold {{ $transaction['type'] === 'income' ? 'text-success' : ($transaction['type'] === 'expense' ? 'text-danger' : 'text-body') }}">@if($isTransfer)<x-money :value="$transaction['amount']" />@else<x-money :value="($transaction['type'] === 'income' ? 1 : -1) * $transaction['amount']" :signed="true" />@endif</td></tr>
                    @empty <tr><td colspan="3" class="text-center py-4 text-body-secondary">Nenhuma transação futura encontrada.</td></tr> @endforelse
                </tbody></table></div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header border-0 bg-transparent d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Orçamentos do mês</h2><a href="{{ route('budgets.index') }}" class="small">Gerenciar</a></div>
                <div class="card-body">
                    @forelse ($budgets as $budget)
                        @php($theme = ['within' => 'primary', 'attention' => 'warning', 'exceeded' => 'danger'][$budget['status']])
                        <div class="mb-4 {{ $loop->last ? 'mb-0' : '' }}"><div class="d-flex justify-content-between mb-2"><span class="fw-medium text-body">{{ $budget['name'] }}</span><span class="small text-body-secondary"><x-money :value="$budget['spent']" /> de <x-money :value="$budget['limit']" /> · {{ number_format($budget['projection_percentage'], 1, ',', '.') }}%</span></div><div class="progress" role="progressbar" aria-label="Orçamento de {{ $budget['name'] }}" aria-valuenow="{{ $budget['projection_progress'] }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-{{ $theme }}" style="width: {{ $budget['projection_progress'] }}%"></div></div><div class="small text-body-secondary mt-1">{{ $budget['status_label'] }}</div></div>
                    @empty <p class="text-body-secondary mb-0">Nenhum orçamento para este mês.</p> @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
