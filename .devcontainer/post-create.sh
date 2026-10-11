#!/usr/bin/env bash
# First-start setup inside the dev container (VS Code runs it once; nightshift runs it per worktree).
# Idempotent: safe to run again.
set -euo pipefail
cd /workspace

if [ ! -f .env ]; then
    cp .env.example .env
fi
# .env.example leaves DB_PASSWORD blank on purpose; the container's Postgres uses "postgres".
sed -i 's/^DB_PASSWORD=$/DB_PASSWORD=postgres/' .env

composer install --no-interaction --prefer-dist
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --no-interaction
npm ci --no-audit --no-fund
# Same as CI's `composer setup`: the build also generates the Wayfinder route files that
# `npm run types:check` needs.
npm run build
php artisan migrate --no-interaction --force
