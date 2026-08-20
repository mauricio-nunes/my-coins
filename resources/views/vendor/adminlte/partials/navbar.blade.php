<nav class="app-header {{ config('adminlte.classes_topnav', 'navbar-expand bg-body') }} navbar">
    <div class="{{ config('adminlte.classes_topnav_container', 'container-fluid') }}">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Alternar menu lateral">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-flex align-items-center text-body-secondary small ms-2"><i class="bi bi-shield-check me-2 text-success" aria-hidden="true"></i> Ambiente protegido</li>
        </ul>

        <ul class="navbar-nav ms-auto">
            <li class="nav-item">
                <a class="nav-link" href="#" data-lte-toggle="fullscreen" aria-label="Alternar tela cheia">
                    <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen" aria-hidden="true"></i>
                    <i data-lte-icon="minimize" class="bi bi-fullscreen-exit d-none" aria-hidden="true"></i>
                </a>
            </li>
            @if (config('adminlte.color_mode_toggle', true))
                @include('adminlte::partials.color-mode')
            @endif
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Abrir menu do usuário">
                    <span class="user-avatar"><i class="bi bi-person" aria-hidden="true"></i></span>
                    <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-body-secondary">{{ auth()->user()->email }}</span></li>
                    <li><a class="dropdown-item" href="{{ route('password.edit') }}"><i class="bi bi-key me-2"></i>Alterar senha</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form action="{{ route('logout') }}" method="post">@csrf
                            <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sair</button>
                        </form>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</nav>
