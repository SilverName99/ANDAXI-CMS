#!/usr/bin/env bash
# Verifică sintaxa fiecărui fișier PHP care ajunge pe un site cu PHP-ul minim
# suportat (`php_min` din config/app.php: 8.3, decizia OD-48): o construcție mai
# nouă (din 8.4: proprietăți cu get/set, vizibilitate asimetrică, `new X()->m()`
# fără paranteze, funcții apărute după) pică aici înainte să pice pe server.
#
#   tools/dev/verifica-php.sh                      # cu php<php_min> (php8.3)
#   CMS_DEV_PHP=php8.4 tools/dev/verifica-php.sh   # cu altă versiune
#
# Dosarele: src/ views/ config/ scripts/ public/ bootstrap.php, database/ (migrările, pe care
# le rulează scripts/migreaza.php pe servere), deploy/ (stub-ul din public_html, Etapa 2) și, când
# există, tools/verificare/ și tools/sonda/ (rulează pe servere), plus tools/dev/lib/normalizeaza-html.php (normalizarea
# paginilor, pe care o încarcă tools/verificare/amprenta-site.php pe server, WP-11). Restul uneltelor din tools/dev/
# nu ajung pe site-uri; ele se verifică rulându-le cu CMS_DEV_PHP (smoke.sh,
# test-pluginuri.php). Funcțiile apărute după php_min nu le prinde `-l`; le prind
# testele rulate cu CMS_DEV_PHP=php<min>.
#
# Cod de ieșire 0 doar dacă toate fișierele trec.

set -euo pipefail
RADACINA="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MINIM="$(sed -n "s/^[[:space:]]*'php_min' => '\([0-9][0-9.]*\)'.*/\1/p" "$RADACINA/config/app.php" | head -1)"
[ -n "$MINIM" ] || { echo "EROARE: nu găsesc 'php_min' în config/app.php" >&2; exit 1; }
# Implicit PHP-ul minim, chiar dacă uneltele au pus CMS_DEV_PHP=php (comun.sh); altă
# versiune doar cerută anume, de exemplu CMS_DEV_PHP=php8.4.
PHP_LINT="${CMS_DEV_PHP:-php}"
[ "$PHP_LINT" = "php" ] && PHP_LINT="php$MINIM"
command -v "$PHP_LINT" >/dev/null 2>&1 \
    || { echo "EROARE: lipsește $PHP_LINT; îl instalezi cu: tools/dev/instaleaza.sh --php-min" >&2; exit 1; }

tinte=()
for cale in src views config scripts public bootstrap.php database deploy tools/verificare tools/sonda tools/dev/lib/normalizeaza-html.php; do
    [ -e "$RADACINA/$cale" ] && tinte+=("$RADACINA/$cale")
done

fisiere=0
erori=0
while IFS= read -r -d '' fisier; do
    fisiere=$((fisiere + 1))
    if ! iesire="$("$PHP_LINT" -d display_errors=1 -d log_errors=0 -d error_reporting=E_ALL -l "$fisier" 2>&1)"; then
        erori=$((erori + 1))
        echo "  EȘEC  ${fisier#"$RADACINA"/}: $(echo "$iesire" | grep -v '^Errors parsing' | tr '\n' ' ')"
    elif echo "$iesire" | grep -qE '(Deprecated|Warning|Fatal)'; then
        erori=$((erori + 1))
        echo "  EȘEC  ${fisier#"$RADACINA"/}: $(echo "$iesire" | tr '\n' ' ')"
    fi
done < <(find "${tinte[@]}" -type f -name '*.php' -print0 | sort -z)

versiune="$("$PHP_LINT" -r 'echo PHP_VERSION;')"
case "$versiune" in
    "$MINIM".*) ;;
    *) echo "ATENȚIE: verificarea a rulat cu PHP $versiune, nu cu PHP-ul minim $MINIM." ;;
esac
if [ "$erori" -gt 0 ]; then
    echo "verifica-php: $erori din $fisiere fișiere PHP nu trec de PHP $versiune (php_min $MINIM)."
    exit 1
fi
echo "verifica-php: toate cele $fisiere fișiere PHP trec de PHP $versiune (php_min $MINIM)."
