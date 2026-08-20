<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (env('PLAYWRIGHT_TEST')) {
            $this->call(BrowserTestSeeder::class);
        }

        // Production owners are created explicitly by `php artisan mycoins:install`.
    }
}
