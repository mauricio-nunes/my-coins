<?php

namespace App\Support;

use App\Models\User;

class DefaultCategories
{
    public static function definitions(): array
    {
        return [
            ['name' => 'Moradia', 'type' => 'expense', 'icon' => 'bi-house-door', 'color' => '#7c3aed'],
            ['name' => 'Alimentação', 'type' => 'expense', 'icon' => 'bi-basket', 'color' => '#ea580c'],
            ['name' => 'Transporte', 'type' => 'expense', 'icon' => 'bi-car-front', 'color' => '#2563eb'],
            ['name' => 'Saúde e cuidados pessoais', 'type' => 'expense', 'icon' => 'bi-heart-pulse', 'color' => '#dc2626'],
            ['name' => 'Educação', 'type' => 'expense', 'icon' => 'bi-mortarboard', 'color' => '#0891b2'],
            ['name' => 'Lazer e compras', 'type' => 'expense', 'icon' => 'bi-bag-heart', 'color' => '#db2777'],
            ['name' => 'Família e pets', 'type' => 'expense', 'icon' => 'bi-people', 'color' => '#c026d3'],
            ['name' => 'Serviços e assinaturas', 'type' => 'expense', 'icon' => 'bi-phone', 'color' => '#4f46e5'],
            ['name' => 'Financeiro', 'type' => 'expense', 'icon' => 'bi-bank', 'color' => '#475569'],
            ['name' => 'Presentes e doações', 'type' => 'expense', 'icon' => 'bi-gift', 'color' => '#e11d48'],
            ['name' => 'Outros', 'type' => 'expense', 'icon' => 'bi-three-dots', 'color' => '#6b7280'],
            ['name' => 'Trabalho', 'type' => 'income', 'icon' => 'bi-briefcase', 'color' => '#16a34a'],
            ['name' => 'Benefícios', 'type' => 'income', 'icon' => 'bi-shield-check', 'color' => '#0d9488'],
            ['name' => 'Investimentos', 'type' => 'income', 'icon' => 'bi-graph-up-arrow', 'color' => '#059669'],
            ['name' => 'Vendas', 'type' => 'income', 'icon' => 'bi-shop', 'color' => '#65a30d'],
            ['name' => 'Reembolsos e devoluções', 'type' => 'income', 'icon' => 'bi-arrow-counterclockwise', 'color' => '#0284c7'],
            ['name' => 'Outras receitas', 'type' => 'income', 'icon' => 'bi-cash-coin', 'color' => '#ca8a04'],
        ];
    }

    public static function iconOptions(): array
    {
        return [
            'bi-house-door' => 'Moradia',
            'bi-basket' => 'Alimentação',
            'bi-car-front' => 'Transporte',
            'bi-heart-pulse' => 'Saúde',
            'bi-mortarboard' => 'Educação',
            'bi-bag-heart' => 'Compras e lazer',
            'bi-people' => 'Família',
            'bi-phone' => 'Celular e serviços',
            'bi-bank' => 'Financeiro',
            'bi-gift' => 'Presentes',
            'bi-three-dots' => 'Outros',
            'bi-briefcase' => 'Trabalho',
            'bi-shield-check' => 'Benefícios',
            'bi-graph-up-arrow' => 'Investimentos',
            'bi-shop' => 'Vendas',
            'bi-arrow-counterclockwise' => 'Reembolsos',
            'bi-cash-coin' => 'Receitas',
            'bi-controller' => 'Jogos e hobbies',
            'bi-laptop' => 'Computador',
            'bi-piggy-bank' => 'Economia',
        ];
    }

    public static function createFor(User $user): void
    {
        $priorities = ['expense' => 0, 'income' => 0];
        foreach (self::definitions() as $category) {
            $type = $category['type'];
            $user->categories()->create($category + ['match_priority' => ++$priorities[$type]]);
        }
    }
}
