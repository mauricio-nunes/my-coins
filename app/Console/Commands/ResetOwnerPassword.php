<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetOwnerPassword extends Command
{
    protected $signature = 'mycoins:reset-password';

    protected $description = 'Gera uma senha temporária para o proprietário e revoga suas sessões';

    public function handle(): int
    {
        $user = User::query()->first();
        if (! $user) {
            $this->error('Nenhum proprietário encontrado. Execute mycoins:install primeiro.');

            return self::FAILURE;
        }

        $password = Str::password(20, true, true, true, false);
        DB::transaction(function () use ($password, $user): void {
            $user->update(['password' => $password, 'must_change_password' => true, 'password_changed_at' => null]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        $this->warn('Senha redefinida. O proprietário deverá alterá-la no próximo login.');
        $this->table(['E-mail', 'Senha temporária'], [[$user->email, $password]]);

        return self::SUCCESS;
    }
}
