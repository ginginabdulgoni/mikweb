#!/usr/bin/env bash
#
# MikWeb local/dev bootstrap. Idempotent: safe to re-run.
# For production, follow docs/DEPLOYMENT.md instead.
#
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> MikWeb setup"

command -v php >/dev/null || { echo "php not found"; exit 1; }
command -v composer >/dev/null || { echo "composer not found"; exit 1; }
command -v npm >/dev/null || { echo "npm not found"; exit 1; }

echo "==> Composer dependencies"
composer install

echo "==> NPM dependencies"
npm install

if [ ! -f .env ]; then
  echo "==> Creating .env"
  cp .env.example .env
  php artisan key:generate
fi

echo "==> Database migrate + seed"
php artisan migrate --seed

echo "==> Building assets"
npm run build

echo
echo "Done. Start the app with:"
echo "  php artisan serve          # web"
echo "  php artisan queue:work     # background jobs (monitoring/automation)"
echo "  php artisan schedule:work  # scheduler (metrics every minute)"
echo
echo "Sign in: admin@mikweb.local / password"
