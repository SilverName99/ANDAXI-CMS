#!/usr/bin/env bash
# Testul de fum al proiectului: pornește de la o bază goală și verifică, cu
# curl, că paginile principale ale site-ului și toate intrările din meniul
# adminului răspund corect, fără avertismente PHP. La final face capturi din
# admin cu Playwright.
#
#   tools/dev/smoke.sh                       # bază nouă + date demo + verificări + capturi, cu licența „toate”
#   tools/dev/smoke.sh --fara-capturi        # fără Playwright
#   tools/dev/smoke.sh --pachet=prezentare   # cu licența unui pachet (prezentare, catalog, magazin, asociatie, toate)
#   tools/dev/smoke.sh --toate-pachetele     # pe rând: toate, magazin, catalog, prezentare, asociatie
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

cu_capturi=1
PACHET="toate"
toate_pachetele=0
for argument in "$@"; do
    case "$argument" in
        --fara-capturi) cu_capturi=0 ;;
        --pachet=*) PACHET="${argument#--pachet=}" ;;
        --toate-pachetele) toate_pachetele=1 ;;
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
        if "$0" "${optiuni[@]}" > /dev/null 2>&1; then rezultat="OK"; else rezultat="EȘEC"; cod_total=1; fi
        fisier="$CMS_DEV_DIR/smoke-raport.txt"
        [ "$p" != "toate" ] && fisier="$CMS_DEV_DIR/smoke-raport-$p.txt"
        sumar+=("$(printf '%-5s %-11s %s' "$rezultat" "$p" "$(tail -1 "$fisier")")")
    done
    printf '%s\n' "${sumar[@]}"
    exit "$cod_total"
fi

case "$PACHET" in
    toate|magazin|catalog|prezentare|asociatie) ;;
    *) die "pachet necunoscut: $PACHET (prezentare, catalog, magazin, asociatie, toate)" ;;
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
scrie "Commit: $(git -C "$RADACINA" rev-parse --short HEAD 2>/dev/null || echo '?')  Bază: $CMS_DEV_DB  Port: $CMS_DEV_PORT  Licență: $PACHET"
scrie ""

# 1. Bază goală -> install -> seed -> date demo (+ licența „toate”)
scrie "== Instalare de la zero"
if ! "$RADACINA/tools/dev/instaleaza.sh" > "$TMP/instalare.log" 2>&1; then
    cat "$TMP/instalare.log"
    die "instalarea a eșuat (detalii mai sus)"
fi
sed -n '/^  /p' "$TMP/instalare.log" | tee -a "$RAPORT"
exporta_mediul_aplicatiei
PAROLA="$ADMIN_DEFAULT_PASSWORD"
if [ "$PACHET" != "toate" ]; then
    "$RADACINA/tools/dev/php.sh" "$RADACINA/tools/dev/licenta.php" "$PACHET" | sed 's/^/  /' | tee -a "$RAPORT"
fi
incarca_starile

# 2. Serverul
"$RADACINA/tools/dev/server.sh" > /dev/null
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
verifica_p catalog "produs cu oferte BBD" 200 viz GET "/produs/$SLUG_BBD" "data-bbd-key="
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
verifica_p nucleu "health" 200 - GET /health "ok"

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
