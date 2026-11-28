#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
port=${SQE_PORT:-8765}
test_directory=$(mktemp -d -t akaunting-sqe-XXXXXXXX)
export APP_ENV=testing APP_INSTALLED=true DB_CONNECTION=sqlite
export MAIL_MAILER=array QUEUE_CONNECTION=sync
export APP_URL="http://127.0.0.1:${port}" DB_DATABASE="$test_directory/database.sqlite"
touch "$DB_DATABASE"
php artisan migrate --force --no-interaction
php artisan db:seed --class='Database\Seeds\TestCompany' --force --no-interaction
exec php -S "127.0.0.1:${port}" -t public server.php
