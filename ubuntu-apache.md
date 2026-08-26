# Publicação no Ubuntu com Apache, sem Docker

Este guia instala o My Coins em Ubuntu Server 24.04 LTS com Apache, PHP-FPM 8.3 e MySQL. Composer, Node.js, npm, Vite e testes são executados somente na máquina local. O servidor recebe um pacote pronto, contendo `vendor/` e `public/build/`.

Isso é possível e simplifica o servidor, mas duas operações continuam obrigatoriamente no ambiente remoto: migrations no banco de produção e geração dos caches do Laravel. Elas não são etapas de compilação.

## 1. Premissas e nomes usados

Substitua os valores em maiúsculas:

```text
DOMINIO             coins.example.com
IP_SERVIDOR         endereço IPv4 do Ubuntu
USUARIO_DEPLOY      usuário utilizado no SSH
APP_DIR             /var/www/my-coins
ARQUIVO_RELEASE     my-coins-release.tar.gz
```

O servidor deve ter pelo menos acesso SSH com `sudo`. O `DocumentRoot` será `/var/www/my-coins/public`; nunca exponha a raiz inteira do Laravel.

## 2. DNS e acesso inicial

Crie um registro DNS `A` apontando `DOMINIO` para `IP_SERVIDOR`. Se estiver usando Cloudflare, deixe o registro inicialmente como **DNS only** até concluir o primeiro certificado TLS. Depois, o proxy pode ser ativado com SSL/TLS em **Full (strict)**.

Conecte-se:

```bash
ssh USUARIO_DEPLOY@IP_SERVIDOR
```

Atualize o Ubuntu:

```bash
sudo apt update
sudo apt upgrade -y
sudo reboot
```

Reconecte por SSH depois da reinicialização.

## 3. Firewall UFW

Autorize SSH antes de habilitar o firewall:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status verbose
```

As regras liberam HTTP/80 e HTTPS/443. O MySQL não deve ser exposto à internet quando a aplicação e o banco estão no mesmo servidor.

## 4. Apache, PHP-FPM e extensões

Instale somente o runtime necessário; Composer e Node não são necessários no servidor:

```bash
sudo apt install -y apache2 libapache2-mod-fcgid mysql-server rsync unzip curl \
  php8.3-cli php8.3-fpm php8.3-common php8.3-mysql php8.3-curl \
  php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl php8.3-opcache
```

Ative PHP-FPM e os módulos do Apache:

```bash
sudo a2enmod proxy_fcgi setenvif rewrite headers ssl
sudo a2enconf php8.3-fpm
sudo systemctl enable --now apache2 php8.3-fpm mysql
sudo apache2ctl configtest
```

O resultado esperado do último comando é `Syntax OK`. Verifique o runtime:

```bash
php -v
php -r 'foreach (["ctype", "curl", "dom", "fileinfo", "mbstring", "openssl", "pdo_mysql", "session", "tokenizer", "xml"] as $extension) { echo $extension.": ".(extension_loaded($extension) ? "OK" : "AUSENTE").PHP_EOL; }'
```

Todos os itens devem aparecer como `OK`.

Configure limites adequados ao upload OFX:

```bash
sudo nano /etc/php/8.3/fpm/conf.d/99-my-coins.ini
```

Conteúdo sugerido:

```ini
date.timezone = America/Sao_Paulo
memory_limit = 256M
upload_max_filesize = 4M
post_max_size = 8M
max_execution_time = 60
expose_php = Off
opcache.enable = 1
opcache.validate_timestamps = 0
```

Aplique:

```bash
sudo systemctl restart php8.3-fpm
```

Como `opcache.validate_timestamps` está desabilitado, cada publicação deverá recarregar o PHP-FPM, conforme indicado neste guia.

## 5. Banco MySQL

Abra o cliente administrativo local:

```bash
sudo mysql
```

Crie banco e usuário, usando uma senha exclusiva:

```sql
CREATE DATABASE my_coins CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'my_coins'@'localhost' IDENTIFIED BY 'SUBSTITUA_POR_UMA_SENHA_FORTE';
GRANT ALL PRIVILEGES ON my_coins.* TO 'my_coins'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Não abra a porta 3306 no UFW. Teste a credencial:

```bash
mysql -h 127.0.0.1 -u my_coins -p my_coins
```

Digite `EXIT;` depois do teste.

## 6. Preparar todo o build localmente

Faça o release a partir de um commit revisado. `git archive HEAD` empacota somente o último commit: alterações modificadas ou não rastreadas ficam de fora. Na raiz local do repositório:

```bash
git status --short
make test
make build
```

Se `git status --short` mostrar código que precisa ser publicado, revise e faça o commit antes de continuar.

Crie uma cópia limpa em diretório temporário. O Composer será executado pelo container PHP 8.3 local do projeto; ele não precisa estar instalado no host nem existirá no servidor de produção:

```bash
set -e

test -z "$(git status --porcelain)" || {
  echo "O repositório possui alterações não commitadas. Interrompendo o release."
  exit 1
}

LOCAL_RELEASE_DIR="$(mktemp -d -t my-coins-ubuntu.XXXXXX)"
chmod 755 "$LOCAL_RELEASE_DIR"
git archive HEAD | tar -x -C "$LOCAL_RELEASE_DIR"

APP_UID="$(id -u)" APP_GID="$(id -g)" docker-compose run --rm --no-deps \
  -v "$LOCAL_RELEASE_DIR:/release" \
  app composer --working-dir=/release install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

npm ci --prefix "$LOCAL_RELEASE_DIR"
npm run build --prefix "$LOCAL_RELEASE_DIR"

test -f "$LOCAL_RELEASE_DIR/vendor/autoload.php"
test -f "$LOCAL_RELEASE_DIR/public/build/manifest.json"
test ! -f "$LOCAL_RELEASE_DIR/public/hot"

tar --exclude='./node_modules' --exclude='./tests' \
  -czf my-coins-release.tar.gz -C "$LOCAL_RELEASE_DIR" .

tar -tzf my-coins-release.tar.gz | grep -Fx './vendor/autoload.php'
tar -tzf my-coins-release.tar.gz | grep -Fx './public/build/manifest.json'

sha256sum my-coins-release.tar.gz
echo "Release criado e validado com sucesso."
```

O `set -e` encerra o processo na primeira falha. O `chmod 755` é necessário porque `mktemp` cria o diretório com modo `700`; sem a normalização, o `tar` pode aplicar esse modo ao diretório da aplicação no servidor e impedir que o Apache atravesse o caminho. Os comandos `test` e a inspeção do `.tar.gz` impedem que um pacote sem `vendor/autoload.php` ou sem o manifesto do Vite seja publicado. O checksum só é calculado depois dessas validações.

O pacote final contém PHP, `vendor/` e assets compilados. Ele não contém `.env`, `node_modules`, testes nem arquivos OFX ignorados pelo Git. Embora o Composer seja executado pelo Docker local, o pacote produzido é uma aplicação PHP comum e será executado sem Docker no Ubuntu.

Se sua máquina usa Compose v2, substitua `docker-compose` por `docker compose`. Se a imagem local do serviço `app` ainda não existe, execute antes:

```bash
docker-compose build app
```

Não publique o arquivo se a mensagem `Release criado e validado com sucesso.` não for exibida. O aviso do Vite sobre chunks maiores que 500 kB é apenas informativo e não invalida o build.

## 7. Primeira transferência

Envie o pacote para a área temporária do usuário SSH:

```bash
scp my-coins-release.tar.gz USUARIO_DEPLOY@IP_SERVIDOR:/tmp/
```

No servidor:

```bash
sudo mkdir -p /var/www/my-coins
sudo tar -xzf /tmp/my-coins-release.tar.gz -C /var/www/my-coins
sudo chown -R USUARIO_DEPLOY:www-data /var/www/my-coins
sudo chmod 755 /var /var/www
sudo chmod 750 /var/www/my-coins
sudo find /var/www/my-coins/public -type d -exec chmod 755 {} \;
sudo find /var/www/my-coins/public -type f -exec chmod 644 {} \;
sudo mkdir -p /var/www/my-coins/storage/framework/{cache,sessions,views}
sudo chown -R USUARIO_DEPLOY:www-data /var/www/my-coins/storage /var/www/my-coins/bootstrap/cache
sudo find /var/www/my-coins/storage /var/www/my-coins/bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find /var/www/my-coins/storage /var/www/my-coins/bootstrap/cache -type f -exec chmod 664 {} \;
sudo -u www-data test -x /var/www/my-coins
sudo -u www-data test -r /var/www/my-coins/public/index.php
```

Os dois últimos comandos não imprimem nada quando passam. Eles garantem que o Apache consegue atravessar o diretório da aplicação e ler o front controller. Os demais arquivos podem permanecer legíveis pelo Apache e graváveis somente pelo usuário de deploy.

## 8. Arquivo de produção `.env`

Crie o arquivo diretamente no servidor:

```bash
cd /var/www/my-coins
cp .env.example .env
nano .env
```

Configuração mínima:

```dotenv
APP_NAME="My Coins"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://DOMINIO
APP_TIMEZONE=America/Sao_Paulo
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_coins
DB_USERNAME=my_coins
DB_PASSWORD=SUBSTITUA_PELA_SENHA_DO_MYSQL

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

MAIL_MAILER=log
```

Use uma URL válida e sem espaços em `APP_URL`; não inclua comentários no fim da linha. Proteja o arquivo:

```bash
chmod 640 /var/www/my-coins/.env
sudo chgrp www-data /var/www/my-coins/.env
```

## 9. VirtualHost do Apache

Crie:

```bash
sudo nano /etc/apache2/sites-available/my-coins.conf
```

Conteúdo:

```apache
<VirtualHost *:80>
    ServerName DOMINIO
    ServerAdmin webmaster@DOMINIO

    DocumentRoot /var/www/my-coins/public

    <Directory /var/www/my-coins/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/my-coins-error.log
    CustomLog ${APACHE_LOG_DIR}/my-coins-access.log combined
</VirtualHost>
```

Ative o site:

```bash
sudo a2ensite my-coins.conf
sudo a2dissite 000-default.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

O `public/.htaccess` versionado cuida das rotas Laravel. O Apache nunca deve usar `/var/www/my-coins` como `DocumentRoot`.

## 10. Instalação inicial da aplicação

Execute como `USUARIO_DEPLOY`:

```bash
cd /var/www/my-coins
php artisan key:generate --force
php artisan optimize:clear
php artisan migrate --force
php artisan mycoins:install --name="Seu Nome" --email="seu-email@example.com"
php artisan optimize
php artisan migrate:status
```

Guarde a senha temporária exibida pelo `mycoins:install`. Ela aparece somente quando o primeiro proprietário é criado. O sistema exigirá uma nova senha no primeiro login.

Não execute `key:generate` nas atualizações. A `APP_KEY` deve permanecer estável.

Reaplique as permissões depois dos comandos:

```bash
sudo chown -R USUARIO_DEPLOY:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

## 11. HTTPS com Certbot

Depois que o DNS responder para o IP correto:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d DOMINIO
sudo certbot renew --dry-run
```

Escolha o redirecionamento permanente de HTTP para HTTPS. Verifique:

```bash
curl -I https://DOMINIO/up
```

Se usar Cloudflare, ative o proxy somente depois e configure **Full (strict)** para manter a validação do certificado de origem.

## 12. Verificação da primeira publicação

No servidor:

```bash
cd /var/www/my-coins
php artisan about
php artisan config:show app
test -f public/build/manifest.json
test ! -f public/hot
curl -I https://DOMINIO/up
```

No navegador:

1. Abra `https://DOMINIO/login`.
2. Entre com a senha temporária e altere-a.
3. Crie uma conta e uma transação de teste.
4. Importe um OFX pequeno.
5. Confirme CSS, JavaScript e prevenção de duplicidade.

## 13. Atualizações: build local e sobrescrita controlada

Gere novamente `my-coins-release.tar.gz` seguindo a seção 6. O servidor continuará sem Composer, npm ou Vite.

Envie o novo pacote:

```bash
scp my-coins-release.tar.gz USUARIO_DEPLOY@IP_SERVIDOR:/tmp/
```

No servidor, extraia em uma pasta temporária. Não extraia diretamente sobre a aplicação, pois arquivos removidos em uma nova versão permaneceriam no servidor:

```bash
DEPLOY_STAGE="$(mktemp -d -p /tmp my-coins-deploy.XXXXXX)"
tar -xzf /tmp/my-coins-release.tar.gz -C "$DEPLOY_STAGE"
test -f "$DEPLOY_STAGE/vendor/autoload.php"
test -f "$DEPLOY_STAGE/public/build/manifest.json"
test ! -f "$DEPLOY_STAGE/public/hot"
```

Execute o restante da atualização na mesma sessão SSH para preservar `DEPLOY_STAGE`.

Faça backup do banco antes da manutenção:

```bash
sudo mkdir -p /var/backups/my-coins
mysqldump -h 127.0.0.1 -u my_coins -p my_coins | gzip \
  | sudo tee /var/backups/my-coins/database-before-deploy.sql.gz >/dev/null
sudo chmod 600 /var/backups/my-coins/database-before-deploy.sql.gz
```

Ative manutenção e sincronize. `.env`, dados de `storage` e o link `public/storage` são preservados:

```bash
cd /var/www/my-coins
php artisan down --retry=30

case "$DEPLOY_STAGE" in
  /tmp/my-coins-deploy.*) ;;
  *) echo "Diretório temporário inválido"; exit 1 ;;
esac
test -f "$DEPLOY_STAGE/vendor/autoload.php"

sudo rsync -a --delete \
  --exclude='.env' \
  --exclude='storage/' \
  --exclude='public/storage' \
  "$DEPLOY_STAGE/" /var/www/my-coins/

sudo chown -R USUARIO_DEPLOY:www-data /var/www/my-coins
sudo chmod 750 /var/www/my-coins
sudo find /var/www/my-coins/public -type d -exec chmod 755 {} \;
sudo find /var/www/my-coins/public -type f -exec chmod 644 {} \;
sudo chown -R USUARIO_DEPLOY:www-data /var/www/my-coins/storage /var/www/my-coins/bootstrap/cache
sudo find /var/www/my-coins/storage /var/www/my-coins/bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find /var/www/my-coins/storage /var/www/my-coins/bootstrap/cache -type f -exec chmod 664 {} \;
sudo -u www-data test -x /var/www/my-coins
sudo -u www-data test -r /var/www/my-coins/public/index.php

cd /var/www/my-coins
php artisan optimize:clear
php artisan migrate --force
php artisan optimize
sudo systemctl reload php8.3-fpm
sudo systemctl reload apache2
php artisan up
curl -I https://DOMINIO/up
```

`rsync --delete` remove código antigo que deixou de existir no release, mas as três exclusões protegem configuração e dados persistentes. Revise esses caminhos antes de executar o comando.

Se qualquer comando falhar antes de `artisan up`, mantenha a manutenção ativa, consulte os logs e restaure o backup antes de liberar o site.

## 14. Rollback e backups

Para conservar também uma cópia do código antes da atualização:

```bash
sudo tar --exclude='my-coins/storage/logs' \
  -czf /var/backups/my-coins/code-before-deploy.tar.gz \
  -C /var/www my-coins
sudo chmod 600 /var/backups/my-coins/code-before-deploy.tar.gz
```

O rollback de código pode não ser suficiente se migrations alteraram o schema. Nesse caso, restaure também o banco:

```bash
gzip -dc /var/backups/my-coins/database-before-deploy.sql.gz \
  | mysql -h 127.0.0.1 -u my_coins -p my_coins
```

Depois restaure o código, reaplique permissões, execute `php artisan optimize` e recarregue PHP-FPM e Apache.

Automatize um backup diário do MySQL somente depois de definir retenção, proteção das credenciais e armazenamento fora do servidor.

## 15. Diagnóstico

### Erro 500

```bash
sudo tail -n 100 /var/log/apache2/my-coins-error.log
sudo tail -n 100 /var/www/my-coins/storage/logs/laravel.log
cd /var/www/my-coins
php artisan about
php artisan optimize:clear
```

Verifique `.env`, `APP_KEY`, credenciais MySQL e permissões de `storage` e `bootstrap/cache`. Não habilite `APP_DEBUG` publicamente.

### CSS ou JavaScript não carrega

```bash
test -f /var/www/my-coins/public/build/manifest.json
test ! -f /var/www/my-coins/public/hot
curl -I https://DOMINIO/build/manifest.json
```

O servidor não executa Vite. `public/hot` nunca deve estar presente em produção.

### Rotas retornam 404

```bash
sudo a2query -m rewrite
sudo apache2ctl -S
sudo apache2ctl configtest
```

Confirme `AllowOverride All`, o `.htaccess` e o `DocumentRoot` terminando em `/public`.

### Apache retorna 403 por falta de `search permissions`

Esse erro indica que o usuário `www-data` não possui permissão de travessia (`x`) em algum diretório do caminho. Identifique o componente:

```bash
namei -l /var/www/my-coins/public/index.php
sudo -u www-data test -x /var/www/my-coins && echo "Diretório acessível"
sudo -u www-data test -r /var/www/my-coins/public/index.php && echo "index.php acessível"
```

Corrija o diretório da aplicação sem usar `777`:

```bash
sudo chown USUARIO_DEPLOY:www-data /var/www/my-coins
sudo chmod 750 /var/www/my-coins
sudo chmod 755 /var /var/www /var/www/my-coins/public
```

O usuário de deploy mantém acesso como proprietário e o Apache atravessa `my-coins` por pertencer ao grupo `www-data`.

### Banco ou `could not find driver`

```bash
php -r 'var_dump(extension_loaded("pdo_mysql"));'
cd /var/www/my-coins
php artisan config:show database
php artisan migrate:status
```

O primeiro resultado deve ser `bool(true)`.

### Recuperar o proprietário

```bash
cd /var/www/my-coins
php artisan mycoins:reset-password
```

Guarde a senha temporária exibida e altere-a no próximo login.

## 16. Scheduler e filas

As transações recorrentes dependem do Laravel Scheduler para manter a projeção dos próximos 12 meses. Edite o crontab do usuário que executa a aplicação:

```bash
sudo crontab -u www-data -e
```

Adicione:

```cron
* * * * * cd /var/www/my-coins && php artisan schedule:run >> /dev/null 2>&1
```

Valide o comando e a agenda:

```bash
cd /var/www/my-coins
sudo -u www-data php artisan mycoins:generate-recurrences
sudo -u www-data php artisan schedule:list
```

O projeto usa `QUEUE_CONNECTION=sync` e não exige `queue:work`. Se futuramente houver jobs assíncronos, configure um serviço `systemd` separado para a fila.

## Referências

- [Deployment do Laravel 13](https://laravel.com/docs/13.x/deployment)
- [Estrutura e diretório público do Laravel](https://laravel.com/docs/13.x/structure)
- [Configuração do Apache no Ubuntu](https://documentation.ubuntu.com/server/how-to/web-services/configure-apache2-settings/)
- [Firewall UFW no Ubuntu](https://documentation.ubuntu.com/server/how-to/security/firewalls/)
- [Documentação do Certbot](https://eff-certbot.readthedocs.io/en/stable/)
