#!/usr/bin/env bash
# Crea en Azure los recursos del Patronato y el acceso de GitHub Actions.
# Se ejecuta una sola vez, en Azure Cloud Shell (Bash) o con la CLI de Azure.
#
# Uso: APP=patronato-xyz BD=patronato-bd-xyz bash crear-recursos.sh
# APP y BD deben ser nombres únicos en todo Azure.
set -euo pipefail

: "${APP:?Indica APP, el nombre del App Service}"
: "${BD:?Indica BD, el nombre del servidor PostgreSQL}"
GRUPO="${GRUPO:-patronato}"
REGION="${REGION:-eastus2}"
PLAN="${PLAN:-patronato-plan}"
SKU="${SKU:-B1}"
VERSION_PG="${VERSION_PG:-17}"
BD_USUARIO="${BD_USUARIO:-patronato_admin}"
BD_PASSWORD="${BD_PASSWORD:-$(openssl rand -base64 24 | tr -d '/+=')Aa1}"

echo "Grupo de recursos"
az group create --name "$GRUPO" --location "$REGION" --output none

echo "PostgreSQL (puede tardar varios minutos)"
az postgres flexible-server create \
    --resource-group "$GRUPO" --name "$BD" --location "$REGION" \
    --tier Burstable --sku-name Standard_B1ms --storage-size 32 \
    --version "$VERSION_PG" \
    --admin-user "$BD_USUARIO" --admin-password "$BD_PASSWORD" \
    --public-access 0.0.0.0 --yes --output none
az postgres flexible-server parameter set \
    --resource-group "$GRUPO" --server-name "$BD" \
    --name azure.extensions --value BTREE_GIST --output none
az postgres flexible-server db create \
    --resource-group "$GRUPO" --server-name "$BD" --database-name patronato --output none

echo "App Service"
az appservice plan create --resource-group "$GRUPO" --name "$PLAN" --is-linux --sku "$SKU" --output none
az webapp create --resource-group "$GRUPO" --plan "$PLAN" --name "$APP" --runtime "PHP:8.4" --output none
az webapp update --resource-group "$GRUPO" --name "$APP" --https-only true --output none
HOST=$(az webapp show --resource-group "$GRUPO" --name "$APP" --query defaultHostName --output tsv)
az webapp config set --resource-group "$GRUPO" --name "$APP" \
    --startup-file "bash /home/site/wwwroot/scripts/azure/inicio.sh" \
    --always-on true --ftps-state Disabled --output none

az webapp config appsettings set --resource-group "$GRUPO" --name "$APP" --output none --settings \
    APP_NAME=Patronato \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY="base64:$(openssl rand -base64 32)" \
    APP_URL="https://${HOST}" \
    APP_LOCALE=es \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    DB_CONNECTION=pgsql \
    DB_HOST="${BD}.postgres.database.azure.com" \
    DB_PORT=5432 \
    DB_DATABASE=patronato \
    DB_USERNAME="$BD_USUARIO" \
    DB_PASSWORD="$BD_PASSWORD" \
    DB_SSLMODE=require \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    SCM_DO_BUILD_DURING_DEPLOYMENT=false \
    WEBSITES_ENABLE_APP_SERVICE_STORAGE=true

echo "Acceso de GitHub Actions (publish profile)"
az resource update --resource-group "$GRUPO" --namespace Microsoft.Web     --resource-type basicPublishingCredentialsPolicies --parent "sites/${APP}" --name scm     --set properties.allow=true --output none
az webapp deployment list-publishing-profiles --resource-group "$GRUPO" --name "$APP" --xml > "publish-profile-${APP}.xml"

cat <<FIN

Listo. En GitHub, Settings > Environments > produccion, agrega:

  Secreto
    AZURE_WEBAPP_PUBLISH_PROFILE   todo el contenido de publish-profile-${APP}.xml

  Variables
    AZURE_WEBAPP_NAME              ${APP}
    AZURE_WEBAPP_HOST              ${HOST}

Después borra publish-profile-${APP}.xml: da acceso para publicar en el sitio.

Guarda la contraseña de PostgreSQL en un lugar seguro:
    Usuario: ${BD_USUARIO}
    Contraseña: ${BD_PASSWORD}

Sitio: https://${HOST}
FIN
