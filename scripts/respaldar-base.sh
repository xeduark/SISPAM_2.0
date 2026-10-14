#!/usr/bin/env bash
#
# Copia de seguridad de la base de SISPAM: un archivo comprimido por corrida,
# y se borran los de más de 30 días.
#
#   scripts/respaldar-base.sh
#
# En la base vive la auditoría: quién consultó qué documento y cuándo. Perderla
# es perder el rastro que exige la protección de datos de salud.
#
# Usuario y clave salen de ~/.my.cnf (chmod 600), nunca de este archivo ni del
# repositorio. Esta copia queda en el mismo servidor: súbela también a otro
# sitio (ver docs/despliegue.md, «Copias de seguridad»).
#
# Variables opcionales: SISPAM_DB (sispam), SISPAM_RESPALDOS
# (/var/backups/sispam), SISPAM_RESPALDOS_DIAS (30).

set -Eeuo pipefail

BASE="${SISPAM_DB:-sispam}"
DESTINO="${SISPAM_RESPALDOS:-/var/backups/sispam}"
DIAS="${SISPAM_RESPALDOS_DIAS:-30}"

# Solo el dueño puede leer las copias: tienen datos de pacientes.
umask 077
mkdir -p "$DESTINO"

ARCHIVO="${DESTINO}/sispam-$(date +%Y%m%d-%H%M%S).sql.gz"

# --single-transaction: copia consistente sin bloquear a quien está consultando.
mysqldump --single-transaction --routines --triggers --no-tablespaces \
    --default-character-set=utf8mb4 "$BASE" | gzip -9 > "${ARCHIVO}.parcial"

mv "${ARCHIVO}.parcial" "$ARCHIVO"
gzip -t "$ARCHIVO"

find "$DESTINO" -name 'sispam-*.sql.gz' -mtime "+${DIAS}" -delete

echo "Copia lista: ${ARCHIVO} ($(du -h "$ARCHIVO" | cut -f1))"
