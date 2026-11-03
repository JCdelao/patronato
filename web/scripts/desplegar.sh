#!/usr/bin/env bash
set -euo pipefail

destino="$1"
version="$2"
paquete="/tmp/patronato-${version}.tar.gz"
release="${destino}/releases/${version}"
compartido="${destino}/shared"

if [ ! -f "${compartido}/.env" ]; then
    echo "Falta ${compartido}/.env con la configuración de producción." >&2
    exit 1
fi

mkdir -p \
    "${compartido}/storage/app/public" \
    "${compartido}/storage/app/private/respaldos" \
    "${compartido}/storage/framework/cache/data" \
    "${compartido}/storage/framework/sessions" \
    "${compartido}/storage/framework/views" \
    "${compartido}/storage/logs"

rm -rf "${release}"
mkdir -p "${release}"
tar -xzf "${paquete}" -C "${release}"
rm -f "${paquete}"

ln -sfn "${compartido}/.env" "${release}/web/.env"
ln -sfn "${compartido}/storage" "${release}/web/storage"

cd "${release}/web"
php artisan migrate --force --no-interaction
php artisan db:seed --class=RolesSeeder --force --no-interaction
php artisan storage:link --force
php artisan optimize

ln -sfn "${release}" "${destino}/current.nuevo"
mv -Tf "${destino}/current.nuevo" "${destino}/current"

php artisan queue:restart

tarea="* * * * * cd ${destino}/current/web && php artisan schedule:run >> /dev/null 2>&1"
{ crontab -l 2>/dev/null | grep -vF 'artisan schedule:run' || true; echo "${tarea}"; } | crontab -

ls -1dt "${destino}"/releases/* | tail -n +6 | xargs -r rm -rf

echo "Versión ${version} publicada."
