#!/bin/sh

export VITE_FRONTEND_URL="${VITE_FRONTEND_URL:-${APP_FRONTEND_URL:-/}}"
if [ -n "${APP_FRONTEND_URL}" ]; then
    export VITE_API_URL_CLIENT="${VITE_API_URL_CLIENT:-${APP_FRONTEND_URL}/api}"
fi
export VITE_API_URL_SERVER="${VITE_API_URL_SERVER:-http://localhost:80/api}"

cd /app/backend

if ! php artisan migrate --force; then
    echo "============================================"
    echo "ERROR: Migrations could not complete. Check the error above."
    echo "Ensure DATABASE_URL is set."
    echo "============================================"
fi

php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan storage:link

chown -R www-data:www-data /app/backend
chmod -R 775 /app/backend/storage /app/backend/bootstrap/cache

exec /usr/bin/supervisord -c /etc/supervisord.conf
