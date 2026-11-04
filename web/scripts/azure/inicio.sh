#!/usr/bin/env bash
# Arranque de la aplicación en Azure App Service
set -euo pipefail

app=/home/site/wwwroot
datos=/home/patronato/storage

# Nginx sirve la carpeta public de Laravel
cp "${app}/scripts/azure/nginx.conf" /etc/nginx/sites-available/default
service nginx reload

# /home se conserva entre despliegues; wwwroot se reemplaza en cada uno
mkdir -p \
    "${datos}/app/public" \
    "${datos}/app/private/respaldos" \
    "${datos}/framework/cache/data" \
    "${datos}/framework/sessions" \
    "${datos}/framework/views" \
    "${datos}/logs"
rm -rf "${app}/storage"
ln -sfn "${datos}" "${app}/storage"

cd "${app}"
php artisan migrate --force --no-interaction
php artisan db:seed --class=RolesSeeder --force --no-interaction
php artisan storage:link --force
php artisan optimize

echo "Patronato listo."
