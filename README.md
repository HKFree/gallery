# HKFree Gallery

A photo gallery for the [HKFree](https://www.hkfree.org) community network. Galleries are
organized by **area** and **access point (AP)**. Each AP has a **public** gallery (open to
everyone) and a **private** "Dokumentace" gallery (signed-in users only). Members with the
right role can upload and remove photos directly in the browser.

- **Single sign-on** via Keycloak / OIDC — no local accounts.
- **Role-based management** — uploading and deleting is restricted to configured Keycloak
  realm roles (e.g. `SO`, `ZSO`, `PREDSTAVENSTVO`, `VV`).
- **Area / AP data** is pulled live from the HKFree Userdb API.
- **Images stream through the application** from a private disk, so private documentation
  is never directly reachable; thumbnails are generated on demand.

## Tech stack

- PHP 8.3 · [Laravel 13](https://laravel.com)
- [Laravel Socialite](https://laravel.com/docs/socialite) + Keycloak provider for OIDC login
- [Intervention Image](https://image.intervention.io) for thumbnails
- Tailwind CSS 4 + Vite for the frontend
- SQLite (default) for sessions, cache, queue and app data
- [Pest](https://pestphp.com) for tests

## Configuration

The most important environment variables (see `.env.example` for the full list):

| Variable | Purpose |
| --- | --- |
| `APP_URL` | Public base URL. The Keycloak redirect URI is derived from it (`{APP_URL}/auth/callback`). |
| `KEYCLOAK_BASE_URL` | Keycloak server, e.g. `https://sso.hkfree.org`. |
| `KEYCLOAK_REALM` | Keycloak realm. |
| `KEYCLOAK_CLIENT_ID` / `KEYCLOAK_CLIENT_SECRET` | OIDC client credentials for this app. |
| `USERDB_AREAS_URL` | Userdb API endpoint that lists areas and APs. |
| `USERDB_API_USERNAME` / `USERDB_API_PASSWORD` | Credentials for the Userdb API. |
| `GALLERY_ADMIN_ROLES` | Comma-separated Keycloak realm roles allowed to manage galleries (OR-ed), e.g. `SO,ZSO,PREDSTAVENSTVO,VV`. |

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate

# SQLite database
touch database/database.sqlite
php artisan migrate

npm install
```

Fill in the `KEYCLOAK_*` and `USERDB_*` values in `.env`, then start everything (PHP
server, queue worker, log tail and Vite) with a single command:

```bash
composer run dev
```

Run the test suite with:

```bash
php artisan test
```

## Deployment (Apache)

This guide deploys the app into a Linux user's home directory at
`~/websites/hkfree-gallery` and serves it from `galerie.hkfree.org` over HTTPS, with
Apache running as `www-data`. Replace `<user>` with the account that owns the code.

### 1. Prerequisites

```bash
# Apache modules
sudo a2enmod rewrite ssl headers

# PHP 8.3 + extensions
sudo apt install php8.3 php8.3-cli libapache2-mod-php8.3 \
  php8.3-gd php8.3-sqlite3 php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-intl

# Let's Encrypt
sudo apt install certbot python3-certbot-apache
```

You also need [Composer](https://getcomposer.org) and [Node.js](https://nodejs.org)
(used only to build the frontend assets). This guide assumes `mod_php`; if you use
PHP-FPM instead, configure the handler accordingly.

### 2. Get the code

```bash
git clone <repo-url> ~/websites/hkfree-gallery
cd ~/websites/hkfree-gallery
```

### 3. Install dependencies and build assets

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### 4. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` for production:

```dotenv
APP_NAME="HKFree galerie"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://galerie.hkfree.org

DB_CONNECTION=sqlite

KEYCLOAK_BASE_URL=https://sso.hkfree.org
KEYCLOAK_REALM=...
KEYCLOAK_CLIENT_ID=...
KEYCLOAK_CLIENT_SECRET=...

USERDB_AREAS_URL=https://userdb.hkfree.org/userdb/api/areas
USERDB_API_USERNAME=...
USERDB_API_PASSWORD=...

GALLERY_ADMIN_ROLES=SO,ZSO,PREDSTAVENSTVO,VV
```

### 5. Database and optimization caches

```bash
touch database/database.sqlite
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> `php artisan storage:link` is **not** required — gallery images stream through the
> application from the private disk. Only run it if you later add public-disk assets.

### 6. Permissions (Apache runs as `www-data`)

Two things must work: Apache needs to **traverse into the home directory** to reach
`public/`, and it needs to **write** to `storage/`, `bootstrap/cache/` and the SQLite
database (SQLite also writes a journal in the `database/` directory).

```bash
# Let www-data traverse the path to the app's public/ directory
sudo chmod o+x /home/<user>
sudo chmod o+x /home/<user>/websites

# Own the code as <user>, with www-data as the group
sudo chown -R <user>:www-data /home/<user>/websites/hkfree-gallery

# Make the runtime directories and the SQLite database group-writable.
# The setgid bit makes newly created files inherit the www-data group.
cd ~/websites/hkfree-gallery
sudo chmod -R ug+rwX storage bootstrap/cache database
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
sudo chmod 664 database/database.sqlite
```

> Instead of `chmod o+x` on the home path, you may add `www-data` to `<user>`'s group:
> `sudo usermod -aG <user> www-data` (requires the home directory to be group-readable
> and executable). Re-run the permission and cache commands after every deploy that
> creates new files.

### 7. Apache virtual host

Create `/etc/apache2/sites-available/galerie.hkfree.org.conf`:

```apache
<VirtualHost *:80>
    ServerName galerie.hkfree.org
    DocumentRoot /home/<user>/websites/hkfree-gallery/public

    <Directory /home/<user>/websites/hkfree-gallery/public>
        AllowOverride All
        Require all granted
        Options FollowSymLinks
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/galerie.hkfree.org-error.log
    CustomLog ${APACHE_LOG_DIR}/galerie.hkfree.org-access.log combined
</VirtualHost>
```

Enable it and reload Apache:

```bash
sudo a2ensite galerie.hkfree.org
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### 8. HTTPS with Let's Encrypt (and HTTP → HTTPS redirect)

Let Certbot obtain the certificate, create the `:443` SSL virtual host, and add the
HTTP → HTTPS redirect to the port-80 vhost automatically:

```bash
sudo certbot --apache -d galerie.hkfree.org --redirect
```

Certificate renewal runs automatically via Certbot's systemd timer — check it with
`systemctl status certbot.timer`.

If you are **not** using `certbot --apache`, add the redirect to the port-80 vhost
manually:

```apache
<VirtualHost *:80>
    ServerName galerie.hkfree.org
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>
```

### 9. Updating an existing deployment

```bash
cd ~/websites/hkfree-gallery
git pull
composer install --no-dev --optimize-autoloader
source ~/.bashrc
nvm use --lts
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
# Re-apply group ownership if new files were created (see step 6).
```
