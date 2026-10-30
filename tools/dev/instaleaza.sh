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
#                                        # licența, apoi baseline-ul (scripts/migreaza.php --aplica) și
#                                        # pașii de activare: baza unui site vechi trecut pe codul comun
#   tools/dev/instaleaza.sh --sterge-baza  # doar șterge baza $CMS_DEV_DB (de exemplu una făcută pentru o poartă)
#   tools/dev/instaleaza.sh --php-min    # doar instalează PHP-ul minim (php_min din config/app.php),
#                                        # ca testele să ruleze și cu el (CMS_DEV_PHP=php8.3 …); nu atinge baza
#   tools/dev/instaleaza.sh --site=biovitality  # baza în forma unui site existent (docs/DEZVOLTARE.md, „Baza în forma
#                                        # unui site”): cms_dev_biovitality (sau CMS_DEV_DB), install + seed, înghețarea
#                                        # pachetului site-uri/<site>/ cu valorile publice (tools/dev/pachet-site.php
#                                        # emuleaza; cele private: înlocuitorii inventați), datele demo cu un antet cu
#                                        # {{cart_count}} și {{mobile_menu}} (date-demo.php --site), licența de
#                                        # dezvoltare a site-ului într-un dosar al ei (și pașii ei de activare). Nu atinge
#                                        # baza cms_dev, licența ei, parola adminului de test și dosarele comune
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

cu_demo=1
pe_vechi=0
site=""
for argument in "$@"; do
    case "$argument" in
        --site=*) site="${argument#--site=}" ;;
    esac
done
[ -z "$site" ] || alege_site_dev "$site"
verifica_mediul

for argument in "$@"; do
    case "$argument" in
        --fara-demo) cu_demo=0 ;;
        --pe-vechi) pe_vechi=1 ;;
        --site=*) ;;
        --sterge-baza)
            mysql_dev -e "DROP DATABASE IF EXISTS \`$CMS_DEV_DB\`;"
            echo "Baza $CMS_DEV_DB a fost ștearsă."
            exit 0
            ;;
        *) die "argument necunoscut: $argument (vezi începutul fișierului)" ;;
    esac
done

[ "$pe_vechi" = 1 ] && [ -n "$site" ] && die "--site și --pe-vechi nu merg împreună"
[ "$cu_demo" = 0 ] && [ -n "$site" ] && die "--site face și datele demo (fără --fara-demo)"
if [ -z "$site" ]; then
    # Parolă nouă de admin pentru fiecare bază nouă. Cu --site, aceeași ca a bazei cms_dev (fișierul e unul singur;
    # o parolă nouă ar lăsa adminul bazei cms_dev cu una pe care n-o mai știe nimeni, iar porțile care se loghează ar pica).
    rm -f "$CMS_DEV_DIR/admin-parola.txt"
fi
exporta_mediul_aplicatiei

echo "== Baza $CMS_DEV_DB: o șterg și o creez din nou"
mysql_dev -e "DROP DATABASE IF EXISTS \`$CMS_DEV_DB\`; CREATE DATABASE \`$CMS_DEV_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if [ -z "$site" ]; then
    # Ce a rămas de la baza veche: sesiuni, cache de pagini, emailuri capturate, jurnale. Cu --site nu (sunt și ale bazei
    # cms_dev; pozele demo le scrie din nou date-demo.php, aceleași).
    find "$RADACINA/storage/sessions" -maxdepth 1 -type f -name 'sess_*' -delete 2>/dev/null || true
    find "$RADACINA/storage/cache" -mindepth 1 -type f ! -name '.gitkeep' -delete 2>/dev/null || true
    rm -f "$CMS_DEV_DIR"/mail/*.eml "$CMS_DEV_DIR/php-erori.log" "$RADACINA/storage/logs/pluginuri.log"
    rm -rf "$RADACINA/public/uploads/demo"
    rm -f "$RADACINA"/public/*.xml
fi

cd "$RADACINA"
if [ "$pe_vechi" = 1 ]; then
    echo "== tools/dev/migrari/pe-vechi.php (baza ca înainte de migrări)"
    "${PHP_DEV[@]}" tools/dev/migrari/pe-vechi.php
elif [ -n "$site" ]; then
    # Instalarea cu pachetul demo: cu pachetul site-ului, runner-ul ar cere pasul înghețării (--site), iar acela valorile
    # private, care nu intră în baza de dezvoltare (OD-49). Înghețarea o pune emularea, mai jos, cu valorile publice.
    echo "== scripts/install.php (cu pachetul demo; înghețarea site-ului vine după)"
    SITE_DATE_DOSAR="$RADACINA/tools/dev/site-demo" "${PHP_DEV[@]}" scripts/install.php
else
    echo "== scripts/install.php"
    "${PHP_DEV[@]}" scripts/install.php
fi
echo "== scripts/seed.php"
"${PHP_DEV[@]}" scripts/seed.php
if [ -n "$site" ]; then
    # Ca la adoptare (pasul înghețării), înaintea datelor demo: ce scriu ele (numele magazinului, adresele @exemplu.ro)
    # rămâne, ca o setare schimbată de client pe site.
    echo "== tools/dev/pachet-site.php emuleaza site-uri/$site"
    "${PHP_DEV[@]}" tools/dev/pachet-site.php emuleaza "site-uri/$site"
fi
if [ "$cu_demo" = 1 ]; then
    echo "== tools/dev/date-demo.php${site:+ --site=$site}"
    "${PHP_DEV[@]}" tools/dev/date-demo.php ${site:+"--site=$site"}
fi
if [ -n "$site" ]; then
    # Licența de dezvoltare a site-ului (config/plugins.php → licente_dev), în dosarul ei (alege_site_dev); pe o bază la
    # zi, licenta.php rulează și pașii de activare (pagina /acorduri-gdpr, pragul ERP), ca prima cerere de după adoptare.
    echo "== Licența de dezvoltare a site-ului $site"
    "${PHP_DEV[@]}" tools/dev/licenta.php "$site"
    # Garda (planul de-Bioscem §7.2): nicio adresă reală, nici după datele demo.
    "${PHP_DEV[@]}" tools/dev/pachet-site.php garda "site-uri/$site"
else
    # Licența de test: toate pluginurile pornite (ca Bioscem azi). Alt pachet:
    # tools/dev/php.sh tools/dev/licenta.php <prezentare|catalog|magazin|asociatie|toate>
    echo "== Licența de test"
    "${PHP_DEV[@]}" tools/dev/licenta.php toate
fi
if [ "$pe_vechi" = 1 ]; then
    # Ca la adoptarea unui site vechi: baseline-ul găsește schema, grupul C convertește datele vechi.
    echo "== scripts/migreaza.php --aplica (baseline-ul pe baza făcută ca înainte)"
    "${PHP_DEV[@]}" scripts/migreaza.php --aplica | tail -1
    # Pașii de activare ai licenței au așteptat baseline-ul (pe o bază fără registru nu scriu nimic,
    # docs/MIGRARI.md, „Pașii de activare”); pe un site îi rulează prima cerere de după --aplica, aici
    # chiar acum, ca porțile de după să nu vadă o primă cerere care scrie în bază.
    echo "== Pașii de activare (după baseline)"
    "${PHP_DEV[@]}" tools/dev/licenta.php activari
fi

echo
echo "Gata. Admin: $CMS_DEV_ADMIN_EMAIL / parola în $CMS_DEV_DIR/admin-parola.txt"
if [ -n "$site" ]; then
    echo "Baza $CMS_DEV_DB, pachetul site-uri/$site. Pornește serverul cu: CMS_DEV_PORT=<alt port> tools/dev/server.sh --site=$site"
else
    echo "Pornește serverul cu: tools/dev/server.sh"
fi
