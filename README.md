# My Coins

Aplicação de finanças pessoais em Laravel 13 e AdminLTE 4, com autenticação de proprietário único e persistência em MySQL 8.

## O que está incluído

- Dashboard com saldo, receitas, despesas, fluxo de caixa e progresso dos orçamentos.
- Fluxos de transações, transferências, contas, categorias, tags e orçamentos.
- Transações recorrentes semanais, mensais e anuais, com projeção automática de 12 meses.
- Importação OFX em três etapas, com classificação, transferências e detecção de duplicidade.
- Relatórios filtráveis, gráficos e detalhamento por categoria.
- Login protegido, troca obrigatória da senha inicial e recuperação pela CLI.
- PHPUnit, Playwright desktop/mobile e verificações de acessibilidade com axe.

## Executar com Docker

Pré-requisitos: Docker e `docker-compose` (ou Docker Compose moderno).

```bash
make setup INSTALL_ARGS="--name='Seu nome' --email=voce@exemplo.com"
make up
```

`make setup` exibe uma senha temporária uma única vez. Entre com o e-mail informado e altere essa senha no primeiro acesso. Sem `INSTALL_ARGS`, o instalador solicita nome e e-mail de forma interativa.

Acesse `http://localhost:8000`. O Vite usa a porta `5173` e o MySQL é exposto em `3307` para evitar conflito com instalações locais.

Se npm/Vite retornar `EACCES` em `node_modules` ou `public/build`, execute `make fix-permissions`. Para Docker Compose v2, acrescente `COMPOSE="docker compose"` aos comandos.

Os alvos `make up` e `make debug` recriam somente os containers descartáveis `app`, `vite` e `scheduler`, evitando o erro `KeyError: 'ContainerConfig'` do Compose 1.29. Dados e sessões permanecem no volume MySQL.

O serviço `scheduler` acompanha a aplicação e executa diariamente a geração idempotente das próximas recorrências. Para executar manualmente:

```bash
docker-compose run --rm app php artisan mycoins:generate-recurrences
```

Para gerar uma nova senha temporária e revogar sessões existentes:

```bash
docker-compose run --rm app php artisan mycoins:reset-password
```

## Qualidade e debug

```bash
make test       # PHPUnit em my_coins_testing
make e2e        # Playwright + axe em my_coins_e2e
make build      # assets de produção do Vite
make format     # Laravel Pint
make debug      # stack com Xdebug na porta 9003
```

No VS Code, use a configuração **Listen for My Coins Xdebug** antes de `make debug`. O servidor mapeia `/var/www/html` para a raiz do projeto.

Para executar sem Docker, use PHP 8.3, Composer, MySQL 8 e Node 22. Copie `.env.example` para `.env`, ajuste a conexão e execute `composer install`, `php artisan key:generate`, `php artisan migrate`, `php artisan mycoins:install`, `npm install`, `npm run build` e `php artisan serve`.
