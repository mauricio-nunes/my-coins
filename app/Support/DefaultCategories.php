<?php

namespace App\Support;

use App\Models\User;

class DefaultCategories
{
    public static function createFor(User $user): void
    {
        $categories = [
            ['name' => 'Salário', 'type' => 'income', 'icon' => 'bi-briefcase', 'color' => '#16a34a'],
            ['name' => 'Freelance', 'type' => 'income', 'icon' => 'bi-laptop', 'color' => '#0891b2'],
            ['name' => 'Moradia', 'type' => 'expense', 'icon' => 'bi-house', 'color' => '#7c3aed'],
            ['name' => 'Alimentação', 'type' => 'expense', 'icon' => 'bi-basket', 'color' => '#ea580c'],
            ['name' => 'Transporte', 'type' => 'expense', 'icon' => 'bi-car-front', 'color' => '#2563eb'],
            ['name' => 'Lazer', 'type' => 'expense', 'icon' => 'bi-controller', 'color' => '#db2777'],
            ['name' => 'Saúde', 'type' => 'expense', 'icon' => 'bi-heart-pulse', 'color' => '#dc2626'],
        ];

        foreach ($categories as $category) {
            $user->categories()->create($category);
        }
    }
}
