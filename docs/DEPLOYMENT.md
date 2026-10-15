# MikWeb — Deployment Guide (Ubuntu + Nginx + PHP-FPM)

Target: Ubuntu 22.04/24.04. Adapt paths for aaPanel (set the site document root to
`/public` and point the PHP version to 8.4).

## 1. System packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server redis-server supervisor \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-snmp php8.4-sockets
```

> `php8.4-snmp` and `php8.4-sockets` are required for SNMP monitoring and the
> RouterOS API transport respectively.

## 2. Application

```bash
cd /var/www
git clone <repo> mikweb && cd mikweb
composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
# edit .env: APP_URL, DB_*, REDIS_*, MIKWEB_*, REVERB_*
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force

php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Set ownership so PHP-FPM can write storage/cache:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 3. Nginx

```nginx
server {
    listen 80;
    server_name mikweb.example.com;
    root /var/www/mikweb/public;

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

    location ~ /\.(?!well-known).* { deny all; }
    client_max_body_size 25M;
}
```

Enable TLS with certbot (`sudo certbot --nginx`). **MikWeb controls network
devices — always run it behind HTTPS.**

## 4. Background workers (Supervisor)

MikWeb needs three long-running processes: the queue worker (monitoring +
automation + bulk jobs), the scheduler, and Reverb (WebSocket, optional until
Phase 4 live-push is enabled).

`/etc/supervisor/conf.d/mikweb.conf`:

```ini
[program:mikweb-queue]
command=php /var/www/mikweb/artisan queue:work redis --sleep=1 --tries=1 --max-time=3600
directory=/var/www/mikweb
user=www-data
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=30

[program:mikweb-scheduler]
command=php /var/www/mikweb/artisan schedule:work
directory=/var/www/mikweb
user=www-data
autostart=true
autorestart=true

[program:mikweb-reverb]
command=php /var/www/mikweb/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/mikweb
user=www-data
autostart=true
autorestart=true
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start all
```

> Alternatively run the scheduler from cron:
> `* * * * * cd /var/www/mikweb && php artisan schedule:run >> /dev/null 2>&1`

## 5. Verify

```bash
php artisan about                 # environment summary
php artisan mikweb:collect-metrics --sync   # one metrics pass, inline
```

Sign in, add a router, click **Test Connection**. If it reports the device's
identity and version, the full stack (web → queue → RouterOS API) is healthy.

## 6. Upgrades

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache route:cache
sudo supervisorctl restart mikweb-queue:* mikweb-scheduler mikweb-reverb
```
