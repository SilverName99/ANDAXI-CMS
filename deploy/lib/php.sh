# shellcheck shell=bash
# PHP-ul liniei de comandă pentru scripturile din deploy/ (docs/DEPLOY.md).
#
# Ordinea: variabila PHP, dacă e dată (poate avea și opțiuni, ca în docs: PHP="/opt/alt/php83/usr/bin/php -d
# extension=sodium.so"); altfel /opt/alt/php83/usr/bin/php (Hostinger), altfel php. Dacă PHP-ul ales nu încarcă singur
# sodium, dar îl are pe disc (așa e pe Hostinger), se adaugă -d extension=sodium.so. Scripturile PHP primesc comanda în
# PLATFORMA_PHP și o folosesc la fel pentru tot ce pornesc (migreaza.php, diagnostic.php).

set -euo pipefail

if [ -z "${PHP:-}" ]; then
    if [ -x /opt/alt/php83/usr/bin/php ]; then
        PHP=/opt/alt/php83/usr/bin/php
    else
        PHP=php
    fi
fi
read -r -a PHP_COMANDA <<< "$PHP"
command -v "${PHP_COMANDA[0]}" >/dev/null 2>&1 || { echo "EROARE: nu găsesc PHP-ul „${PHP_COMANDA[0]}” (variabila PHP)." >&2; exit 2; }
if ! "${PHP_COMANDA[@]}" -r 'exit(extension_loaded("sodium") ? 0 : 1);' 2>/dev/null; then
    if "${PHP_COMANDA[@]}" -d extension=sodium.so -r 'exit(extension_loaded("sodium") ? 0 : 1);' 2>/dev/null; then
        PHP_COMANDA+=(-d extension=sodium.so)
    fi
fi
PLATFORMA_PHP="${PHP_COMANDA[*]}"
export PLATFORMA_PHP
DOSAR_DEPLOY="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
