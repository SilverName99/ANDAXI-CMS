#!/usr/bin/env bash
# Compară HTML-ul paginilor-cheie ale site-ului cu cel al unui cod de bază, pe
# aceeași bază de date: cu pluginurile Bioscem pornite, site-ul trebuie să arate
# exact ca Bioscem azi.
#
#   tools/dev/compara-cu-baza.sh                                # față de codul Bioscem însuși ($CMS_DEV_REPO_BS),
#                                                               # la „ultimul commit revizuit” din docs/de-portat.md
#   tools/dev/compara-cu-baza.sh --baza-repo=<clonă>@<commit>   # față de un repo vechi, la un commit anume
#   tools/dev/compara-cu-baza.sh --baza=f5ae5ea                 # față de un commit din acest repo (istoric: codul
#                                                               # dinaintea pluginurilor; scoate diferențele SEO și porturile)
#   tools/dev/compara-cu-baza.sh --licenta=bioscem              # cu licența de dezvoltare a Bioscem (config/plugins.php,
#                                                               # licente_dev), pusă înainte; la final se pune la loc „toate”
#
# Codul de bază se scoate cu `git archive`: din repo-ul vechi în
# $CMS_DEV_DIR/baza-<repo>-<commit>, dintr-un commit al acestui repo în
# $CMS_DEV_DIR/baza-<commit>. Pornește pe portul următor ($CMS_DEV_PORT + 1), cu
# același mediu, aceeași bază și același PHP (CMS_DEV_PHP); ambele servere primesc
# aceleași cereri (vizitator, client, admin), iar răspunsurile (codul, Location,
# anteturile: Content-Type, Cache-Control, X-Robots-Tag, cookie-urile … și corpul) se
# compară după ce se scot tokenurile și versiunile fișierelor. Diferențele (anteturile
# în capul fiecărui fișier): $CMS_DEV_DIR/compara/<pagina>.diff.
# Cod de ieșire 0 doar dacă toate paginile sunt identice.
#
# Față de Bioscem (implicit) nu se scoate nimic din livrarea 1 SEO și nimic din
# $porturiBioscem: codul Bioscem le are deja, deci trebuie să iasă octet cu octet.
# Se scot doar diferențele declarate explicit, cu motivul lor, în compara-cu-baza.php
# ($deBioscem, $porturiBiovitality, $porturiVertij, $porturiGrafo și marcajele
# <!--port:…-->), în ambele moduri. O pagină care în codul de bază cade și aici merge
# ($coduriDeBioscem, de exemplu /checkout/succes deschis direct) trece doar cu exact
# codurile declarate și cu textul declarat pe pagina curentă.
#
# Tot față de un repo vechi: harta adreselor vechi se cere din pachetul site-ului și din
# cea scrisă în codul de bază (config/redirecturi.php), iar cele două liste trebuie să fie
# aceleași. Față de Bioscem, și salvările din admin (ce rămâne în bază după un POST: cheia
# ofertelor BBD, categoriile unei postări, duplicarea): aceeași cerere pe ambele servere,
# pe rând, cu baza pusă la loc înainte de fiecare și la final (tools/dev/lib/salvari-admin.php).
#
# Diferențele față de Bioscem care nu se văd în HTML (deci nu le prinde comparația;
# fiecare e scrisă și în docs/DEZVOLTARE.md sau docs/PLUGINURI.md):
#  - scripts/fan-tracking-sync.php: fără cele două `use` care scriau avertismente la
#    fiecare rulare (reparația f5ae5ea, „Erori moștenite” 1) și fără cele trei ALTER TABLE
#    de la fiecare rulare (coloanele le fac migrările, WP-05);
#  - cronurile (erp-sync, bt-ipay-sync, fan-*, newsletter-campaigns, abandoned-cart-emails):
#    garda Plugins::cronPermis la început (cu pluginul pornit rulează ca înainte);
#  - scripts/install.php: instalarea prin migrări, seed-ul neutru și pașii de activare, iar pe
#    o bază care are administratori refuză (iese cu 1, fără să scrie nimic; docs/MIGRARI.md,
#    „Instalarea”); fără parola de admin de rezervă; config/app.php are în plus licența și
#    php_min; scripts/diagnostic.php: licența și php_min;
#  - public/index.php, src/Http/rute.php, src/Http/Router.php: rutele mutate într-un fișier
#    și garda pluginurilor; public/.htaccess: doar un comentariu;
#  - public/index.php: poarta (App\Support\Verificare, docs/VERIFICARE.md), prima. Fără nicio
#    bază configurată (nici .env, nici DB_DATABASE) răspunde 503 „neconfigurat”, unde Bioscem
#    ar merge fără bază, cu textele implicite; cu SITE_COPIE_VERIFICARE=1 cere parola. Aici ambele
#    servere au baza și nu au variabila, deci comparația arată doar că, așa, poarta nu face nimic
#    (pe Bioscem .env există mereu);
#  - public/assets/js/floating-cart.js: fără câmpul de cupon doar când cupoanele sunt oprite
#    (cu pluginul pornit, la fel ca Bioscem);
#  - bootstrap.php: în linia de comandă (cronurile, scripturile) nu mai pornește o sesiune PHP,
#    deci nu mai lasă câte un fișier gol în storage/sessions la fiecare rulare („Erori moștenite” 5,
#    site-uri/bioscem/ce-se-schimba.md); paginile (serverul web, și `php -S` de aici) pornesc
#    sesiunea exact ca Bioscem. Dovada: test-migrari.php (S) și smoke.sh (cronurile) numără
#    fișierele de sesiune;
#  - scripts/migreaza.php și src/Support/Migrari/ (runner-ul migrărilor, docs/MIGRARI.md): noi;
#    database/migrari/ (migrările de bază 0001–0024, WP-03): pe o bază făcută de cod găsesc tot,
#    fără nicio schimbare de schemă (tools/dev/migrari/genereaza-fixture.php --verifica);
#  - garda R1 (Migrari\Garda, WP-05) în capul celor 36 de funcții ensure* și asigura*: pe o bază
#    la zi cu migrările nu mai trimit nicio instrucțiune de schemă, la nicio cerere (dovada:
#    tools/dev/test-instalare.php și smoke.sh numără instrucțiunile cu ContorSql, doar în
#    dezvoltare). Ambele servere de aici lucrează pe aceeași bază la zi, deci HTML-ul nu se
#    schimbă; pe o bază fără registru (Bioscem înainte de adoptare) funcțiile lucrează ca înainte;
#  - /health: a doua linie „schema: <stare>” și 503 „indisponibil” cu baza în urma codului;
#    bannerul „Baza de date nu e la zi cu codul” din admin (doar administratorul general, doar
#    cu baza în urmă sau fără registru: aici nu apare);
#  - pașii de activare ai pluginurilor (config/activari.php, App\Support\Activari): la prima
#    cerere după ce un plugin devine „pornit” (și în cronul erp-sync), o singură dată pe bază,
#    doar pe o bază la zi cu migrările (înainte de --aplica nu scriu nimic);
#    pagina /acorduri-gdpr ștearsă nu mai reapare (ensureOptionalSchema o readucea);
#    erp_trimite_din se scrie doar dacă lipsește (pe Bioscem e înghețat la '' și rămâne);
#  - tot din garda R1, ștergerile adminului rămân (site-uri/bioscem/ce-se-schimba.md): toate
#    categoriile de blog șterse nu mai revin ca cele patru ale Bioscem (ensureOptionalSchema le
#    punea într-un tabel gol), iar o recenzie copiată din tabelul vechi `reviews` și ștearsă nu
#    se mai copiază din nou (ensureOptionalSchema și ensureProductCustomSchema o copiau la fiecare
#    cerere; acum o dată, recenzii/0023). Aici nu se văd (datele demo au categorii și nicio
#    recenzie veche ștearsă); dovada: T10 din test-migrari.php (codul comun nu le readuce, codul
#    Bioscem da);
#  - fișierele scoase la copiere (docs/templates, scripturile unice Bioscem, vezi
#    docs/DEZVOLTARE.md) și config/redirecturi.php, mutat în site-uri/bioscem/redirecturi.json.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
verifica_mediul
exporta_mediul_aplicatiei

MOD="repo"
REPO_BAZA=""
COMMIT=""
LICENTA_COMPARATIE=""
for argument in "$@"; do
    case "$argument" in
        --licenta=*) LICENTA_COMPARATIE="${argument#--licenta=}" ;;
        --baza-repo=*)
            MOD="repo"
            valoare="${argument#--baza-repo=}"
            REPO_BAZA="${valoare%@*}"
            [ "$valoare" != "$REPO_BAZA" ] && COMMIT="${valoare##*@}"
            ;;
        --baza-repo) MOD="repo" ;;
        --baza=*) MOD="commit"; COMMIT="${argument#--baza=}" ;;
        --*) die "argument necunoscut: $argument" ;;
        # Ca până acum: un commit din acest repo.
        *) MOD="commit"; COMMIT="$argument" ;;
    esac
done

PORT_CURENT="$CMS_DEV_PORT"
if [ "$MOD" = "repo" ]; then
    REPO_BAZA="${REPO_BAZA:-$CMS_DEV_REPO_BS}"
    [ -d "$REPO_BAZA" ] || die "clona de bază $REPO_BAZA lipsește (CMS_DEV_REPO_BS sau --baza-repo=<clonă>@<commit>)."
    # Al cărui site e clona (pentru commit-ul revizuit și pentru pachetul de date al site-ului).
    SITE_BAZA=""
    for pereche in "bioscem:$CMS_DEV_REPO_BS" "biovitality:$CMS_DEV_REPO_BV" "vertij:$CMS_DEV_REPO_VT" "grafoanaytis:$CMS_DEV_REPO_GR"; do
        [ "$(realpath "$REPO_BAZA")" = "$(realpath -m "${pereche#*:}")" ] && SITE_BAZA="${pereche%%:*}"
    done
    if [ -z "$COMMIT" ]; then
        # Ultimul commit revizuit (și portat) al site-ului, din docs/de-portat.md.
        [ -n "$SITE_BAZA" ] || die "pentru $REPO_BAZA dă și commit-ul: --baza-repo=$REPO_BAZA@<commit>"
        COMMIT="$("$CMS_DEV_PHP" "$RADACINA/tools/dev/de-portat.php" --revizuit="$SITE_BAZA")" \
            || die "nu pot citi „ultimul commit revizuit” ($SITE_BAZA) din docs/de-portat.md"
    fi
    COMMIT_SCURT="$(git -C "$REPO_BAZA" rev-parse --short "$COMMIT^{commit}")" || die "commit necunoscut în $REPO_BAZA: $COMMIT"
    NUME_REPO="$(basename "$REPO_BAZA")"
    BAZA_DIR="$CMS_DEV_DIR/baza-$NUME_REPO-$COMMIT_SCURT"
    ETICHETA_BAZA="$NUME_REPO@$COMMIT_SCURT"
    # Codul vechi își are datele site-ului scrise în cod (harta adreselor vechi, regulile
    # SEO); codul comun le citește din pachetul site-ului. Comparația folosește deci
    # pachetul lui din site-uri/<site>/, nu cel inventat din tools/dev/site-demo.
    if [ -n "$SITE_BAZA" ] && [ -d "$RADACINA/site-uri/$SITE_BAZA" ]; then
        export SITE_DATE_DOSAR="$RADACINA/site-uri/$SITE_BAZA"
    fi
    # Un server al codului curent doar pentru comparație (portul +4), cu datele de mai sus:
    # serverul de dezvoltare, dacă merge, rămâne neatins.
    PORT_CURENT=$((CMS_DEV_PORT + 4))
else
    COMMIT="${COMMIT:-f5ae5ea}"
    COMMIT_SCURT="$(git -C "$RADACINA" rev-parse --short "$COMMIT^{commit}")" || die "commit necunoscut: $COMMIT"
    BAZA_DIR="$CMS_DEV_DIR/baza-$COMMIT_SCURT"
    ETICHETA_BAZA="$COMMIT_SCURT"
fi
PORT_BAZA=$((CMS_DEV_PORT + 1))
PID_BAZA="$CMS_DEV_DIR/server-baza.pid"
PID_CURENT="$CMS_DEV_DIR/server-compara.pid"

if [ ! -f "$BAZA_DIR/public/index.php" ]; then
    rm -rf "$BAZA_DIR"
    mkdir -p "$BAZA_DIR"
    if [ "$MOD" = "repo" ]; then
        git -C "$REPO_BAZA" archive "$COMMIT_SCURT" | tar -x -C "$BAZA_DIR"
    else
        git -C "$RADACINA" archive "$COMMIT_SCURT" | tar -x -C "$BAZA_DIR"
    fi
fi
[ -e "$BAZA_DIR/.env" ] && die "copia de bază are un .env ($BAZA_DIR/.env); n-ar trebui (git archive nu-l scoate)."
# Sesiunile și cache-ul de pagini ale copiei de bază, de la rularea trecută.
find "$BAZA_DIR/storage/sessions" -maxdepth 1 -type f -name 'sess_*' -delete 2>/dev/null || true
find "$BAZA_DIR/storage/cache" -mindepth 1 -type f ! -name '.gitkeep' -delete 2>/dev/null || true
# Aceleași fișiere încărcate ca site-ul curent (pozele demo, refăcute la fiecare instalare):
# codul de bază își calculează și el width/height din fișiere. Copie, nu legătură: ca și
# codul curent, nu citește poze din afara dosarului lui public/.
rm -rf "$BAZA_DIR/public/uploads"
[ -d "$RADACINA/public/uploads" ] && cp -a "$RADACINA/public/uploads" "$BAZA_DIR/public/uploads"
# Codurile vechi nu au uneltele de dezvoltare: routerul lui `php -S` vine de aici (face doar
# ce fac cele două .htaccess, deci nu schimbă ce răspunde codul de bază).
ROUTER_BAZA="$BAZA_DIR/tools/dev/router.php"
[ -f "$ROUTER_BAZA" ] || ROUTER_BAZA="$RADACINA/tools/dev/router.php"

opreste_pid() {
    local fisier="$1"
    if [ -s "$fisier" ] && kill -0 "$(cat "$fisier")" 2>/dev/null; then
        opreste_server_php "$(cat "$fisier")"
    fi
    rm -f "$fisier"
}
am_pornit_serverul=0
curata() {
    opreste_pid "$PID_BAZA"
    opreste_pid "$PID_CURENT"
    if [ -n "$LICENTA_COMPARATIE" ]; then
        "${PHP_DEV[@]}" "$RADACINA/tools/dev/licenta.php" toate >/dev/null 2>&1 || true
    fi
    [ "$am_pornit_serverul" = 1 ] && "$RADACINA/tools/dev/server.sh" --opreste >/dev/null 2>&1 || true
}
trap curata EXIT

# porneste_server <pid> <port> <dosar> <router> <jurnal-erori> <jurnal-server>
porneste_server() {
    local pid="$1" port="$2" dosar="$3" router="$4" erori="$5" jurnal="$6"
    opreste_pid "$pid"
    # Un server rămas pe port (pornit de altcineva) ar răspunde în locul celui de aici.
    if curl -fsS --noproxy '*' -o /dev/null "http://127.0.0.1:$port/health" 2>/dev/null; then
        die "pe portul $port răspunde deja un server (nepornit de această comparație); oprește-l întâi"
    fi
    (
        cd "$dosar"
        PHP_CLI_SERVER_WORKERS=4 nohup "${PHP_DEV[@]}" -d "error_log=$erori" \
            -S "127.0.0.1:$port" -t "$dosar/public" "$router" >> "$jurnal" 2>&1 &
        echo $! > "$pid"
    )
    for _ in $(seq 1 50); do
        if curl -fsS --noproxy '*' "http://127.0.0.1:$port/health" >/dev/null 2>&1; then
            kill -0 "$(cat "$pid")" 2>/dev/null || die "serverul de pe portul $port s-a oprit; vezi $jurnal"
            return 0
        fi
        sleep 0.2
    done
    die "serverul de pe portul $port nu pornește; vezi $jurnal"
}

if [ "$PORT_CURENT" = "$CMS_DEV_PORT" ]; then
    if ! curl -fsS --noproxy '*' "http://127.0.0.1:$CMS_DEV_PORT/health" >/dev/null 2>&1; then
        "$RADACINA/tools/dev/server.sh" >/dev/null
        am_pornit_serverul=1
    fi
else
    porneste_server "$PID_CURENT" "$PORT_CURENT" "$RADACINA" "$RADACINA/tools/dev/router.php" \
        "$CMS_DEV_DIR/php-erori.log" "$CMS_DEV_DIR/server-compara.log"
fi
# Codul de bază nu scrie marcaje de port (doar codul curent le are).
(
    unset CMS_DEV_MARCAJE_PORT
    porneste_server "$PID_BAZA" "$PORT_BAZA" "$BAZA_DIR" "$ROUTER_BAZA" \
        "$CMS_DEV_DIR/php-erori-baza.log" "$CMS_DEV_DIR/server-baza.log"
)

cd "$RADACINA"
if [ -n "$LICENTA_COMPARATIE" ]; then
    "${PHP_DEV[@]}" tools/dev/licenta.php "$LICENTA_COMPARATIE" >/dev/null || die "licența „$LICENTA_COMPARATIE” nu s-a pus"
fi
[ "$MOD" = "repo" ] && echo "  INFO  bază $ETICHETA_BAZA (port $PORT_BAZA), cod curent pe portul $PORT_CURENT, datele site-ului: ${SITE_DATE_DOSAR#"$RADACINA"/}"
"${PHP_DEV[@]}" tools/dev/compara-cu-baza.php "$PORT_BAZA" "$ETICHETA_BAZA" "$BAZA_DIR" "$MOD" "$PORT_CURENT" "${SITE_BAZA:-}"
