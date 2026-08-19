<?php

use ColorlibHQ\AdminLte\Menu\Filters\ActiveFilter;
use ColorlibHQ\AdminLte\Menu\Filters\GateFilter;
use ColorlibHQ\AdminLte\Menu\Filters\HrefFilter;
use ColorlibHQ\AdminLte\Menu\Filters\SearchFilter;

return [
    'title' => 'My Coins',
    'title_prefix' => '',
    'title_postfix' => ' · Finanças pessoais',
    'logo' => '<b>My</b> Coins',
    'logo_img' => false,
    'logo_img_alt' => 'My Coins',
    'auth_logo' => ['enabled' => false],

    'usermenu_enabled' => true,
    'usermenu_header' => false,
    'usermenu_image' => false,
    'usermenu_profile_url' => false,

    'layout_fixed_sidebar' => true,
    'layout_fixed_navbar' => true,
    'layout_fixed_footer' => false,
    'layout_dark_mode' => null,
    'layout_rtl' => false,
    'sidebar_breakpoint' => 'lg',
    'sidebar_mini' => true,
    'sidebar_collapse' => false,
    'sidebar_collapse_auto_size' => false,
    'sidebar_scrollbar_theme' => 'os-theme-light',
    'sidebar_scrollbar_auto_hide' => 'leave',

    'footer_left' => 'My Coins · Protótipo de finanças pessoais',
    'footer_right' => 'Dados mantidos somente nesta sessão',
    'preloader' => false,
    'control_sidebar' => false,
    'sidebar_docs_url' => false,
    'demo' => false,
    'docs' => false,

    'sidebar_theme' => 'dark',
    'primary_color' => '#0f766e',
    'sidebar_color' => '#102a2b',
    'navbar_color' => null,
    'footer_color' => null,
    'classes_body' => 'my-coins-app',
    'classes_brand' => '',
    'classes_brand_text' => 'fw-semibold',
    'classes_content_wrapper' => '',
    'classes_content_header' => 'pt-4',
    'classes_content' => 'pb-4',
    'classes_sidebar' => 'shadow',
    'classes_sidebar_nav' => '',
    'classes_topnav' => 'navbar-expand bg-body',
    'classes_topnav_nav' => 'navbar',
    'classes_topnav_container' => 'container-fluid',
    'color_mode_toggle' => true,

    'menu' => [
        ['header' => 'VISÃO GERAL'],
        ['text' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'bi bi-grid-1x2-fill'],
        ['header' => 'ORGANIZAÇÃO'],
        ['text' => 'Transações', 'route' => 'transactions.index', 'active' => ['transactions*'], 'icon' => 'bi bi-arrow-left-right'],
        ['text' => 'Importar OFX', 'route' => 'imports.create', 'active' => ['imports*'], 'icon' => 'bi bi-file-earmark-arrow-up'],
        ['text' => 'Transferir', 'route' => 'transfers.create', 'active' => ['transfers*'], 'icon' => 'bi bi-arrow-right-circle'],
        ['text' => 'Contas', 'route' => 'accounts.index', 'active' => ['accounts*'], 'icon' => 'bi bi-wallet2'],
        ['text' => 'Categorias', 'route' => 'categories.index', 'active' => ['categories*'], 'icon' => 'bi bi-tags'],
        ['text' => 'Tags', 'route' => 'tags.index', 'active' => ['tags*'], 'icon' => 'bi bi-hash'],
        ['text' => 'Orçamentos', 'route' => 'budgets.index', 'active' => ['budgets*'], 'icon' => 'bi bi-bullseye'],
        ['header' => 'ANÁLISE'],
        ['text' => 'Relatórios', 'route' => 'reports.index', 'active' => ['reports*'], 'icon' => 'bi bi-bar-chart-line'],
    ],

    'filters' => [GateFilter::class, HrefFilter::class, ActiveFilter::class, SearchFilter::class],
    'plugins' => [],
];
