#!/usr/bin/env bash
# Reface de la zero baza de test (implicit cms_dev) pe calea normală de
# instalare a proiectului: scripts/install.php + scripts/seed.php, apoi datele
# demo inventate din tools/dev/date-demo.php.
#
#   tools/dev/instaleaza.sh            # bază nouă + date demo
#   tools/dev/instaleaza.sh --fara-demo  # doar install + seed
#   tools/dev/instaleaza.sh --php-min    # doar instalează PHP-ul minim (php_min din config/app.php),
#                                        # ca testele să ruleze și cu el (CMS_DEV_PHP=php8.0 …); nu atinge baza
#
# ATENȚIE: șterge baza $CMS_DEV_DB (doar dacă numele începe cu cms_).

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

if [ "${1:-}" = "--php-min" ]; then
    versiune="$(php_min)"
    [ -n "$versiune" ] || die "nu găsesc 'php_min' în config/app.php"
    # Aceleași extensii ca pe găzduire (docs/DEZVOLTARE.md, „Cerințe”), din depozitul
    # ondrej/php deja configurat în apt (sodium vine cu php-common).
    pachete=()
    for extensie in cli mysql mbstring gd curl xml zip intl; do
        pachete+=("php$versiune-$extensie")
    done
    echo "== PHP $versiune (php_min): ${pachete[*]}"
    prefix=()
    [ "$(id -u)" != 0 ] && prefix=(sudo)
    "${prefix[@]}" apt-get install -y "${pachete[@]}" \
        || die "instalarea a eșuat (listele apt vechi? încearcă întâi: ${prefix[*]} apt-get update)"
    "php$versiune" -v | head -1
    echo "Gata. Testele cu PHP $versiune: CMS_DEV_PHP=php$versiune tools/dev/smoke.sh --toate-pachetele"
    exit 0
fi

verifica_mediul

cu_demo=1
[ "${1:-}" = "--fara-demo" ] && cu_demo=0

# Parolă nouă de admin pentru fiecare bază nouă.
rm -f "$CMS_DEV_DIR/admin-parola.txt"
exporta_mediul_aplicatiei

echo "== Baza $CMS_DEV_DB: o șterg și o creez din nou"
mysql_dev -e "DROP DATABASE IF EXISTS \`$CMS_DEV_DB\`; CREATE DATABASE \`$CMS_DEV_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Ce a rămas de la baza veche: sesiuni, cache de pagini, emailuri capturate, jurnale.
find "$RADACINA/storage/sessions" -maxdepth 1 -type f -name 'sess_*' -delete 2>/dev/null || true
find "$RADACINA/storage/cache" -mindepth 1 -type f ! -name '.gitkeep' -delete 2>/dev/null || true
rm -f "$CMS_DEV_DIR"/mail/*.eml "$CMS_DEV_DIR/php-erori.log" "$RADACINA/storage/logs/pluginuri.log"
rm -rf "$RADACINA/public/uploads/demo"
rm -f "$RADACINA"/public/*.xml

cd "$RADACINA"
echo "== scripts/install.php"
"${PHP_DEV[@]}" scripts/install.php
echo "== scripts/seed.php"
"${PHP_DEV[@]}" scripts/seed.php
if [ "$cu_demo" = 1 ]; then
    echo "== tools/dev/date-demo.php"
    "${PHP_DEV[@]}" tools/dev/date-demo.php
fi
# Licența de test: toate pluginurile pornite (ca Bioscem azi). Alt pachet:
# tools/dev/php.sh tools/dev/licenta.php <prezentare|catalog|magazin|asociatie|toate>
echo "== Licența de test"
"${PHP_DEV[@]}" tools/dev/licenta.php toate

echo
echo "Gata. Admin: $CMS_DEV_ADMIN_EMAIL / parola în $CMS_DEV_DIR/admin-parola.txt"
echo "Pornește serverul cu: tools/dev/server.sh"
