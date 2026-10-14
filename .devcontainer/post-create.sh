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

# Network steps fail now and then (transient HTTP 500 from api.github.com): try up to 3 times.
# RETRY_SLEEP (seconds between attempts, default 15) exists so tests can run fast.
retry() {
    local attempt rc=0
    for attempt in 1 2 3; do
        rc=0
        "$@" || rc=$?
        [ "$rc" -eq 0 ] && return 0
        echo "post-create: '$*' failed (attempt $attempt of 3, exit $rc)" >&2
        [ "$attempt" -lt 3 ] && sleep "${RETRY_SLEEP:-15}"
    done
    return "$rc"
}

retry composer install --no-interaction --prefer-dist
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --no-interaction
retry npm ci --no-audit --no-fund
# Same as CI's `composer setup`: the build also generates the Wayfinder route files that
# `npm run types:check` needs.
npm run build
php artisan migrate --no-interaction --force
