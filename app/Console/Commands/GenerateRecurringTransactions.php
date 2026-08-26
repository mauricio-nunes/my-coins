<?php

namespace App\Console\Commands;

use App\Services\RecurringTransactionService;
use Illuminate\Console\Command;

class GenerateRecurringTransactions extends Command
{
    protected $signature = 'mycoins:generate-recurrences';

    protected $description = 'Gera as próximas ocorrências das transações recorrentes';

    public function handle(RecurringTransactionService $recurrences): int
    {
        $created = $recurrences->generateAll();
        $this->info("{$created} ocorrência(s) gerada(s).");

        return self::SUCCESS;
    }
}
