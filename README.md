# My Coins

Protótipo navegável de uma aplicação de finanças pessoais em Laravel 13 e AdminLTE 4. Os dados são fictícios, ficam na sessão do navegador e podem ser restaurados pelo menu superior.

## O que está incluído

- Dashboard com saldo, receitas, despesas, fluxo de caixa e progresso dos orçamentos.
- Fluxos de transações, contas, categorias e orçamentos com validação em português.
- Relatórios filtráveis com gráficos e detalhamento por categoria.
- Login demonstrativo, tema claro/escuro e layouts responsivos.
- PHPUnit, Playwright e verificações de acessibilidade com axe.
- Docker, Vite e Xdebug configurados para desenvolvimento local.

## Executar com Docker

Pré-requisitos: Docker e `docker-compose` (ou Docker Compose moderno).

```bash
make setup
make up
```

Se uma execução anterior criou `node_modules` ou `public/build` com outro usuário e npm/Vite retornar `EACCES`, execute `make fix-permissions` e repita o comando.

Acesse `http://localhost:8000`. O Vite usa a porta `5173`.

O Vite publica os assets como `http://localhost:5173` e aceita requisições da origem definida em `VITE_APP_ORIGIN` (`http://localhost:8000` por padrão). Se você acessar a aplicação de outro computador, ajuste as duas variáveis para os endereços que esse navegador consegue alcançar antes de iniciar os containers.

Credenciais:

```text
demo@mycoins.local
demo1234
```

Para Docker Compose moderno, acrescente `COMPOSE="docker compose"` aos comandos, por exemplo:

```bash
make setup COMPOSE="docker compose"
```

Os alvos `make up` e `make debug` removem e recriam somente os containers descartáveis `app` e `vite`. Isso evita o erro `KeyError: 'ContainerConfig'` do Docker Compose 1.29 com versões atuais do Docker. Os arquivos e dados de sessão permanecem nos diretórios montados do projeto. A atualização para Docker Compose v2 continua recomendada.

## Debug local no VS Code

Instale a extensão PHP Debug, abra a configuração **Listen for My Coins Xdebug** e execute:

```bash
make debug
```

O Xdebug conecta na porta `9003` e mapeia `/var/www/html` para a raiz deste projeto. No uso normal, `XDEBUG_MODE` fica desligado.

## Qualidade

```bash
make test       # PHPUnit
make e2e        # Playwright desktop/mobile + axe
make build      # build de produção do Vite
make format     # Laravel Pint
```

Também é possível usar PHP 8.3, Composer e Node 22 diretamente no host: copie `.env.example` para `.env`, execute `composer install`, `php artisan key:generate`, `npm install`, `npm run build` e `php artisan serve`.

## Limites do protótipo

Não há banco de dados ou autenticação de produção. Criar, editar, excluir e arquivar altera apenas a sessão atual. O botão **Restaurar dados** repõe os fixtures originais; logout também encerra a sessão.
