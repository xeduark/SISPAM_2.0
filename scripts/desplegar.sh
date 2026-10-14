#!/usr/bin/env bash
#
# Actualiza SISPAM en el servidor a una etiqueta del repositorio.
#
#   scripts/desplegar.sh v1.0.1
#
# Lo corre el usuario dueño del código (sispam) desde la carpeta del proyecto.
# Despliega etiquetas, no ramas: lo que sale es exactamente lo que se probó.
# El primer despliegue es distinto: ver docs/despliegue.md.
#
# Variables opcionales: PHP_BIN, COMPOSER_BIN, PHP_FPM_SERVICIO (php8.3-fpm).

set -Eeuo pipefail

ETIQUETA="${1:-}"
PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_BIN:-composer}"
FPM="${PHP_FPM_SERVICIO:-php8.3-fpm}"

if [[ -z "$ETIQUETA" ]]; then
    echo "Uso: scripts/desplegar.sh <etiqueta>   (por ejemplo: scripts/desplegar.sh v1.0.1)" >&2
    exit 1
fi

cd "$(dirname "$0")/.."

if [[ ! -f .env ]]; then
    echo "No hay .env en $(pwd). El primer despliegue se hace a mano: docs/despliegue.md." >&2
    exit 1
fi

git fetch --tags --force --quiet origin

if ! git rev-parse -q --verify "refs/tags/${ETIQUETA}" >/dev/null; then
    echo "La etiqueta ${ETIQUETA} no existe en el repositorio." >&2
    exit 1
fi

ANTERIOR="$(git describe --tags --exact-match 2>/dev/null || git rev-parse --short HEAD)"
echo "→ SISPAM pasa de ${ANTERIOR} a ${ETIQUETA}"

# Mientras se actualiza, quien entre ve la página de mantenimiento en español.
"$PHP" artisan down --render="errors::503" --retry=60

falla() {
    echo "" >&2
    echo "✗ El despliegue falló. SISPAM sigue en mantenimiento." >&2
    echo "  Revisa el error de arriba. Para volver a ${ANTERIOR}:" >&2
    echo "    git checkout --force ${ANTERIOR}" >&2
    echo "    ${COMPOSER} install --no-dev --optimize-autoloader --no-interaction" >&2
    echo "    ${PHP} artisan optimize && sudo systemctl reload ${FPM} && ${PHP} artisan up" >&2
    echo "  Si alcanzó a correr migraciones nuevas, revísalas antes de volver." >&2
}
trap falla ERR

git checkout --force --quiet "tags/${ETIQUETA}"

"$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress

"$PHP" artisan migrate --force

# Cachés de configuración, rutas, vistas, eventos, íconos y Filament.
"$PHP" artisan optimize:clear
"$PHP" artisan optimize

# OPcache guarda el código viejo en memoria hasta que PHP-FPM se recarga.
sudo systemctl reload "$FPM"

"$PHP" artisan up

trap - ERR
echo "✓ SISPAM quedó en ${ETIQUETA}"
