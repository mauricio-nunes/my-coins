# Deploying My Coins on Google Compute Engine

This guide deploys My Coins on one Ubuntu VM using Docker Compose. Laravel,
MySQL, and the Cloudflare Tunnel client run as containers. The tunnel makes an
outbound connection to Cloudflare, so the VM needs no static IP and exposes no
HTTP, HTTPS, MySQL, Laravel, or Vite ports.

The examples use an `e2-micro` VM in `us-central1-a`. Its 1 GB of memory is
enough for light personal use, but builds may be slow. The guide adds swap;
upgrade to `e2-small` if the kernel repeatedly kills builds or containers.

## 1. Architecture and prerequisites

You need:

- a Google Cloud project with billing enabled;
- the [Google Cloud CLI](https://cloud.google.com/sdk/docs/install) locally;
- a domain managed by Cloudflare;
- permission to create a VM, service account, and Cloud Storage bucket;
- a Cloudflare account with access to create a remotely managed Tunnel.

The request path is:

```text
Browser -> Cloudflare HTTPS -> outbound Tunnel -> cloudflared -> app:80
                                                        |
                                                        +-> mysql:3306
```

### Why Caddy is not used

Caddy can run successfully as a Docker container. It is not needed in this
architecture: Cloudflare terminates public HTTPS, and `cloudflared` sends the
request directly to the Apache container across Docker's private network.
Removing Caddy eliminates another container, certificate configuration, and a
proxy hop. If Cloudflare Tunnel is removed later, Caddy in Docker is a valid
replacement for exposing ports 80 and 443.

Set variables on your workstation. Replace the project, domain, and globally
unique bucket name:

```bash
export PROJECT_ID="your-google-project"
export ZONE="us-central1-a"
export VM_NAME="my-coins"
export DOMAIN="coins.example.com"
export BACKUP_BUCKET="your-project-my-coins-backups"
export VM_SERVICE_ACCOUNT="my-coins-vm@${PROJECT_ID}.iam.gserviceaccount.com"

gcloud auth login
gcloud config set project "$PROJECT_ID"
gcloud services enable compute.googleapis.com storage.googleapis.com iam.googleapis.com
```

## 2. Create the backup bucket and VM

Create a service account that can upload database backups but cannot administer
the VM:

```bash
gcloud iam service-accounts create my-coins-vm \
  --display-name="My Coins VM"

gcloud storage buckets create "gs://${BACKUP_BUCKET}" \
  --location=us-central1 \
  --uniform-bucket-level-access

gcloud storage buckets add-iam-policy-binding "gs://${BACKUP_BUCKET}" \
  --member="serviceAccount:${VM_SERVICE_ACCOUNT}" \
  --role="roles/storage.objectCreator"
```

Create the VM on the existing default network. Omitting `--address` gives the
VM an ephemeral external IP. The tunnel does not depend on that address, and
`gcloud compute ssh` resolves the VM's current address automatically.

```bash
gcloud compute instances create "$VM_NAME" \
  --zone="$ZONE" \
  --machine-type=e2-micro \
  --network=default \
  --image-family=ubuntu-2404-lts-amd64 \
  --image-project=ubuntu-os-cloud \
  --boot-disk-size=20GB \
  --boot-disk-type=pd-standard \
  --service-account="$VM_SERVICE_ACCOUNT" \
  --scopes=https://www.googleapis.com/auth/devstorage.read_write \
  --metadata=enable-oslogin=TRUE
```

This guide does not create or modify VPC firewall rules. It assumes the default
network already has its standard SSH rule. Confirm it and connect:

```bash
gcloud compute firewall-rules describe default-allow-ssh
gcloud compute ssh "$VM_NAME" --zone="$ZONE"
```

If `default-allow-ssh` does not exist, `gcloud compute ssh` cannot reach the VM;
ask the project administrator to restore SSH access at the Google Cloud layer.
UFW cannot override a VPC-level denial. No Google Cloud web firewall rule or VM
HTTP/HTTPS network tag is required because the Tunnel is outbound-only.

All commands below run on the VM unless the guide says otherwise.

## 3. Prepare Ubuntu

Install base packages, apply updates, and create 2 GB of swap:

```bash
sudo apt-get update
sudo apt-get upgrade -y
sudo apt-get install -y ca-certificates curl git gnupg ufw unattended-upgrades
sudo systemctl enable --now unattended-upgrades

sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h
```

Ubuntu 24.04 Compute Engine images include the Google Cloud CLI, which the
backup job will use with the VM service account.

### Install Docker and Compose v2

```bash
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

printf '%s\n' \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker "$USER"
```

Reconnect so the Docker group takes effect:

```bash
exit
gcloud compute ssh "$VM_NAME" --zone="$ZONE"
docker compose version
```

## 4. Configure UFW and SSH

There are no inbound application ports. UFW allows only rate-limited SSH and
allows outbound traffic, including Cloudflare Tunnel connections on port 7844.

Keep the current session open and confirm a second terminal can connect with
`gcloud compute ssh`. Then harden SSH:

```bash
sudo tee /etc/ssh/sshd_config.d/99-my-coins.conf >/dev/null <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AuthenticationMethods publickey
PermitEmptyPasswords no
MaxAuthTries 3
EOF

sudo sshd -t
sudo systemctl reload ssh
```

Test `gcloud compute ssh` again from the second terminal before enabling UFW:

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw limit OpenSSH comment 'rate-limit-ssh'
sudo ufw --force enable
sudo ufw status verbose
```

Do not add rules for ports 80, 443, 8000, 3306, or 5173. The production Compose
file below publishes no Docker ports, avoiding Docker/UFW forwarding surprises.

## 5. Create the Cloudflare Tunnel

Use a remotely managed Tunnel as recommended for Docker deployments:

1. Open the Cloudflare dashboard.
2. Go to **Networking > Tunnels**.
3. Select **Create Tunnel**, choose **Cloudflared**, and name it `my-coins`.
4. Choose the Docker environment.
5. Copy the generated Docker command into a private text editor.
6. Keep only the long token following `--token`; do not run the dashboard's
   command directly and do not commit the token.
7. In the Tunnel's **Routes** tab, add a **Published application** route.
8. Set the public hostname to the value represented by `$DOMAIN`.
9. Set **Service URL** to `http://app:80`.

Cloudflare creates the proxied DNS record for the route. `app` works as the
origin hostname because `cloudflared` and Laravel share a Docker Compose
network. The Tunnel may show a disconnected status until the first deployment.

See Cloudflare's [Tunnel setup](https://developers.cloudflare.com/tunnel/setup/),
[routing](https://developers.cloudflare.com/tunnel/routing/), and
[token security](https://developers.cloudflare.com/tunnel/advanced/tunnel-tokens/)
documentation. Anyone who has the token can run the Tunnel, so protect and
rotate it like a password.

## 6. Clone the application

```bash
sudo mkdir -p /opt/my-coins
sudo chown "$USER":"$USER" /opt/my-coins
git clone https://github.com/mauricio-nunes/my-coins.git /opt/my-coins
cd /opt/my-coins
```

Create the production files below. Commit the non-secret files to the repository
or maintain them in a reviewed deployment branch. Never commit
`.env.production`.

### `Dockerfile.production`

```dockerfile
FROM php:8.3-apache-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip \
    && docker-php-ext-install intl pcntl pdo_mysql zip \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
    && docker-php-ext-enable opcache

FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --no-progress \
    --prefer-dist --optimize-autoloader

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php-base AS runtime
WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /app/bootstrap/cache ./bootstrap/cache
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
CMD ["apache2-foreground"]
```

`EXPOSE 80` documents the internal container port; it does not publish it on the
VM.

### `.dockerignore`

```gitignore
.git
.github
.env
.env.*
vendor
node_modules
public/build
public/hot
storage/logs/*
storage/framework/cache/data/*
storage/framework/sessions/*
storage/framework/views/*
tests
```

### `compose.production.yml`

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.production
      target: runtime
    env_file:
      - .env.production
    restart: unless-stopped
    depends_on:
      mysql:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "php", "-r", "exit(@file_get_contents('http://127.0.0.1/up') === false ? 1 : 0);"]
      interval: 30s
      timeout: 5s
      retries: 5
      start_period: 20s
    mem_limit: 320m

  scheduler:
    build:
      context: .
      dockerfile: Dockerfile.production
      target: runtime
    command: php artisan schedule:work
    env_file:
      - .env.production
    restart: unless-stopped
    depends_on:
      mysql:
        condition: service_healthy
    mem_limit: 128m

  mysql:
    image: mysql:8.4
    env_file:
      - .env.production
    environment:
      MYSQL_DATABASE: ${DB_DATABASE:?Set DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME:?Set DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD:?Set DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:?Set DB_ROOT_PASSWORD}
    command:
      - --innodb-buffer-pool-size=128M
      - --performance-schema=OFF
      - --max-connections=30
    restart: unless-stopped
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h localhost -uroot -p$$MYSQL_ROOT_PASSWORD"]
      interval: 10s
      timeout: 5s
      retries: 20
      start_period: 30s
    mem_limit: 320m

  cloudflared:
    image: cloudflare/cloudflared:latest
    command: tunnel --no-autoupdate run
    environment:
      TUNNEL_TOKEN: ${CLOUDFLARE_TUNNEL_TOKEN:?Set CLOUDFLARE_TUNNEL_TOKEN}
    restart: unless-stopped
    depends_on:
      app:
        condition: service_healthy
    mem_limit: 128m

volumes:
  mysql-data:
```

No service has a `ports` entry. Only containers on this Compose network can
reach `app:80` and `mysql:3306`.

### Trust the Tunnel proxy

In `bootstrap/app.php`, add this call inside the existing `withMiddleware`
callback, before the alias registration:

```php
$middleware->trustProxies(
    at: '*',
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```

The wildcard is acceptable here because the application has no host port and
can receive web requests only from the Tunnel container.

## 7. Create production secrets

Generate values that are safe to store in a Compose environment file:

```bash
cd /opt/my-coins
export APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
export DB_PASSWORD_VALUE="$(openssl rand -hex 24)"
export DB_ROOT_PASSWORD_VALUE="$(openssl rand -hex 24)"
```

Create `.env.production` and paste the Tunnel token copied from Cloudflare:

```dotenv
APP_NAME="My Coins"
APP_ENV=production
APP_KEY=replace-with-generated-app-key
APP_DEBUG=false
APP_URL=https://coins.example.com
APP_TIMEZONE=America/Sao_Paulo
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
APP_FAKER_LOCALE=pt_BR

LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=my_coins
DB_USERNAME=my_coins
DB_PASSWORD=replace-with-generated-database-password
DB_ROOT_PASSWORD=replace-with-generated-root-password

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_PATH=/
SESSION_DOMAIN=null

CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
MAIL_MAILER=log

CLOUDFLARE_TUNNEL_TOKEN=paste-the-tunnel-token-here
```

Insert the generated values and domain:

```bash
sed -i "s|replace-with-generated-app-key|${APP_KEY_VALUE}|" .env.production
sed -i "s|replace-with-generated-database-password|${DB_PASSWORD_VALUE}|" .env.production
sed -i "s|replace-with-generated-root-password|${DB_ROOT_PASSWORD_VALUE}|" .env.production
sed -i "s|coins.example.com|${DOMAIN}|" .env.production
chmod 600 .env.production
unset APP_KEY_VALUE DB_PASSWORD_VALUE DB_ROOT_PASSWORD_VALUE
git check-ignore .env.production
```

The final command must print `.env.production`. Review the file to ensure the
placeholder Tunnel token was replaced. Docker users with access to the VM can
inspect container environment variables, so grant VM and Docker access only to
trusted administrators.

## 8. First deployment

Build the image, start MySQL, migrate, and start all services:

```bash
cd /opt/my-coins
docker compose --env-file .env.production -f compose.production.yml build
docker compose --env-file .env.production -f compose.production.yml up -d mysql
docker compose --env-file .env.production -f compose.production.yml run --rm app \
  php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yml up -d
```

Create the initial owner and save the one-time temporary password:

```bash
docker compose --env-file .env.production -f compose.production.yml exec app \
  php artisan mycoins:install --name="Your Name" --email="you@example.com"
```

Sign in immediately and complete the required password change. If an owner
already exists, use `php artisan mycoins:reset-password`; do not delete the
database just to recover access.

Check application and Tunnel health:

```bash
docker compose --env-file .env.production -f compose.production.yml ps
docker compose --env-file .env.production -f compose.production.yml exec -T app \
  php -r 'exit(@file_get_contents("http://127.0.0.1/up") === false ? 1 : 0);'
docker compose --env-file .env.production -f compose.production.yml logs \
  --tail=100 cloudflared
```

The Cloudflare dashboard should show the Tunnel as **Healthy**. Open
`https://your-domain` and verify that login and compiled assets load without
Vite, mixed-content, or CORS errors.

## 9. Validate that nothing is publicly exposed

On the VM:

```bash
sudo ufw status verbose
sudo ss -lntp
docker compose --env-file .env.production -f compose.production.yml ps
```

Expected results:

- UFW allows only rate-limited OpenSSH inbound;
- no process listens publicly on ports 80, 443, 8000, 3306, or 5173;
- Compose lists no published host ports;
- the public hostname works only through Cloudflare Tunnel;
- `gcloud compute ssh "$VM_NAME" --zone="$ZONE"` continues to work;
- data survives container restarts and a VM reboot.

## 10. Deploy updates

Back up first, then update only reviewed code:

```bash
cd /opt/my-coins
sudo /usr/local/sbin/backup-my-coins
git fetch --prune origin
git pull --ff-only origin main
docker compose --env-file .env.production -f compose.production.yml build
docker compose --env-file .env.production -f compose.production.yml pull cloudflared
docker compose --env-file .env.production -f compose.production.yml run --rm app \
  php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yml up -d --remove-orphans
docker compose --env-file .env.production -f compose.production.yml ps
```

The backup command becomes available after section 11. Complete that section
before the first update.

Inspect failures with:

```bash
docker compose --env-file .env.production -f compose.production.yml logs \
  --tail=200 app mysql cloudflared
```

To roll application code back, check out the previous Git tag or commit, rebuild,
and recreate the app. Restore the pre-deployment database dump if a migration
made incompatible schema or data changes.

## 11. Back up MySQL to Cloud Storage

Create a consistent compressed dump, retain seven days locally, and upload each
dump to the private bucket:

```bash
sudo install -d -o root -g docker -m 0750 /var/backups/my-coins
sudo tee /usr/local/sbin/backup-my-coins >/dev/null <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail

cd /opt/my-coins
stamp=$(date -u +%Y%m%dT%H%M%SZ)
target="/var/backups/my-coins/my-coins-${stamp}.sql.gz"

docker compose --env-file .env.production -f compose.production.yml exec -T mysql \
  sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  | gzip -9 >"$target"

test -s "$target"
gcloud storage cp "$target" "gs://REPLACE_BACKUP_BUCKET/mysql/"
find /var/backups/my-coins -type f -name 'my-coins-*.sql.gz' -mtime +7 -delete
SCRIPT

sudo sed -i "s/REPLACE_BACKUP_BUCKET/${BACKUP_BUCKET}/" /usr/local/sbin/backup-my-coins
sudo chmod 0750 /usr/local/sbin/backup-my-coins
sudo /usr/local/sbin/backup-my-coins
```

Schedule it daily:

```bash
sudo tee /etc/systemd/system/my-coins-backup.service >/dev/null <<'EOF'
[Unit]
Description=Back up the My Coins database
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/backup-my-coins
EOF

sudo tee /etc/systemd/system/my-coins-backup.timer >/dev/null <<'EOF'
[Unit]
Description=Daily My Coins database backup

[Timer]
OnCalendar=*-*-* 04:00:00
Persistent=true
RandomizedDelaySec=15m

[Install]
WantedBy=timers.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now my-coins-backup.timer
sudo systemctl list-timers my-coins-backup.timer
```

On your workstation, configure automatic deletion of remote dumps after 30
days. This deletion is irreversible:

```bash
cat >/tmp/my-coins-backup-lifecycle.json <<'EOF'
{
  "rule": [
    {
      "action": {"type": "Delete"},
      "condition": {"age": 30}
    }
  ]
}
EOF

gcloud storage buckets update "gs://${BACKUP_BUCKET}" \
  --lifecycle-file=/tmp/my-coins-backup-lifecycle.json
```

The VM service account can upload but not download backups. To restore, use an
authorized workstation to download and copy the selected dump to the VM:

```bash
gcloud storage cp "gs://${BACKUP_BUCKET}/mysql/SELECTED_BACKUP.sql.gz" /tmp/
gcloud compute scp /tmp/SELECTED_BACKUP.sql.gz \
  "$VM_NAME:/tmp/SELECTED_BACKUP.sql.gz" --zone="$ZONE"
```

Then, on the VM, take a fresh backup and restore the selected file:

```bash
sudo /usr/local/sbin/backup-my-coins
gunzip -c /tmp/SELECTED_BACKUP.sql.gz \
  | docker compose --env-file /opt/my-coins/.env.production \
      -f /opt/my-coins/compose.production.yml exec -T mysql \
      sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

Test restoration periodically with an isolated database. This guide intentionally
does not create boot-disk snapshots; the MySQL dumps are the recovery source.

## 12. Troubleshooting

- **Tunnel disconnected:** check the token, container logs, outbound DNS, and
  outbound connectivity to Cloudflare on port 7844.
- **Cloudflare 502:** confirm the published route uses `http://app:80`, the app
  is healthy, and both containers belong to the same Compose project.
- **Redirect or insecure-cookie problems:** verify `APP_URL` uses HTTPS,
  `SESSION_SECURE_COOKIE=true`, and Laravel trusts forwarded headers.
- **Missing CSS or JavaScript:** ensure no `public/hot` exists, rebuild the image,
  and verify `public/build/manifest.json` inside the app container.
- **Build killed:** confirm swap is active or temporarily resize to `e2-small`.
- **Permission errors:** do not bind-mount the source over `/var/www/html` in
  production; the production image prepares Laravel's writable directories.
- **SSH unavailable:** use `gcloud compute ssh --troubleshoot`; check the default
  VPC SSH rule, OS Login permissions, VM state, and UFW.
- **Tunnel token exposed:** rotate it in Cloudflare, replace it in
  `.env.production`, and recreate `cloudflared`.
- **Lost owner password:** execute `php artisan mycoins:reset-password` in the
  app container and immediately change the generated password.

Routine checks:

```bash
docker compose --env-file .env.production -f compose.production.yml ps
docker stats --no-stream
df -h
free -h
sudo ufw status verbose
sudo systemctl --failed
sudo journalctl -u my-coins-backup.service -n 50 --no-pager
```
