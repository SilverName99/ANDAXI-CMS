#!/usr/bin/env bash
# Reface de la zero baza de test (implicit cms_dev) pe calea normală de
# instalare a proiectului: scripts/install.php (migrările, seed-ul neutru, primul
# administrator; docs/MIGRARI.md) + scripts/seed.php, apoi datele demo inventate
# din tools/dev/date-demo.php și licența „toate”.
#
#   tools/dev/instaleaza.sh              # bază nouă (prin migrări) + date demo
#   tools/dev/instaleaza.sh --fara-demo  # doar install + seed
#   tools/dev/instaleaza.sh --pe-vechi   # bază făcută ca înainte de migrări (tools/dev/migrari/pe-vechi.php:
#                                        # install.php de atunci + funcțiile ensure*), seed, date demo,
#                                        # licența, apoi baseline-ul (scripts/migreaza.php --aplica):
#                                        # baza unui site vechi trecut pe codul comun
#   tools/dev/instaleaza.sh --sterge-baza  # doar șterge baza $CMS_DEV_DB (de exemplu una făcută pentru o poartă)
#   tools/dev/instaleaza.sh --php-min    # doar instalează PHP-ul minim (php_min din config/app.php),
#                                        # ca testele să ruleze și cu el (CMS_DEV_PHP=php8.3 …); nu atinge baza
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
pe_vechi=0
for argument in "$@"; do
    case "$argument" in
        --fara-demo) cu_demo=0 ;;
        --pe-vechi) pe_vechi=1 ;;
        --sterge-baza)
            mysql_dev -e "DROP DATABASE IF EXISTS \`$CMS_DEV_DB\`;"
            echo "Baza $CMS_DEV_DB a fost ștearsă."
            exit 0
            ;;
        *) die "argument necunoscut: $argument (vezi începutul fișierului)" ;;
    esac
done

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
if [ "$pe_vechi" = 1 ]; then
    echo "== tools/dev/migrari/pe-vechi.php (baza ca înainte de migrări)"
    "${PHP_DEV[@]}" tools/dev/migrari/pe-vechi.php
else
    echo "== scripts/install.php"
    "${PHP_DEV[@]}" scripts/install.php
fi
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
if [ "$pe_vechi" = 1 ]; then
    # Ca la adoptarea unui site vechi: baseline-ul găsește schema, grupul C convertește datele vechi.
    echo "== scripts/migreaza.php --aplica (baseline-ul pe baza făcută ca înainte)"
    "${PHP_DEV[@]}" scripts/migreaza.php --aplica | tail -1
fi

echo
echo "Gata. Admin: $CMS_DEV_ADMIN_EMAIL / parola în $CMS_DEV_DIR/admin-parola.txt"
echo "Pornește serverul cu: tools/dev/server.sh"
