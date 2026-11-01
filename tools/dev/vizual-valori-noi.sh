#!/usr/bin/env bash
# G-vizual, a doua parte (WP-40, SEO partea B, B6): stilurile calculate ale paginilor publice cu valorile vechi față de
# valorile noi ale vitezei, pe codul curent. Partea întâi a lui G-vizual (capturi-publice.sh) compară codul curent cu
# Bioscem pe înghețarea Bioscem (valorile vechi); aceasta dovedește că valorile noi ale nucleului nu schimbă aspectul:
#   - css_js_in_fisiere = 1 (CSS-ul și JavaScript-ul fix al șablonului din fișierele minificate, nu din pagină);
#   - imagini_srcset = 1 (pozele cu variantele lor; aceeași poză afișată la aceeași mărime);
#   - sesiune_doar_la_nevoie = 1.
# Fonturile din nucleu (valoarea nouă a temei) sunt pe amândouă: unealta de capturi nu iese din calculator (Google Fonts
# ar rămâne neîncărcat pe partea veche), deci fonturile se dovedesc altfel: aceleași familii și greutăți ca cererea spre
# Google Fonts, aceleași fișiere (tools/dev/test-seo.php E4, VERSIUNE.txt al fiecărui font).
# Două copii ale bazei de dezvoltare (cms_dev → <bază>_vizual_vechi și _vizual_nou), două servere ale codului curent
# (CMS_DEV_PORT+24 și +25), același pachet demo, apoi capturi-publice.php compara.
# Trece doar cu 0 diferențe nedeclarate. Bazele și serverele se șterg și se opresc la final.
#
#   tools/dev/vizual-valori-noi.sh [--latimi=390,1366] [--pagini=redus]
source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

verifica_mediul
exporta_mediul_aplicatiei
P1=$((CMS_DEV_PORT + 24))
P2=$((CMS_DEV_PORT + 25))
LUCRU="$CMS_DEV_DIR/vizual-valori-noi"
BAZA_VECHE="${CMS_DEV_DB}_vizual_vechi"
BAZA_NOUA="${CMS_DEV_DB}_vizual_nou"
rm -rf "$LUCRU"
mkdir -p "$LUCRU"
PID1="$LUCRU/server-$P1.pid"
PID2="$LUCRU/server-$P2.pid"

curata() {
    local f
    for f in "$PID1" "$PID2"; do
        if [ -s "$f" ] && kill -0 "$(cat "$f")" 2>/dev/null; then
            opreste_server_php "$(cat "$f")"
        fi
    done
    mysql_dev -e "DROP DATABASE IF EXISTS \`$BAZA_VECHE\`; DROP DATABASE IF EXISTS \`$BAZA_NOUA\`" 2>/dev/null || true
    rm -rf "$LUCRU"
}
trap curata EXIT

for baza in "$BAZA_VECHE" "$BAZA_NOUA"; do
    mysql_dev -e "DROP DATABASE IF EXISTS \`$baza\`; CREATE DATABASE \`$baza\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
        || die "nu pot face baza $baza"
    mysqldump --default-character-set=utf8mb4 --socket="$CMS_DEV_SOCK" -u"$CMS_DEV_DB_USER" ${CMS_DEV_DB_PAROLA:+-p"$CMS_DEV_DB_PAROLA"} \
        --single-transaction --routines=false "$CMS_DEV_DB" | mysql_dev "$baza" || die "nu pot copia $CMS_DEV_DB în $baza"
done
valori() {
    local baza="$1" v="$2"
    mysql_dev "$baza" -e "REPLACE INTO settings (\`key\`, value) VALUES ('css_js_in_fisiere', '$v'), ('imagini_srcset', '$v'),
        ('sesiune_doar_la_nevoie', '$v'), ('cache_pagini_extins', '0'), ('cache_pages_enabled', '0')" || die "nu pot scrie setările în $baza"
}
valori "$BAZA_VECHE" 0
valori "$BAZA_NOUA" 1
# Variantele pozelor (o dată; ce e la zi se sare), ca srcset să aibă ce arăta.
"${PHP_DEV[@]}" "$RADACINA/scripts/optimizeaza-imagini.php" --aplica > "$LUCRU/poze.txt" 2>&1 || die "optimizeaza-imagini.php: $(tail -3 "$LUCRU/poze.txt")"
"${PHP_DEV[@]}" "$RADACINA/tools/dev/minifica-sablon.php" --verifica > "$LUCRU/minifica.txt" 2>&1 \
    || die "fișierele minificate ale șablonului nu sunt la zi: tools/dev/php.sh tools/dev/minifica-sablon.php"

porneste() {
    local port="$1" pid="$2"
    shift 2
    if curl -fsS --noproxy '*' -o /dev/null "http://127.0.0.1:$port/health" 2>/dev/null; then
        die "pe portul $port răspunde deja un server (nepornit de aici); oprește-l întâi"
    fi
    (
        cd "$RADACINA"
        env "$@" PHP_CLI_SERVER_WORKERS=2 nohup "${PHP_DEV[@]}" -S "127.0.0.1:$port" -t "$RADACINA/public" "$RADACINA/tools/dev/router.php" \
            >> "$LUCRU/server-$port.log" 2>&1 &
        echo $! > "$pid"
    )
    for _ in $(seq 1 50); do
        curl -fsS --noproxy '*' -o /dev/null "http://127.0.0.1:$port/health" 2>/dev/null && return 0
        sleep 0.2
    done
    die "serverul de pe portul $port nu pornește; vezi $LUCRU/server-$port.log"
}
porneste "$P1" "$PID1" DB_DATABASE="$BAZA_VECHE" SITE_DATE_DOSAR="$RADACINA/tools/dev/site-demo" APP_URL="http://127.0.0.1:$P1"
porneste "$P2" "$PID2" DB_DATABASE="$BAZA_NOUA" SITE_DATE_DOSAR="$RADACINA/tools/dev/site-demo" APP_URL="http://127.0.0.1:$P2"

# Verificarea de bază: pagina veche are CSS-ul în ea, cea nouă fișierele minificate și srcset.
VECHE="$(curl -fsS --noproxy '*' "http://127.0.0.1:$P1/magazin")" || die "magazinul (valorile vechi) nu răspunde"
NOUA="$(curl -fsS --noproxy '*' "http://127.0.0.1:$P2/magazin")" || die "magazinul (valorile noi) nu răspunde"
grep -q '\.bv-header-search-shell{' <<< "$VECHE" && ! grep -q 'srcset=' <<< "$VECHE" \
    || die "serverul valorilor vechi nu are CSS-ul șablonului în pagină (sau are srcset)"
grep -q '/assets/css/sablon-site.min.css?v=' <<< "$NOUA" && grep -q 'srcset="/uploads/_variante/' <<< "$NOUA" \
    && ! grep -q '\.bv-header-search-shell{' <<< "$NOUA" || die "serverul valorilor noi nu are fișierele șablonului și srcset"
echo "  OK    valorile vechi: CSS-ul și scripturile în pagină, fără srcset; valorile noi: fișierele minificate, srcset"

"${PHP_DEV[@]}" "$RADACINA/tools/dev/capturi-publice.php" compara --baza="http://127.0.0.1:$P1" --curent="http://127.0.0.1:$P2" \
    --eticheta="valorile noi ale vitezei (WP-40)" --fara-declaratii --dosar=valori-noi "$@"
