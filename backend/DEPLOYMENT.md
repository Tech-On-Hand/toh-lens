# Deploying the backend

This covers putting the Laravel backend on a real server for a school/organization to actually use — not local dev (see the root [`README.md`](../README.md#getting-started) for that).

The backend is the source of truth for the whole TOH Klas system: the kiosk app, the teacher app, and the browser extension all talk to whatever server you deploy here. There's no separate "staging" concept baked in — just point each environment's `.env` at its own database.

## Requirements

- PHP 8.4+ with the usual Laravel extensions (`pdo_mysql`, `redis` if using phpredis, `mbstring`, `fileinfo`, `gd` or `imagick`)
- Composer
- Node 20+ (to build frontend assets — not needed at runtime after that)
- MySQL (or another Laravel-supported database — MySQL is what's configured by default)
- Redis — used for the queue and for Reverb (the websocket server behind the teacher app's live updates)
- A process manager (systemd, used below) to keep the queue worker and Reverb running — `php-fpm` already runs as its own systemd service once installed
- Cron, for the scheduler

## Server setup (Ubuntu 22.04/24.04 LTS)

Everything below assumes a fresh Ubuntu server with a non-root sudo user already set up (`adduser deploy && usermod -aG sudo deploy`) and `git` installed. Run as that user, not root.

```bash
sudo apt update && sudo apt upgrade -y
```

### PHP 8.4

Ubuntu's own repos usually ship an older PHP than Laravel needs, so use the `ondrej/php` PPA:

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4 php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis \
    php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-bcmath
```

### Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Node

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

### MySQL

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation
```

Then create the database and a dedicated user (don't use `root` in `.env`):

```sql
sudo mysql
CREATE DATABASE toh_lens CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'toh_lens'@'localhost' IDENTIFIED BY 'a-real-password';
GRANT ALL PRIVILEGES ON toh_lens.* TO 'toh_lens'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Redis

```bash
sudo apt install -y redis-server
sudo systemctl enable --now redis-server
```

### Nginx + Certbot (HTTPS)

```bash
sudo apt install -y nginx certbot python3-certbot-nginx
```

Configuring the actual server block and running Certbot comes after the code is in place — see [Web server](#web-server) below.

### Firewall

If `ufw` is active, allow web traffic and SSH (and nothing else — Reverb, MySQL, and Redis should only ever be reached via `127.0.0.1`, which the configs below already do):

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

### Get the code

```bash
sudo mkdir -p /var/www/toh-lens
sudo chown deploy:deploy /var/www/toh-lens
git clone https://github.com/Tech-On-Hand/toh-lens.git /var/www/toh-lens
cd /var/www/toh-lens/backend
```

## Environment

Copy `.env.example` to `.env` and fill in real values. The defaults are tuned for local dev and are **wrong** for production in these specific ways:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-real-domain.example   # must be https — see "Why HTTPS" below

DB_HOST=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

REDIS_HOST=...
REDIS_PASSWORD=...

REVERB_APP_KEY=...     # generate real values, not "local-key"/"local-secret"
REVERB_APP_SECRET=...
REVERB_HOST=127.0.0.1  # Reverb listens locally; put it behind the web server (see below)
REVERB_ALLOWED_ORIGINS=https://your-real-domain.example

MAIL_MAILER=...        # not "log" — staff invitation emails are only written to the log otherwise
MAIL_HOST=...
MAIL_USERNAME=...
MAIL_PASSWORD=...
```

`QUEUE_CONNECTION=redis` and `BROADCAST_CONNECTION=reverb` can stay as the `.env.example` defaults.

### Why HTTPS

`php artisan key:generate` is fine to leave until first deploy, but **APP_URL must be `https://`** before you go live. Device enrollment tokens, API tokens, and student names cross the network on every kiosk request — plaintext HTTP means anyone on the same network segment as a school's router can read them. `php artisan klas:check` (below) fails loudly on this.

### Optional: S3-compatible storage

If you'd rather not store uploaded files on the app server's disk, set `FILESYSTEM_DISK=s3` and fill in the `AWS_*` variables (works with any S3-compatible provider, not just AWS — set `AWS_ENDPOINT` for others).

## First deploy

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build

php artisan key:generate   # only if APP_KEY is still empty
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

PHP-FPM runs as `www-data` by default, which needs write access to `storage/` and `bootstrap/cache/` (for logs, compiled views, and the session/cache files) even though `deploy` owns everything else:

```bash
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## Web server

Point nginx's document root at `backend/public/` — never at the repo root or `backend/` itself, which would expose `.env` and application code.

```nginx
# /etc/nginx/sites-available/toh-lens
server {
    listen 80;
    server_name your-real-domain.example;
    root /var/www/toh-lens/backend/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Reverb's websocket endpoint — proxy it through the same domain so the
    # teacher app doesn't need a second TLS cert/port opened up.
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/toh-lens /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Start on plain HTTP (above) so Certbot's HTTP-01 challenge can reach the server, point your domain's DNS A record at the server, then get a real certificate — Certbot rewrites this config in place to add the `listen 443 ssl` block and redirect, and sets up auto-renewal via a systemd timer (no cron entry needed):

```bash
sudo certbot --nginx -d your-real-domain.example
```

## Long-running processes

Three things need to stay running, independent of the web server. Each gets its own systemd unit so it restarts on crash and on reboot.

**`/etc/systemd/system/toh-lens-queue.service`**

```ini
[Unit]
Description=TOH Klas queue worker
After=network.target redis-server.service mysql.service

[Service]
User=deploy
WorkingDirectory=/var/www/toh-lens/backend
ExecStart=/usr/bin/php artisan queue:work --tries=1 --sleep=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

**`/etc/systemd/system/toh-lens-reverb.service`**

```ini
[Unit]
Description=TOH Klas Reverb (websockets)
After=network.target

[Service]
User=deploy
WorkingDirectory=/var/www/toh-lens/backend
ExecStart=/usr/bin/php artisan reverb:start
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now toh-lens-queue toh-lens-reverb
```

The third is the **scheduler**, which runs as cron rather than a long-lived process — `schedule:run` itself exits immediately each minute; it's not something a systemd service wraps:

```bash
crontab -e -u deploy
# add this line:
* * * * * cd /var/www/toh-lens/backend && php artisan schedule:run >> /dev/null 2>&1
```

Nothing else triggers the jobs below — Laravel's scheduler is just a dispatcher that needs this one cron line to actually run.

What the scheduler runs (all defined in [`routes/console.php`](routes/console.php), nothing to configure beyond the cron line above):

- `devices:mark-offline` and `device-commands:expire` — every minute
- `focus-sessions:expire` and `screen-sessions:expire` — every minute (without these, a focus-lock or a "someone is watching your screen" session never ends on its own)
- `activity:prune` — daily at 02:30, deletes browsing/app activity older than `TOH_ACTIVITY_RETENTION_DAYS` (default 90 — tell schools and parents what this is set to; it's the real retention policy for student activity data)

## Bootstrap the first school

Nothing exists yet after a fresh deploy — no organization, no school, no admin account. One command sets up all three:

```bash
php artisan klas:bootstrap "Tech On Hand" "Demo Primary School" admin@example.com \
    --classroom="Computer Lab" --classroom="Room 1" --organization-admin
```

`--organization-admin` also makes this account an administrator of the whole organization, which is required to invite staff (a school administrator alone gets a 403 on invitations) and gives access to every school in it. Leave it off for a school head who should only run their own school.

Safe to run again (it reuses an existing org/school/admin by name/email rather than duplicating). If the admin account is new, it prints a one-time generated password — there's no other way to retrieve it, so capture it before moving on.

## Verify before calling it done

```bash
php artisan klas:check
```

This is a built-in go/no-go check written specifically for this — it verifies `APP_KEY` is set, debug mode is off, `APP_URL` is real HTTPS, the database connects and migrations are current, Redis is reachable, a queue worker and Reverb are actually listening (not just configured), real mail credentials are set (not `log`), the scheduler has run in the last 3 minutes (proving cron is wired up), and that at least one school exists. Fix every `FAIL`; read every `WARN`.

## Teacher app download

Teachers who sign in to the website (and are not administrators) see a "Download the Teacher app for Windows" button on their dashboard. The installer is **not** in git or in `public/`; it is served only to signed-in users from `storage/app/private/downloads/`. After building it (`cd teacher; npm run tauri build`), copy it to the server:

```bash
scp "teacher/src-tauri/target/release/bundle/nsis/TOH Klas Teacher_0.1.0_x64-setup.exe" \
    deploy@your-server:/var/www/toh-lens/backend/storage/app/private/downloads/
```

The file name must start with `TOH Klas Teacher` and end in `.exe` or `.msi`; if several are there, the newest wins. Until one is uploaded the button is hidden and the dashboard tells teachers to ask their administrator. The installers are unsigned, so Windows SmartScreen shows an "unknown publisher" warning (More info, then Run anyway).

## Pointing the other apps at this server

Once the backend is live, each of the other three apps needs to know its URL:

- **Kiosk**: the setup screen's "Backend URL" field (or a provisioning file — see [`provisioning/`](../provisioning)) takes the server's HTTPS URL plus a device enrollment code issued from the admin's Klas Setup page.
- **Teacher app**: the login screen's "Server" field, same URL.
- **Browser extension**: talks to the kiosk app on the same machine (via [`browser-host/`](../browser-host)), not directly to the backend — nothing to configure there.

## Redeploying

```bash
cd /var/www/toh-lens
git pull
cd backend
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart              # queue worker finishes its current job, then exits — systemd restarts it with the new code (Restart=always)
sudo systemctl restart toh-lens-reverb # Reverb has no equivalent graceful-restart signal, so restart it directly
sudo systemctl reload php8.4-fpm       # drop any cached opcache state from the old code
```
