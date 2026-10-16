#!/usr/bin/env bash
#
# Actualiza SISPAM en el servidor con un paquete hecho con `git archive`,
# mientras el servidor no descarga del repositorio.
#
#   En tu equipo, desde la carpeta del proyecto:
#     git archive --format=tar.gz -o ../sispam-v1.1.1.tar.gz v1.1.1
#     scp ../sispam-v1.1.1.tar.gz scripts/actualizar-con-paquete.sh root@<servidor>:/root/
#
#   En el servidor, como root:
#     bash /root/actualizar-con-paquete.sh /root/sispam-v1.1.1.tar.gz
#
# Arma la versión nueva en una carpeta al lado y solo al final la pone en el
# lugar de la que está funcionando: si algo falla antes, esa no se toca. La
# anterior queda como <carpeta>-anterior, para volver atrás con dos `mv`.
#
# Las cachés se arman después del cambio de carpeta, no antes: guardan rutas
# absolutas (logs, vistas compiladas) y quedarían apuntando a la carpeta
# temporal.
#
# Variable opcional: SISPAM_DIR (/var/www/sispam2).

set -Eeuo pipefail

PAQUETE="${1:-}"
APP="${SISPAM_DIR:-/var/www/sispam2}"
APP="${APP%/}"
NUEVA="${APP}-nueva"
ANTERIOR="${APP}-anterior"

sale() {
    echo "✗ $*" >&2
    exit 1
}

# --- Antes de tocar nada -----------------------------------------------------

[[ $EUID -eq 0 ]] || sale "Córrelo como root."
[[ -n "$PAQUETE" && -f "$PAQUETE" ]] || sale "Uso: bash $0 /root/sispam-vX.Y.Z.tar.gz"
[[ -f "$APP/artisan" && -f "$APP/.env" ]] || sale "No encuentro SISPAM en $APP (falta artisan o .env)."
[[ ! -e "$NUEVA" ]] || sale "Ya existe $NUEVA, de un intento anterior. Revísala y bórrala: rm -rf $NUEVA"
[[ ! -e "$ANTERIOR" ]] || sale "Ya existe $ANTERIOR, de la actualización pasada. Si la versión que está funcionando va bien, bórrala (rm -rf $ANTERIOR) y vuelve a correr esto."
command -v composer >/dev/null || sale "No encuentro composer en el servidor."

# El paquete puede venir con los archivos en la raíz o dentro de una carpeta
# (git archive --prefix=...).
LISTA="$(tar -tzf "$PAQUETE")" || sale "No pude leer $PAQUETE: ¿está completo?"
if grep -qx 'artisan' <<<"$LISTA"; then
    QUITAR=0
elif grep -qE '^[^/]+/artisan$' <<<"$LISTA"; then
    QUITAR=1
else
    sale "El paquete no trae artisan: no parece un paquete de SISPAM."
fi

# Quien escribe en storage/ es quien corre artisan: así los logs y las cachés
# quedan a su nombre y PHP los puede seguir escribiendo.
DUENO="$(stat -c %U "$APP/storage")"
PASAR_A_PHP=""
if [[ "$DUENO" == root ]]; then
    # storage/ de root: si PHP corre con un solo usuario, en la versión nueva
    # storage/ queda de ese usuario, que es como debe estar.
    USUARIOS_PHP="$(ps -eo user:32=,comm= | awk '$1 != "root" && $2 ~ /^(php-fpm|apache2|httpd)/ {print $1}' | sort -u)"
    if [[ -n "$USUARIOS_PHP" && "$(wc -l <<<"$USUARIOS_PHP")" -eq 1 ]]; then
        DUENO="$USUARIOS_PHP"
        PASAR_A_PHP=1
    fi
fi

artisan() {
    if [[ "$DUENO" == root ]]; then
        php artisan "$@"
    elif command -v sudo >/dev/null; then
        sudo -u "$DUENO" -- php artisan "$@"
    else
        runuser -u "$DUENO" -- php artisan "$@"
    fi
}

leer_env() {
    sed -n "s/^$1=//p" "$APP/.env" | tail -n 1 | tr -d '\r' | sed -E 's/^"(.*)"$/\1/'
}

ETAPA="armando"

al_fallar() {
    echo "" >&2
    case "$ETAPA" in
        armando)
            echo "✗ Falló antes de cambiar de versión: la que está funcionando no se tocó." >&2
            echo "  Revisa el error de arriba. Para reintentar, borra la carpeta a medias: rm -rf $NUEVA" >&2
            ;;
        migrada)
            echo "✗ Falló antes de cambiar de versión: la que está funcionando no se tocó," >&2
            echo "  pero las migraciones nuevas ya corrieron en la base. Revisa el error de arriba." >&2
            echo "  Para reintentar, borra la carpeta a medias: rm -rf $NUEVA" >&2
            ;;
        cambiada)
            echo "✗ Falló después de cambiar de versión. Si SISPAM no abre, vuelve a la anterior:" >&2
            echo "    mv $APP ${APP}-fallida && mv $ANTERIOR $APP && systemctl reload $RECARGAR" >&2
            ;;
    esac
}
trap al_fallar ERR

echo "→ Carpeta: $APP · usuario de PHP: $DUENO · paquete: $PAQUETE"

# --- 1. Copia de la base -----------------------------------------------------
# Solo si root entra a MySQL sin clave (lo normal en Ubuntu). Si no, sigue.

BASE="$(leer_env DB_DATABASE)"
RESPALDO="/root/respaldo-${BASE:-sispam}-$(date +%Y%m%d-%H%M%S).sql.gz"
echo "→ 1/5 Copia de la base"
if ( umask 077; mysqldump --single-transaction --no-tablespaces "$BASE" | gzip > "$RESPALDO" ) 2>/dev/null; then
    echo "  Lista: $RESPALDO"
else
    rm -f "$RESPALDO"
    echo "  ⚠ No se pudo (¿MySQL le pide clave a root?). Sigo sin copia."
fi

# --- 2. La versión nueva, al lado --------------------------------------------

echo "→ 2/5 Armando la versión nueva en $NUEVA"
mkdir "$NUEVA"
tar -xzf "$PAQUETE" -C "$NUEVA" --strip-components="$QUITAR"

cp -p "$APP/.env" "$NUEVA/.env"

# storage/ trae los logs y lo que se haya guardado: se lleva tal cual, con sus
# dueños y permisos.
rm -rf "$NUEVA/storage"
cp -a "$APP/storage" "$NUEVA/storage"
if [[ -n "$PASAR_A_PHP" ]]; then
    echo "  storage/ era de root: en la versión nueva queda de $DUENO, el usuario de PHP."
    chown -R "$DUENO": "$NUEVA/storage"
fi

# vendor/ de la que está funcionando: composer solo instala lo que cambie.
[[ ! -d "$APP/vendor" ]] || cp -a "$APP/vendor" "$NUEVA/vendor"

# El enlace public/storage (php artisan storage:link), si lo había.
if [[ -L "$APP/public/storage" || -e "$APP/public/storage" ]]; then
    cp -a "$APP/public/storage" "$NUEVA/public/storage"
fi

chown -R "$DUENO": "$NUEVA/bootstrap/cache"

# --- 3. Dependencias ---------------------------------------------------------
# --no-scripts: los scripts de composer corren artisan, y como root dejarían
# archivos que PHP no puede escribir. Los assets de Filament vienen en el
# paquete.

echo "→ 3/5 Dependencias"
cd "$NUEVA"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader \
    --no-interaction --no-progress --no-scripts

# --- 4. Migraciones ----------------------------------------------------------
# Corren mientras la versión anterior sigue atendiendo, así que no hay página
# de mantenimiento. Si algún día una migración rompe la versión anterior,
# primero `php artisan down` en la que está funcionando.

echo "→ 4/5 Migraciones"
artisan migrate --force
ETAPA="migrada"

# --- 5. Cambio de versión ----------------------------------------------------

# OPcache guarda el código viejo en memoria hasta que PHP se recarga.
SERVICIOS="$(systemctl list-units --type=service --state=running --plain --no-legend 2>/dev/null \
    | awk '{print $1}' | grep -E '^(php[0-9.]*-fpm|apache2|httpd)\.service$' || true)"
RECARGAR="$(tr '\n' ' ' <<<"$SERVICIOS" | sed 's/ *$//')"
RECARGAR="${RECARGAR:-php8.3-fpm}"

echo "→ 5/5 Cambiando de versión"
cd /
mv "$APP" "$ANTERIOR"
mv "$NUEVA" "$APP"
ETAPA="cambiada"

cd "$APP"
artisan optimize:clear
artisan optimize

if [[ -z "$SERVICIOS" ]]; then
    echo "  ⚠ No encontré PHP-FPM ni Apache corriendo para recargarlos."
fi
for SERVICIO in $SERVICIOS; do
    systemctl reload "$SERVICIO"
    echo "  Recargado $SERVICIO"
done

trap - ERR

URL="$(leer_env APP_URL)"
if [[ -n "$URL" ]] && command -v curl >/dev/null; then
    CODIGO="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${URL%/}/admin/login" || true)"
    echo "→ ${URL%/}/admin/login respondió ${CODIGO:-000} (200 o 302: bien · 500: algo falló · 000: no se pudo probar desde aquí)"
fi

echo ""
echo "✓ Listo. La versión anterior quedó en $ANTERIOR."
echo "  Si algo no funciona, vuelve a ella:"
echo "    mv $APP ${APP}-fallida && mv $ANTERIOR $APP && systemctl reload $RECARGAR"
echo "  Cuando la nueva lleve unos días bien: rm -rf $ANTERIOR"
