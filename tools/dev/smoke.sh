#!/usr/bin/env bash
# Testul de fum al proiectului: pornește de la o bază goală și verifică, cu
# curl, că paginile principale ale site-ului și toate intrările din meniul
# adminului răspund corect, fără avertismente PHP. La final face capturi din
# admin cu Playwright.
#
#   tools/dev/smoke.sh                       # bază nouă + date demo + verificări + capturi, cu licența „toate”
#   tools/dev/smoke.sh --fara-capturi        # fără Playwright
#   tools/dev/smoke.sh --pachet=prezentare   # cu licența unui pachet (prezentare, catalog, magazin, asociatie, toate)
#   tools/dev/smoke.sh --pachet=grafoanaytis # sau cu licența de dezvoltare a unui site existent (bioscem, biovitality,
#                                            # vertij, grafoanaytis: config/plugins.php → licente_dev)
#   tools/dev/smoke.sh --toate-pachetele     # pe rând: toate, magazin, catalog, prezentare, asociatie
#   tools/dev/smoke.sh --pe-vechi            # pe o bază făcută ca înainte de migrări, apoi trecută prin baseline
#                                            # (tools/dev/instaleaza.sh --pe-vechi; și cu --toate-pachetele)
#   CMS_DEV_PHP=php8.3 tools/dev/smoke.sh …  # cu PHP-ul minim (config/app.php → php_min)
#
# Cu un pachet, fiecare verificare știe al cui plugin e pagina: dacă pluginul nu
# e în pachet, așteaptă 404 pe site și „Modulul nu este inclus în abonament” (403)
# în admin, iar cronurile lui nu trebuie să ruleze. Cu „toate”, rezultatul e
# exact cel dinainte de mecanismul de pluginuri.
#
# Rezultatul: $CMS_DEV_DIR/smoke-raport.txt (pachetul „toate”) sau
# smoke-raport-<pachet>.txt; cod de ieșire 0 doar dacă totul a trecut.
# Serverul pornit de test se oprește la final.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
verifica_mediul
# Înghețarea setărilor (mai jos) se reproduce din clonele celor patru coduri vechi, la commit-ul revizuit.
verifica_clonele

cu_capturi=1
PACHET="toate"
toate_pachetele=0
pe_vechi=0
for argument in "$@"; do
    case "$argument" in
        --fara-capturi) cu_capturi=0 ;;
        --pachet=*) PACHET="${argument#--pachet=}" ;;
        --toate-pachetele) toate_pachetele=1 ;;
        --pe-vechi) pe_vechi=1 ;;
        *) die "argument necunoscut: $argument" ;;
    esac
done

# Fiecare pachet, pe o bază nouă; la final un sumar.
if [ "$toate_pachetele" = 1 ]; then
    cod_total=0
    sumar=()
    for p in toate magazin catalog prezentare asociatie; do
        optiuni=("--pachet=$p")
        [ "$cu_capturi" = 0 ] && optiuni+=("--fara-capturi")
        [ "$pe_vechi" = 1 ] && optiuni+=("--pe-vechi")
        if "$0" "${optiuni[@]}" > /dev/null 2>&1; then rezultat="OK"; else rezultat="EȘEC"; cod_total=1; fi
        fisier="$CMS_DEV_DIR/smoke-raport.txt"
        [ "$p" != "toate" ] && fisier="$CMS_DEV_DIR/smoke-raport-$p.txt"
        sumar+=("$(printf '%-5s %-11s %s' "$rezultat" "$p" "$(tail -1 "$fisier")")")
    done
    printf '%s\n' "${sumar[@]}"
    exit "$cod_total"
fi

PRESETARI="$("$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/licenta.php" presetari | grep -vx fara | tr '\n' ' ')"
case " $PRESETARI " in
    *" $PACHET "*) ;;
    *) die "pachet necunoscut: $PACHET ($PRESETARI)" ;;
esac

RAPORT="$CMS_DEV_DIR/smoke-raport.txt"
[ "$PACHET" != "toate" ] && RAPORT="$CMS_DEV_DIR/smoke-raport-$PACHET.txt"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/cms-smoke.XXXXXX")"
B="http://127.0.0.1:$CMS_DEV_PORT"
ESECURI=0
TRECUTE=0

curata() {
    "$RADACINA/tools/dev/server.sh" --opreste >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap curata EXIT

scrie() {
    echo "$*" | tee -a "$RAPORT"
}

# verifica <eticheta> <cod_asteptat> <jar|-> <metoda> <cale> [text_asteptat] [date POST...]
# Codul așteptat poate fi o listă: "400|401|403".
verifica() {
    local eticheta="$1" asteptat="$2" jar="$3" metoda="$4" cale="$5" text="${6:-}"
    shift 6 2>/dev/null || shift $#
    local args=(-s --noproxy '*' -o "$TMP/corp" -D "$TMP/antet" -w '%{http_code}' -X "$metoda")
    if [ "$jar" != "-" ]; then
        args+=(-b "$TMP/$jar" -c "$TMP/$jar")
    fi
    local d
    for d in "$@"; do
        args+=(--data-urlencode "$d")
    done
    local cod
    cod="$(curl "${args[@]}" "$B$cale" || true)"
    local motiv=""
    if ! [[ "$cod" =~ ^($asteptat)$ ]]; then
        motiv="cod $cod (așteptat $asteptat)"
    elif [ -n "$text" ] && ! grep -qF -- "$text" "$TMP/corp"; then
        motiv="lipsește textul «$text»"
    elif grep -qE '(Fatal error|Parse error|Warning</b>:|Notice</b>:|Uncaught )' "$TMP/corp"; then
        motiv="eroare PHP în pagină"
    fi
    if [ -z "$motiv" ]; then
        TRECUTE=$((TRECUTE + 1))
        scrie "  OK    $cod  $metoda $cale  ($eticheta)"
    else
        ESECURI=$((ESECURI + 1))
        scrie "  EȘEC  $cod  $metoda $cale  ($eticheta): $motiv"
    fi
}

# Starea pluginurilor în licența pachetului (din tools/dev/licenta.php stari).
declare -A STARI=()
incarca_starile() {
    local cheie stare
    while read -r cheie stare; do
        STARI["$cheie"]="$stare"
    done < <("$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/licenta.php" stari)
}
# vizibil <plugin|nucleu>: pluginul e pornit sau se oprește (paginile lui se văd).
vizibil() {
    [ "$1" = "nucleu" ] && return 0
    [ "${STARI[$1]:-oprit}" != "oprit" ]
}

# verifica_p <plugin> <restul ca la verifica>: dacă pluginul paginii e în pachet,
# exact ca verifica; altfel garda trebuie să răspundă: 404 pe site, 403 cu
# „Modulul nu este inclus în abonament” în admin (logat), login în admin (nelogat).
verifica_p() {
    local plugin="$1" eticheta="$2" asteptat="$3" jar="$4" metoda="$5" cale="$6"
    if vizibil "$plugin"; then
        shift 1
        verifica "$@"
        return
    fi
    shift 6
    shift 1 2>/dev/null || true # textul așteptat nu mai contează
    if [[ "$cale" == /admin* ]]; then
        if [ "$jar" != "-" ] && [ -s "$TMP/$jar" ] && grep -q PHPSESSID "$TMP/$jar"; then
            verifica "$eticheta; $plugin oprit" 403 "$jar" "$metoda" "$cale" "Modulul nu este inclus în abonament" "$@"
        else
            verifica "$eticheta; $plugin oprit" 302 "$jar" "$metoda" "$cale" "" "$@"
        fi
    else
        verifica "$eticheta; $plugin oprit" 404 "$jar" "$metoda" "$cale" "" "$@"
    fi
}

# Adresa din antetul Location al ultimei cereri făcute cu verifica.
redirect_ultim() {
    tr -d '\r' < "$TMP/antet" | sed -n 's/^[Ll]ocation: *//p' | tail -1
}

# confirma <eticheta> <condiție (comandă shell)>
confirma() {
    local eticheta="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        TRECUTE=$((TRECUTE + 1))
        scrie "  OK    $eticheta"
    else
        ESECURI=$((ESECURI + 1))
        scrie "  EȘEC  $eticheta"
    fi
}

# confirma_p <plugin> <eticheta> <condiție>: doar dacă pluginul e în pachet.
confirma_p() {
    local plugin="$1"
    shift
    if vizibil "$plugin"; then
        confirma "$@"
    else
        scrie "  SĂRIT $1 ($plugin nu e în pachet)"
    fi
}

: > "$RAPORT"
scrie "Test de fum ANDAXI-CMS — $(date '+%Y-%m-%d %H:%M:%S')"
scrie "Commit: $(git -C "$RADACINA" rev-parse --short HEAD 2>/dev/null || echo '?')  Bază: $CMS_DEV_DB$([ "$pe_vechi" = 1 ] && echo ' (pe vechi + baseline)')  Port: $CMS_DEV_PORT  Licență: $PACHET  PHP: $("$CMS_DEV_PHP" -r 'echo PHP_VERSION;')"
scrie ""

# 1. Bază goală -> install -> seed -> date demo (+ licența „toate”)
scrie "== Instalare de la zero$([ "$pe_vechi" = 1 ] && echo ' (ca înainte de migrări, apoi baseline-ul)')"
optiuni_instalare=()
[ "$pe_vechi" = 1 ] && optiuni_instalare+=("--pe-vechi")
if ! "$RADACINA/tools/dev/instaleaza.sh" "${optiuni_instalare[@]}" > "$TMP/instalare.log" 2>&1; then
    cat "$TMP/instalare.log"
    die "instalarea a eșuat (detalii mai sus)"
fi
sed -n '/^  /p' "$TMP/instalare.log" | tee -a "$RAPORT"
exporta_mediul_aplicatiei
PAROLA="$ADMIN_DEFAULT_PASSWORD"
# Instalarea prin migrări (docs/MIGRARI.md, „Instalarea”; pe larg: tools/dev/test-instalare.php): fără server iese cu
# un cod ≠ 0 (înainte ieșea cu 0, „Erori moștenite” 4), iar pe baza deja instalată refuză, fără să scrie nimic.
if "$CMS_DEV_PHP" -d pdo_mysql.default_socket=/tmp/cms-smoke-nu-exista.sock "$RADACINA/scripts/install.php" > "$TMP/install-fara.log" 2>&1; then
    cod_fara=0
else
    cod_fara=$?
fi
confirma "install.php fără server (socket inexistent): ieșire $cod_fara (≠ 0)" test "$cod_fara" -ne 0
suma_bazei() { mysql_dev -N "$CMS_DEV_DB" -e "CHECKSUM TABLE $(mysql_dev -N -e "SELECT GROUP_CONCAT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$CMS_DEV_DB' AND TABLE_TYPE = 'BASE TABLE'")" | md5sum; }
SUMA_INAINTE="$(suma_bazei)"
if (cd "$RADACINA" && "${PHP_DEV[@]}" scripts/install.php) > "$TMP/install-din-nou.log" 2>&1; then cod_din_nou=0; else cod_din_nou=$?; fi
confirma "install.php pe baza instalată: ieșire 1, „Baza există”, nimic scris" \
    bash -c "[ '$cod_din_nou' = 1 ] && grep -q 'Baza există' '$TMP/install-din-nou.log' && [ '$(suma_bazei)' = '$SUMA_INAINTE' ]"
if [ "$PACHET" != "toate" ]; then
    "$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/licenta.php" "$PACHET" | sed 's/^/  /' | tee -a "$RAPORT"
fi
incarca_starile

# Fără server: suprafața fiecărei licențe de test față de fișierele din git (tools/dev/instantanee/),
# mecanismul diferențelor declarate ale comparației cu baza, verificarea fișierelor de migrare
# (plus DDL-ul din cod față de lista celui vechi; asteptat.json și proprietarii, pe o bază goală cms_mig_v_*)
# și înghețarea setărilor site-urilor vechi.
scrie ""
scrie "== Instantaneul rutelor, diferențele declarate, fișierele de migrare, căile site-ului și înghețarea setărilor"
confirma "instantaneul rutelor (tools/dev/instantaneu-rute.php)" "$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/instantaneu-rute.php"
confirma "diferențele declarate, marcajele de port și anteturile din compara-cu-baza.php (--autotest)" "$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/compara-cu-baza.php" --autotest
if "$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/verifica-migrari.php" > "$TMP/verifica-migrari.log" 2>&1; then
    TRECUTE=$((TRECUTE + 1))
    scrie "  OK    fișierele de migrare și DDL-ul din cod (tools/dev/verifica-migrari.php): $(tail -1 "$TMP/verifica-migrari.log" | sed 's/^verifica-migrari: //')"
else
    ESECURI=$((ESECURI + 1))
    scrie "  EȘEC  fișierele de migrare și DDL-ul din cod (tools/dev/verifica-migrari.php):"
    head -20 "$TMP/verifica-migrari.log" | sed 's/^/        /' | tee -a "$RAPORT"
fi
# Căile site-ului (WP-07): nicio cale storage/, uploads/, sessions/, .env sau public/ construită în afara App\Support\Paths
# (o construcție nouă ar scrie, în așezarea din Etapa 2, în versiunea de cod comună; pe larg: tools/dev/test-paths.php).
if "$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/test-paths.php" --doar-cai > "$TMP/test-paths.log" 2>&1; then
    TRECUTE=$((TRECUTE + 1))
    scrie "  OK    căile site-ului doar prin App\\Support\\Paths (tools/dev/test-paths.php --doar-cai): $(tail -1 "$TMP/test-paths.log")"
else
    ESECURI=$((ESECURI + 1))
    scrie "  EȘEC  căile site-ului doar prin App\\Support\\Paths (tools/dev/test-paths.php --doar-cai):"
    grep -E 'EȘEC' -A20 "$TMP/test-paths.log" | head -20 | sed 's/^/        /' | tee -a "$RAPORT"
fi
# Înghețarea setărilor site-urilor vechi (WP-06): o setare nouă sau schimbată în codul comun fără decizia ei pentru
# Bioscem, Biovitality, vertij și grafoanaytis pică aici (docs/MIGRARI.md, „Înghețarea setărilor”).
if "$RADACINA/tools/dev/php.sh" "$RADACINA/tools/inghetare/verifica-inghetare.php" > "$TMP/verifica-inghetare.log" 2>&1; then
    TRECUTE=$((TRECUTE + 1))
    scrie "  OK    înghețarea setărilor site-urilor vechi (tools/inghetare/verifica-inghetare.php): $(tail -1 "$TMP/verifica-inghetare.log" | sed 's/^verifica-inghetare: //')"
else
    ESECURI=$((ESECURI + 1))
    scrie "  EȘEC  înghețarea setărilor site-urilor vechi (tools/inghetare/verifica-inghetare.php):"
    grep -E 'EȘEC|EROARE' "$TMP/verifica-inghetare.log" | head -20 | sed 's/^/        /' | tee -a "$RAPORT"
fi

# 2. Serverul, cu contorul de instrucțiuni (App\Support\ContorSql): la final, nicio cerere nu trebuie să fi trimis
# DDL (pe o bază la zi, funcțiile ensure* ies pe loc; garda din docs/MIGRARI.md).
rm -f "$CMS_DEV_DIR/numara-sql.jsonl"
CMS_DEV_NUMARA_SQL=1 "$RADACINA/tools/dev/server.sh" > /dev/null
q() { mysql_dev -N "$CMS_DEV_DB" -e "$1"; }
ID_MENTA="$(q "SELECT id FROM products WHERE slug = 'ceai-de-menta'")"
SLUG_BBD="$(q "SELECT slug FROM products WHERE bbd_enabled = 1 AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1")"
SLUG_PRE="$(q "SELECT slug FROM products WHERE preorder_enabled = 1 ORDER BY id LIMIT 1")"
SLUG_BLOG="$(q "SELECT slug FROM blog_posts WHERE is_published = 1 ORDER BY id LIMIT 1")"
NR_CLIENT="$(q "SELECT o.order_number FROM orders o JOIN users u ON u.id = o.user_id WHERE u.email = 'andrei.exemplu@exemplu.ro' AND o.deleted_at IS NULL ORDER BY o.id LIMIT 1")"
ID_CLIENT="$(q "SELECT id FROM users WHERE email = 'andrei.exemplu@exemplu.ro'")"

scrie ""
scrie "== Site public (vizitator)"
verifica_p nucleu "acasă" 200 viz GET / "Firma Demo"
verifica_p catalog "magazin" 200 viz GET /magazin "Ceai de mentă"
verifica_p catalog "categorie" 200 viz GET /categorie/ceaiuri "Ceai de mentă"
verifica_p catalog "marcă" 200 viz GET /marca/livada-demo "Ceai de mentă"
verifica_p catalog "produs cu șablon" 200 viz GET /produs/ceai-de-menta "Ceai de mentă"
verifica_p catalog "produs fără șablon" 200 viz GET /produs/ceai-de-musetel "Ceai de mușețel"
if vizibil oferte_bbd; then
    verifica_p catalog "produs cu oferte BBD" 200 viz GET "/produs/$SLUG_BBD" "data-bbd-key="
else
    verifica_p catalog "produs cu oferte BBD (fără pluginul ofertelor: produs obișnuit)" 200 viz GET "/produs/$SLUG_BBD"
fi
verifica_p catalog "produs la precomandă" 200 viz GET "/produs/$SLUG_PRE"
verifica_p catalog "căutare" 200 viz GET "/api/search/products?q=ceai" "Ceai"
verifica_p magazin "adaugă în coș" 302 viz POST "/cos/adauga/$ID_MENTA" "" "quantity=2"
verifica_p magazin "coș" 200 viz GET /cos "Ceai de mentă"
verifica_p magazin "sumar coș (API)" 200 viz GET /api/cart/summary
verifica_p magazin "checkout" 200 viz GET /checkout 'name="payment_method"'
verifica_p conturi_clienti "login client" 200 viz GET /login
verifica_p conturi_clienti "înregistrare client" 200 viz GET /register
verifica_p conturi_clienti "cont fără login" 302 viz GET /contul-meu
verifica_p blog "blog" 200 viz GET /blog "Cum alegi un ceai"
verifica_p blog "articol" 200 viz GET "/blog/$SLUG_BLOG"
verifica_p nucleu "contact" 200 viz GET /contact
verifica_p nucleu "pagină legală" 200 viz GET /termeni-si-conditii "Firma Demo SRL"
verifica_p nucleu "robots.txt" 200 viz GET /robots.txt "Sitemap:"
verifica_p nucleu "pagină inexistentă" 404 viz GET /pagina-care-nu-exista
verifica_p nucleu "health (cu starea schemei)" 200 - GET /health "schema: la_zi"
# Pingul monitorizării (App\Support\Ping; pe larg și pe o găzduire simulată: tools/dev/test-paths.php).
verifica_p nucleu "ping: baza, versiunea codului, schema" 200 - GET /_andaxi/ping '"ok":true,"db":true,"cod":null,"schema":"la_zi"'
confirma "ping: JSON, Cache-Control: no-store, fără cookie de sesiune" \
    bash -c "tr -d '\r' < '$TMP/antet' | grep -qix 'cache-control: no-store' && tr -d '\r' < '$TMP/antet' | grep -qi '^content-type: application/json' && ! grep -qi '^set-cookie:' '$TMP/antet'"
SESIUNI_PING="$(find "$RADACINA/storage/sessions" -maxdepth 1 -type f -name 'sess_*' | wc -l)"
for _ in 1 2 3; do curl -s --noproxy '*' -o /dev/null "$B/_andaxi/ping" || true; done
confirma "ping: nicio sesiune nouă în storage/sessions (3 cereri)" \
    bash -c "[ \"\$(find '$RADACINA/storage/sessions' -maxdepth 1 -type f -name 'sess_*' | wc -l)\" = '$SESIUNI_PING' ]"

scrie ""
scrie "== SEO (docs/SEO.md; pe larg: tools/dev/test-seo.php)"
verifica_p nucleu "sitemap.xml construit din bază" 200 - GET /sitemap.xml "<urlset"
if vizibil catalog; then
    confirma "sitemap: produsele (catalog)" grep -qF "/produs/ceai-de-menta</loc>" "$TMP/corp"
else
    confirma "sitemap: fără produse (catalog nu e în pachet)" bash -c "! grep -qF '/produs/' '$TMP/corp'"
fi
verifica_p nucleu "robots.txt cu sitemap-ul" 200 - GET /robots.txt "Sitemap: $B/sitemap.xml"
confirma "robots.txt: adresele platformei închise (Disallow: /_andaxi/)" grep -qxF 'Disallow: /_andaxi/' "$TMP/corp"
if vizibil magazin; then
    confirma "robots.txt: /cos exact, nu ca prefix" grep -qxF 'Disallow: /cos$' "$TMP/corp"
else
    confirma "robots.txt: fără regulile coșului (magazin nu e în pachet)" bash -c "! grep -qF 'Disallow: /cos' '$TMP/corp'"
fi
verifica_p nucleu "date structurate pe prima pagină (firma și site-ul)" 200 - GET / '"@type":"Organization"'
if vizibil catalog; then
    if [ "${STARI[magazin]:-oprit}" = "pornit" ]; then
        verifica "date structurate: produs cu ofertă" 200 - GET /produs/ceai-de-menta '"@type":"Offer"'
    else
        verifica "date structurate: firimiturile produsului" 200 - GET /produs/ceai-de-menta '"@type":"BreadcrumbList"'
        confirma "date structurate: fără ofertă (magazinul nu primește comenzi)" bash -c "! grep -qF '\"@type\":\"Offer\"' '$TMP/corp'"
    fi
fi
verifica_p catalog "adresa cu „/” final" 301 - GET /produs/ceai-de-menta/
if vizibil catalog; then
    confirma "… duce la /produs/ceai-de-menta" test "$(redirect_ultim)" = "/produs/ceai-de-menta"
fi
verifica_p nucleu "adresă veche din datele site-ului" 301 - GET /despre-firma-veche
confirma "… duce la /contact" test "$(redirect_ultim)" = "/contact"
verifica "POST cu „/” final nu e redirecționat (webhook)" "400|401|403" - POST /webhook/stripe/

scrie ""
scrie "== Comandă cu plata la livrare (ramburs), ca un client"
verifica_p magazin "adaugă în coș" 302 cmd POST "/cos/adauga/$ID_MENTA" "" "quantity=1"
verifica_p magazin "checkout" 200 cmd GET /checkout 'name="checkout_form_token"'
# (Fără magazin în pachet, pagina e 404 și nu are formular: tokenurile rămân goale.)
TOKEN="$(grep -o 'name="checkout_form_token" value="[^"]*"' "$TMP/corp" | sed 's/.*value="//; s/"$//' | head -1 || true)"
RANDAT="$(grep -o 'name="checkout_form_rendered_at" value="[^"]*"' "$TMP/corp" | sed 's/.*value="//; s/"$//' | head -1 || true)"
vizibil magazin && sleep 3 # protecția anti-robot refuză comenzile trimise la mai puțin de 2 secunde
verifica_p magazin "plasează comanda" 302 cmd POST /checkout "" \
    "checkout_form_token=$TOKEN" "checkout_form_rendered_at=$RANDAT" \
    "billing_first_name=Test" "billing_last_name=Fum" "billing_phone=0700000555" "billing_email=fum@exemplu.ro" \
    "billing_street=Strada Exemplului" "billing_street_no=5" "billing_city=Cluj-Napoca" "billing_county=Cluj" \
    "billing_postcode=400000" "shipping_same_as_billing=1" "payment_method=cod" "accept_terms=1"
SUCCES="$(redirect_ultim)"
NR_NOU="${SUCCES##*/checkout/succes/}"
if vizibil magazin; then
    verifica "pagina de mulțumire" 200 cmd GET "/checkout/succes/$NR_NOU" "$NR_NOU"
    confirma "comanda $NR_NOU e în bază: ramburs, în așteptare, 1 × Ceai de mentă" \
        test "$(q "SELECT CONCAT(o.payment_method, '/', o.status, '/', i.quantity, '/', i.product_name) FROM orders o JOIN order_items i ON i.order_id = o.id WHERE o.order_number = '$NR_NOU'")" = "cod/pending/1/Ceai de mentă"
    confirma "emailul „comandă nouă” e capturat local, nu trimis" \
        grep -rlq "^Subject: .*$NR_NOU" "$CMS_DEV_DIR/mail"
else
    scrie "  SĂRIT pagina de mulțumire, comanda în bază, emailul (magazin nu e în pachet)"
fi

scrie ""
scrie "== Adrese de confirmare (fără semnătură: trebuie refuzate, niciodată 404/500)"
verifica "webhook Stripe" "400|401|403" - POST /webhook/stripe
verifica "webhook EuPlătesc" "400|401|403" - POST /webhook/euplatesc
verifica "webhook BT iPay" "400|401|403" - POST /webhook/bt-ipay
verifica "notificare ERP" "400|401|403" - POST /api/erp/notificare

# Adresele pluginurilor care lipsesc din licență: răspunde garda (antetul X-Andaxi-Garda),
# nu controllerul, și dă 404 (testul GR-14b; prinde o rută declarată greșit în catalog).
scrie ""
scrie "== Garda: adresele pluginurilor care nu sunt pe site"
garda_404=0
while read -r plugin metoda cale; do
    vizibil "$plugin" && continue
    garda_404=1
    verifica "$plugin oprit: garda răspunde" 404 - "$metoda" "$cale"
    confirma "… cu antetul X-Andaxi-Garda ($metoda $cale)" grep -qi '^X-Andaxi-Garda:' "$TMP/antet"
done <<GARDA
conturi_clienti GET /login
conturi_clienti GET /register
conturi_clienti GET /auth/google
conturi_clienti GET /auth/google/callback
conturi_clienti POST /contul-meu/login
link_plata GET /plata/x
recenzii POST /produs/x/review
recenzii POST /review-form/submit
GARDA
[ "$garda_404" = 0 ] && scrie "  SĂRIT toate pluginurile acestor adrese sunt în pachet"

scrie ""
scrie "== Cont de client"
verifica_p conturi_clienti "login client" 302 cli POST /login "" "email=andrei.exemplu@exemplu.ro" "password=$PAROLA"
verifica_p conturi_clienti "contul meu" 200 cli GET /contul-meu "$NR_CLIENT"

scrie ""
scrie "== Admin"
verifica "admin fără login" 302 adm GET /admin
verifica "pagina de login" 200 adm GET /admin/login "Autentificare"
verifica "login admin" 302 adm POST /admin/login "" "email=$CMS_DEV_ADMIN_EMAIL" "password=$PAROLA"
verifica "dashboard" 200 adm GET /admin "Dashboard"
cp "$TMP/corp" "$TMP/dashboard.html"
sed -n '/<nav class="admin-nav">/,/<\/nav>/p' "$TMP/dashboard.html" \
    | grep -o 'href="/admin[^"#]*"' | sed 's/^href="//; s/"$//; s/&amp;/\&/g' | awk '!vazut[$0]++' > "$TMP/meniu.txt"
scrie "  Meniul adminului are $(wc -l < "$TMP/meniu.txt") intrări:"
while read -r cale; do
    verifica "meniu" 200 adm GET "$cale"
done < "$TMP/meniu.txt"
scrie "  Pagini din admin la care se ajunge din butoane:"
while read -r plugin cale; do
    verifica_p "$plugin" "buton" 200 adm GET "$cale"
done <<EOF
catalog /admin/products/new
catalog /admin/products/trash
catalog /admin/products/templates/new
magazin /admin/orders/trash
nucleu /admin/pages/new
nucleu /admin/pages/trash
blog /admin/blog/posts/new
blog /admin/blog/posts/trash
newsletter /admin/emails/builder
conturi_clienti /admin/users/$ID_CLIENT
magazin /admin/orders/export
catalog /admin/products/export
EOF
verifica "generează sitemap" 302 adm POST /admin/settings/store "" "action=generate_sitemap" "sitemap_filename=sitemap.xml"
verifica "sitemap.xml" 200 - GET /sitemap.xml "<urlset"
verifica "logout admin" 302 adm POST /admin/logout

scrie ""
scrie "== Roluri (administrator blog)"
verifica "login admin blog" 302 blog POST /admin/login "" "email=blog@exemplu.ro" "password=$PAROLA"
verifica_p blog "blog permis" 200 blog GET /admin/blog/posts
verifica "plăți interzise" "302|403" blog GET /admin/settings/payments

# 3. Cronurile, o rulare fiecare. Cererile lor spre ERP, FAN și bancă se
# opresc local (gaura neagră din comun.sh), deci erorile de conexiune sunt
# așteptate; contează să nu apară avertismente PHP și să nu plece nimic.
# Cronurile pluginurilor care nu sunt în pachet trebuie să iasă fără să facă nimic.
declare -A CRON_PLUGIN=() CRON_RULEAZA=()
while read -r script plugin stare; do
    CRON_PLUGIN["$script"]="$plugin"
    CRON_RULEAZA["$script"]="$stare"
done < <("$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/licenta.php" cron)
scrie ""
scrie "== Cronuri (o rulare; cererile spre ERP/FAN/bancă se opresc local)"
# Linia de comandă nu pornește sesiuni (bootstrap.php; „Erori moștenite” 5): cronurile de mai jos
# și runner-ul migrărilor nu au voie să lase fișiere în storage/sessions.
numara_sesiuni() { find "$RADACINA/storage/sessions" -maxdepth 1 -type f -name 'sess_*' | wc -l; }
SESIUNI_INAINTE="$(numara_sesiuni)"
for cron in "scripts/erp-sync.php" "scripts/bt-ipay-sync.php" "scripts/fan-tracking-sync.php --limit=150" \
    "scripts/fan-lockers-sync.php" "scripts/fan-nomenclator-sync.php" "scripts/newsletter-campaigns.php --seconds=20" \
    "scripts/abandoned-cart-emails.php --limit=100"; do
    # shellcheck disable=SC2086
    if timeout 120 "$RADACINA/tools/dev/php.sh" $cron > "$TMP/cron.log" 2>&1; then cod_cron=0; else cod_cron=$?; fi
    script="${cron%% *}"
    if [ "${CRON_RULEAZA[$script]:-ruleaza}" = "oprit" ]; then
        confirma "cronul $script nu rulează (${CRON_PLUGIN[$script]} nu e în pachet)" \
            grep -q "nu este pornit pe acest site" "$TMP/cron.log"
    else
        scrie "  INFO  ieșire $cod_cron  $cron: $(tr -s '\n' ' ' < "$TMP/cron.log" | cut -c1-150)"
    fi
done
confirma_p plata_bt "BT iPay: cererile spre bancă s-au oprit local (127.0.0.1:9)" \
    test "$(q "SELECT COUNT(*) FROM bt_ipay_log WHERE message LIKE '%127.0.0.1 port 9%'")" -gt 0
if [ "${CRON_RULEAZA[scripts/abandoned-cart-emails.php]:-ruleaza}" = "ruleaza" ]; then
    confirma "coș abandonat: emailurile sunt capturate local" grep -rlq '^To: cos0[12]@exemplu.ro' "$CMS_DEV_DIR/mail"
else
    scrie "  SĂRIT coș abandonat: emailurile sunt capturate local (magazin nu e în pachet)"
fi
# Runner-ul migrărilor pe baza de test (doar citește: --stare, --plan). Baza e făcută de scripts/install.php (prin
# migrări) sau, cu --pe-vechi, ca înainte și apoi trecută prin baseline: în ambele cazuri --plan trebuie să iasă cu 0,
# fără nimic de făcut. Că baseline-ul găsește pe vechi tot, fără nicio operație în grupul B, verifică
# genereaza-fixture.php --verifica (G-baza, în T1) și T10 din test-migrari.php.
confirma "scripts/migreaza.php --stare pe baza de test" "$RADACINA/tools/dev/php.sh" "$RADACINA/scripts/migreaza.php" --stare
if "$RADACINA/tools/dev/php.sh" "$RADACINA/scripts/migreaza.php" --plan --json > "$TMP/migreaza.log" 2>&1; then cod_plan=0; else cod_plan=$?; fi
mesaj_plan="$("$CMS_DEV_PHP" -r 'echo (string) (json_decode((string) file_get_contents($argv[1]), true)["mesaj"] ?? "");' "$TMP/migreaza.log" 2>/dev/null)"
if [ "$cod_plan" = 0 ]; then
    TRECUTE=$((TRECUTE + 1))
    scrie "  OK    scripts/migreaza.php --plan (ieșire 0, baza e la zi cu migrările): $(echo "$mesaj_plan" | cut -c1-120)"
else
    ESECURI=$((ESECURI + 1))
    scrie "  EȘEC  scripts/migreaza.php --plan (ieșire $cod_plan, aștept 0): $(tr -s '\n' ' ' < "$TMP/migreaza.log" | cut -c1-200)"
fi
SESIUNI_DUPA="$(numara_sesiuni)"
confirma "cronurile și runner-ul migrărilor nu lasă fișiere de sesiune ($SESIUNI_INAINTE înainte, $SESIUNI_DUPA după)" \
    test "$SESIUNI_DUPA" -eq "$SESIUNI_INAINTE"

# Instrucțiunile de schemă trimise de cererile de mai sus (contorul serverului; cronurile nu sunt numărate).
scrie ""
scrie "== Instrucțiuni de schemă (DDL) pe cerere"
rezultat_ddl="$("$CMS_DEV_PHP" -r '
    $cereri = 0; $cu = [];
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $r = json_decode($l, true);
        if (!is_array($r) || $r["metoda"] === "CLI") { continue; }
        $cereri++;
        if ($r["ddl"] > 0) { $cu[] = $r["metoda"] . " " . $r["cale"] . " (" . $r["ddl"] . ": " . ($r["ddl_texte"][0] ?? "") . ")"; }
    }
    echo $cereri, "\t", implode("; ", array_slice($cu, 0, 5)), "\n";' "$CMS_DEV_DIR/numara-sql.jsonl" 2>&1)"
cereri_numarate="${rezultat_ddl%%$'\t'*}"
cu_ddl="${rezultat_ddl#*$'\t'}"
if [ "${cereri_numarate:-0}" -gt 0 ] 2>/dev/null && [ -z "$cu_ddl" ]; then
    TRECUTE=$((TRECUTE + 1))
    scrie "  OK    nicio cerere nu trimite DDL ($cereri_numarate cereri numărate: dashboard, comenzi, produse, checkout, fiecare intrare din meniu)"
else
    ESECURI=$((ESECURI + 1))
    scrie "  EȘEC  cereri cu DDL (din ${cereri_numarate:-?} numărate): ${cu_ddl:-contorul n-a scris nimic}"
fi

# 4. Jurnalul PHP
scrie ""
scrie "== Jurnalul PHP ($CMS_DEV_DIR/php-erori.log)"
JURNAL="$CMS_DEV_DIR/php-erori.log"
touch "$JURNAL"
GRAVE="$(grep -cE 'PHP (Warning|Notice|Fatal error|Parse error|Recoverable fatal error|Strict Standards)' "$JURNAL" || true)"
DEPRECATED="$(grep -c 'PHP Deprecated' "$JURNAL" || true)"
if [ "$GRAVE" -gt 0 ]; then
    ESECURI=$((ESECURI + 1))
    scrie "  EȘEC  $GRAVE avertismente/erori PHP:"
    grep -E 'PHP (Warning|Notice|Fatal error|Parse error|Recoverable fatal error|Strict Standards)' "$JURNAL" \
        | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | sort -rn | head -30 | tee -a "$RAPORT"
else
    TRECUTE=$((TRECUTE + 1))
    scrie "  OK    niciun Warning/Notice/Fatal"
fi
if [ "$DEPRECATED" -gt 0 ]; then
    scrie "  INFO  $DEPRECATED mesaje Deprecated (moștenite, nu opresc testul):"
    grep 'PHP Deprecated' "$JURNAL" | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | sort -rn | head -20 | tee -a "$RAPORT"
fi
scrie "  INFO  emailuri capturate (nu au plecat nicăieri): $(find "$CMS_DEV_DIR/mail" -name '*.eml' | wc -l)"

# 5. Capturi cu Playwright
if [ "$cu_capturi" = 1 ]; then
    scrie ""
    scrie "== Capturi (Playwright)"
    export CMS_DEV_URL="$B" CMS_DEV_PAROLA="$PAROLA" CMS_DEV_ADMIN_EMAIL
    export CMS_DEV_CAPTURI="${CMS_DEV_CAPTURI:-$CMS_DEV_DIR/capturi}"
    CMS_DEV_CAPTURI_SUFIX=""
    [ "$PACHET" != "toate" ] && CMS_DEV_CAPTURI_SUFIX="-$PACHET"
    export CMS_DEV_CAPTURI_SUFIX
    export NODE_PATH="${NODE_PATH:-/opt/node22/lib/node_modules}"
    export PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-/opt/pw-browsers}"
    if node "$RADACINA/tools/dev/capturi.cjs" > "$TMP/capturi.log" 2>&1; then
        TRECUTE=$((TRECUTE + 1))
        sed 's/^/  /' "$TMP/capturi.log" | tee -a "$RAPORT"
    else
        ESECURI=$((ESECURI + 1))
        sed 's/^/  /' "$TMP/capturi.log" | tee -a "$RAPORT"
        scrie "  EȘEC  capturile Playwright"
    fi
fi

# Dezvoltarea continuă cu toate pluginurile pornite.
[ "$PACHET" != "toate" ] && "$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/licenta.php" toate > /dev/null

scrie ""
scrie "Rezultat: $TRECUTE verificări trecute, $ESECURI eșuate."
[ "$ESECURI" -eq 0 ]
