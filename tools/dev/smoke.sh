#!/usr/bin/env bash
# Testul de fum al proiectului: pornește de la o bază goală și verifică, cu
# curl, că paginile principale ale site-ului și toate intrările din meniul
# adminului răspund corect, fără avertismente PHP. La final face capturi din
# admin cu Playwright.
#
#   tools/dev/smoke.sh                 # bază nouă + date demo + verificări + capturi
#   tools/dev/smoke.sh --fara-capturi  # fără Playwright
#
# Rezultatul: $CMS_DEV_DIR/smoke-raport.txt; cod de ieșire 0 doar dacă totul a trecut.
# Serverul pornit de test se oprește la final.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
verifica_mediul

cu_capturi=1
[ "${1:-}" = "--fara-capturi" ] && cu_capturi=0

RAPORT="$CMS_DEV_DIR/smoke-raport.txt"
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

: > "$RAPORT"
scrie "Test de fum ANDAXI-CMS — $(date '+%Y-%m-%d %H:%M:%S')"
scrie "Commit: $(git -C "$RADACINA" rev-parse --short HEAD 2>/dev/null || echo '?')  Bază: $CMS_DEV_DB  Port: $CMS_DEV_PORT"
scrie ""

# 1. Bază goală -> install -> seed -> date demo
scrie "== Instalare de la zero"
if ! "$RADACINA/tools/dev/instaleaza.sh" > "$TMP/instalare.log" 2>&1; then
    cat "$TMP/instalare.log"
    die "instalarea a eșuat (detalii mai sus)"
fi
sed -n '/^  /p' "$TMP/instalare.log" | tee -a "$RAPORT"
exporta_mediul_aplicatiei
PAROLA="$ADMIN_DEFAULT_PASSWORD"

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
verifica "acasă" 200 viz GET / "Firma Demo"
verifica "magazin" 200 viz GET /magazin "Ceai de mentă"
verifica "categorie" 200 viz GET /categorie/ceaiuri "Ceai de mentă"
verifica "marcă" 200 viz GET /marca/livada-demo "Ceai de mentă"
verifica "produs cu șablon" 200 viz GET /produs/ceai-de-menta "Ceai de mentă"
verifica "produs fără șablon" 200 viz GET /produs/ceai-de-musetel "Ceai de mușețel"
verifica "produs cu oferte BBD" 200 viz GET "/produs/$SLUG_BBD" "data-bbd-key="
verifica "produs la precomandă" 200 viz GET "/produs/$SLUG_PRE"
verifica "căutare" 200 viz GET "/api/search/products?q=ceai" "Ceai"
verifica "adaugă în coș" 302 viz POST "/cos/adauga/$ID_MENTA" "" "quantity=2"
verifica "coș" 200 viz GET /cos "Ceai de mentă"
verifica "sumar coș (API)" 200 viz GET /api/cart/summary
verifica "checkout" 200 viz GET /checkout 'name="payment_method"'
verifica "login client" 200 viz GET /login
verifica "înregistrare client" 200 viz GET /register
verifica "cont fără login" 302 viz GET /contul-meu
verifica "blog" 200 viz GET /blog "Cum alegi un ceai"
verifica "articol" 200 viz GET "/blog/$SLUG_BLOG"
verifica "contact" 200 viz GET /contact
verifica "pagină legală" 200 viz GET /termeni-si-conditii "Firma Demo SRL"
verifica "robots.txt" 200 viz GET /robots.txt "Sitemap:"
verifica "pagină inexistentă" 404 viz GET /pagina-care-nu-exista
verifica "health" 200 - GET /health "ok"

scrie ""
scrie "== Comandă cu plata la livrare (ramburs), ca un client"
verifica "adaugă în coș" 302 cmd POST "/cos/adauga/$ID_MENTA" "" "quantity=1"
verifica "checkout" 200 cmd GET /checkout 'name="checkout_form_token"'
TOKEN="$(grep -o 'name="checkout_form_token" value="[^"]*"' "$TMP/corp" | sed 's/.*value="//; s/"$//' | head -1)"
RANDAT="$(grep -o 'name="checkout_form_rendered_at" value="[^"]*"' "$TMP/corp" | sed 's/.*value="//; s/"$//' | head -1)"
sleep 3 # protecția anti-robot refuză comenzile trimise la mai puțin de 2 secunde
verifica "plasează comanda" 302 cmd POST /checkout "" \
    "checkout_form_token=$TOKEN" "checkout_form_rendered_at=$RANDAT" \
    "billing_first_name=Test" "billing_last_name=Fum" "billing_phone=0700000555" "billing_email=fum@exemplu.ro" \
    "billing_street=Strada Exemplului" "billing_street_no=5" "billing_city=Cluj-Napoca" "billing_county=Cluj" \
    "billing_postcode=400000" "shipping_same_as_billing=1" "payment_method=cod" "accept_terms=1"
SUCCES="$(redirect_ultim)"
NR_NOU="${SUCCES##*/checkout/succes/}"
verifica "pagina de mulțumire" 200 cmd GET "/checkout/succes/$NR_NOU" "$NR_NOU"
confirma "comanda $NR_NOU e în bază: ramburs, în așteptare, 1 × Ceai de mentă" \
    test "$(q "SELECT CONCAT(o.payment_method, '/', o.status, '/', i.quantity, '/', i.product_name) FROM orders o JOIN order_items i ON i.order_id = o.id WHERE o.order_number = '$NR_NOU'")" = "cod/pending/1/Ceai de mentă"
confirma "emailul „comandă nouă” e capturat local, nu trimis" \
    grep -rlq "^Subject: .*$NR_NOU" "$CMS_DEV_DIR/mail"

scrie ""
scrie "== Adrese de confirmare (fără semnătură: trebuie refuzate, niciodată 404/500)"
verifica "webhook Stripe" "400|401|403" - POST /webhook/stripe
verifica "webhook EuPlătesc" "400|401|403" - POST /webhook/euplatesc
verifica "webhook BT iPay" "400|401|403" - POST /webhook/bt-ipay
verifica "notificare ERP" "400|401|403" - POST /api/erp/notificare

scrie ""
scrie "== Cont de client"
verifica "login client" 302 cli POST /login "" "email=andrei.exemplu@exemplu.ro" "password=$PAROLA"
verifica "contul meu" 200 cli GET /contul-meu "$NR_CLIENT"

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
for cale in /admin/products/new /admin/products/trash /admin/products/templates/new /admin/orders/trash \
    /admin/pages/new /admin/pages/trash /admin/blog/posts/new /admin/blog/posts/trash /admin/emails/builder \
    "/admin/users/$ID_CLIENT" /admin/orders/export /admin/products/export; do
    verifica "buton" 200 adm GET "$cale"
done
verifica "generează sitemap" 302 adm POST /admin/settings/store "" "action=generate_sitemap" "sitemap_filename=sitemap.xml"
verifica "sitemap.xml" 200 - GET /sitemap.xml "<urlset"
verifica "logout admin" 302 adm POST /admin/logout

scrie ""
scrie "== Roluri (administrator blog)"
verifica "login admin blog" 302 blog POST /admin/login "" "email=blog@exemplu.ro" "password=$PAROLA"
verifica "blog permis" 200 blog GET /admin/blog/posts
verifica "plăți interzise" "302|403" blog GET /admin/settings/payments

# 3. Cronurile, o rulare fiecare. Cererile lor spre ERP, FAN și bancă se
# opresc local (gaura neagră din comun.sh), deci erorile de conexiune sunt
# așteptate; contează să nu apară avertismente PHP și să nu plece nimic.
scrie ""
scrie "== Cronuri (o rulare; cererile spre ERP/FAN/bancă se opresc local)"
for cron in "scripts/erp-sync.php" "scripts/bt-ipay-sync.php" "scripts/fan-tracking-sync.php --limit=150" \
    "scripts/fan-lockers-sync.php" "scripts/fan-nomenclator-sync.php" "scripts/newsletter-campaigns.php --seconds=20" \
    "scripts/abandoned-cart-emails.php --limit=100"; do
    # shellcheck disable=SC2086
    if timeout 120 "$RADACINA/tools/dev/php.sh" $cron > "$TMP/cron.log" 2>&1; then cod_cron=0; else cod_cron=$?; fi
    scrie "  INFO  ieșire $cod_cron  $cron: $(tr -s '\n' ' ' < "$TMP/cron.log" | cut -c1-150)"
done
confirma "BT iPay: cererile spre bancă s-au oprit local (127.0.0.1:9)" \
    test "$(q "SELECT COUNT(*) FROM bt_ipay_log WHERE message LIKE '%127.0.0.1 port 9%'")" -gt 0
confirma "coș abandonat: emailurile sunt capturate local" grep -rlq '^To: cos0[12]@exemplu.ro' "$CMS_DEV_DIR/mail"

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

scrie ""
scrie "Rezultat: $TRECUTE verificări trecute, $ESECURI eșuate."
[ "$ESECURI" -eq 0 ]
