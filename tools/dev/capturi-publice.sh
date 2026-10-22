#!/usr/bin/env bash
# G-vizual (WP-13, G-M3): stilurile calculate ale paginilor publice, codul curent față de codul de bază. HTML-ul
# identic nu dovedește că un site arată la fel: o schimbare de CSS (app.css, tema, un font) nu apare în HTML.
#
#   tools/dev/capturi-publice.sh                      # față de codul Bioscem însuși, pe serverele lui compara-cu-baza.sh
#                                                     # (Bioscem la „ultimul commit revizuit”, codul curent cu site-uri/bioscem/)
#   tools/dev/capturi-publice.sh --licenta=bioscem    # la fel, cu licența de dezvoltare a Bioscem
#   tools/dev/capturi-publice.sh --baza-repo=<clonă>@<commit>
#   tools/dev/capturi-publice.sh --autotest           # unealta însăși: un arbore identic dă 0 diferențe, o singură regulă
#                                                     # schimbată într-o copie a app.css se raportează, declarațiile merg
#
# Paginile (prima pagină, o categorie, un produs cu șablon și unul fără, coșul, checkout-ul, contul, un articol, blogul,
# o pagină legală, un 404, pagina de mulțumire a unei comenzi demo), ca vizitator și ca client logat, la 390, 768, 1366
# și 1920 px. Trece doar dacă fiecare element vizibil are aceleași proprietăți calculate (lista din
# tools/dev/capturi-publice.php, după rotunjire), afară de diferențele declarate acolo, în $porturiVizual. Pentru ochi:
# capturile PNG ale paginilor care diferă la pixel, cu numărul de pixeli, în $CMS_DEV_DIR/vizual/ (raport.txt).
# Testul temei (tools/dev/test-pluginuri.php, T) folosește același instrument, în modul „verificari”.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

if [ "${1:-}" != "--autotest" ]; then
    exec "$RADACINA/tools/dev/compara-cu-baza.sh" --vizual "$@"
fi

# ---------------------------------------------------------------------
# --autotest: două servere ale codului curent pe CMS_DEV_PORT+22 și +23, pe baza de dezvoltare
# ---------------------------------------------------------------------
verifica_mediul
exporta_mediul_aplicatiei
P1=$((CMS_DEV_PORT + 22))
P2=$((CMS_DEV_PORT + 23))
LUCRU="$CMS_DEV_DIR/vizual-autotest"
rm -rf "$LUCRU"
mkdir -p "$LUCRU"
PID1="$LUCRU/server-$P1.pid"
PID2="$LUCRU/server-$P2.pid"

opreste_serverele() {
    local f
    for f in "$PID1" "$PID2"; do
        if [ -s "$f" ] && kill -0 "$(cat "$f")" 2>/dev/null; then
            opreste_server_php "$(cat "$f")"
        fi
        rm -f "$f"
    done
}
trap 'opreste_serverele; rm -rf "$LUCRU"' EXIT

# porneste <port> <fișier-pid> <router> [VAR=valoare …]
porneste() {
    local port="$1" pid="$2" router="$3"
    shift 3
    if [ -s "$pid" ] && kill -0 "$(cat "$pid")" 2>/dev/null; then
        opreste_server_php "$(cat "$pid")"
    fi
    if curl -fsS --noproxy '*' -o /dev/null "http://127.0.0.1:$port/health" 2>/dev/null; then
        die "pe portul $port răspunde deja un server (nepornit de acest autotest); oprește-l întâi"
    fi
    (
        cd "$RADACINA"
        env "$@" PHP_CLI_SERVER_WORKERS=2 nohup "${PHP_DEV[@]}" -S "127.0.0.1:$port" -t "$RADACINA/public" "$router" \
            >> "$LUCRU/server-$port.log" 2>&1 &
        echo $! > "$pid"
    )
    for _ in $(seq 1 50); do
        curl -fsS --noproxy '*' -o /dev/null "http://127.0.0.1:$port/health" 2>/dev/null && return 0
        sleep 0.2
    done
    die "serverul de pe portul $port nu pornește; vezi $LUCRU/server-$port.log"
}

TRECUTE=0
ESECURI=0
# caz <eticheta> <cod așteptat> <text așteptat în ieșire> <argumente pentru capturi-publice.php compara …>
caz() {
    local eticheta="$1" cod_asteptat="$2" text="$3"
    shift 3
    local iesire cod=0
    iesire="$("${PHP_DEV[@]}" "$RADACINA/tools/dev/capturi-publice.php" compara --baza="http://127.0.0.1:$P1" \
        --curent="http://127.0.0.1:$P2" --pagini=redus --fara-capturi --dosar=autotest "$@" 2>&1)" || cod=$?
    if [ "$cod" = "$cod_asteptat" ] && grep -qF -- "$text" <<< "$iesire"; then
        TRECUTE=$((TRECUTE + 1))
        echo "  OK    $eticheta (cod $cod, „$text”)"
    else
        ESECURI=$((ESECURI + 1))
        echo "  EȘEC  $eticheta: cod $cod (aștept $cod_asteptat), „$text” $(grep -qF -- "$text" <<< "$iesire" && echo găsit || echo negăsit)"
        sed 's/^/        /' <<< "$iesire" | tail -12
    fi
}

echo "Autotestul G-vizual (două servere ale codului curent, porturile $P1 și $P2)"
porneste "$P1" "$PID1" "$RADACINA/tools/dev/router.php"
porneste "$P2" "$PID2" "$RADACINA/tools/dev/router.php"

# Declarațiile de test (formatul lui $porturiVizual).
cat > "$LUCRU/declaratie-fundal.json" <<'EOF'
[{"nume": "autotest: fundalul paginii", "selector": "body.site-shell, body.site-shell > main.site-container",
  "proprietate": "background-color", "pagini": "*", "motiv": "autotestul G-vizual: regula schimbată în copia app.css"}]
EOF
cat > "$LUCRU/declaratie-alta-proprietate.json" <<'EOF'
[{"nume": "autotest: altă proprietate", "selector": "body.site-shell, body.site-shell > main.site-container",
  "proprietate": "color", "pagini": "*", "motiv": "autotestul G-vizual: declarația nu acoperă fundalul"}]
EOF
cat > "$LUCRU/declaratie-alta-pagina.json" <<'EOF'
[{"nume": "autotest: altă pagină", "selector": "body.site-shell, body.site-shell > main.site-container",
  "proprietate": "background-color", "pagini": ["/contact"], "motiv": "autotestul G-vizual: pagina nu e în setul redus"}]
EOF

caz "arbore identic: 0 diferențe" 0 "0 diferențe nedeclarate, 0 declarații nefolosite" --latimi=390,1366 --fara-declaratii
caz "arbore identic cu o declarație: declarația nefolosită pică" 1 "nu se potrivește cu nicio diferență" --latimi=1366 \
    --declaratii="$LUCRU/declaratie-fundal.json"

# O singură regulă schimbată într-o copie a app.css, servită doar de al doilea server.
"${PHP_DEV[@]}" -r '
    $css = (string) file_get_contents($argv[1]);
    $vechi = ".site-shell,\n.site-shell > main.site-container {\n    background: #ffffff;\n}";
    if (substr_count($css, $vechi) !== 1) { fwrite(STDERR, "regula de schimbat nu e în app.css (o dată)\n"); exit(1); }
    file_put_contents($argv[2], str_replace($vechi, str_replace("#ffffff", "#fffffe", $vechi), $css));
' "$RADACINA/public/assets/css/app.css" "$LUCRU/app.css" || die "nu pot face copia app.css a autotestului"
porneste "$P2" "$PID2" "$RADACINA/tools/dev/lib/router-css-schimbat.php" CMS_VIZUAL_APP_CSS="$LUCRU/app.css"

caz "o regulă schimbată în app.css: raportată" 1 "background-color: rgb(255, 255, 255) → rgb(255, 255, 254)" --latimi=390,1366 \
    --fara-declaratii
caz "aceeași regulă, declarată: trece" 0 "0 diferențe nedeclarate, 0 declarații nefolosite" --latimi=1366 \
    --declaratii="$LUCRU/declaratie-fundal.json"
caz "declarată pe altă proprietate: pică" 1 "background-color" --latimi=1366 --declaratii="$LUCRU/declaratie-alta-proprietate.json"
caz "declarată pe altă pagină: pică" 1 "background-color" --latimi=1366 --declaratii="$LUCRU/declaratie-alta-pagina.json"

echo "capturi-publice --autotest: $TRECUTE cazuri trecute, $ESECURI eșuate"
[ "$ESECURI" = 0 ]
