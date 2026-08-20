<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallMyCoins extends Command
{
    protected $signature = 'mycoins:install {--name= : Nome do proprietário} {--email= : E-mail do proprietário}';

    protected $description = 'Cria o proprietário inicial e as categorias padrão';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->warn('O My Coins já possui um proprietário. Nenhuma credencial foi alterada.');

            return self::SUCCESS;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Nome do proprietário')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('E-mail do proprietário'))));
        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Informe um nome e um endereço de e-mail válido.');

            return self::FAILURE;
        }

        $password = Str::password(20, true, true, true, false);
        DB::transaction(function () use ($email, $name, $password): void {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'must_change_password' => true,
            ]);
            DefaultCategories::createFor($user);
        });

        $this->newLine();
        $this->info('My Coins instalado. Guarde a senha temporária; ela não será exibida novamente.');
        $this->table(['E-mail', 'Senha temporária'], [[$email, $password]]);

        return self::SUCCESS;
    }
}
