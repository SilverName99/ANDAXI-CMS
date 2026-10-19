#!/usr/bin/env bash
# Compară HTML-ul paginilor-cheie ale site-ului cu cel al commit-ului de bază
# (codul Bioscem dinaintea pluginurilor), pe aceeași bază de date și cu licența
# „toate”: cu toate pluginurile pornite, site-ul trebuie să arate exact ca
# Bioscem azi.
#
#   tools/dev/compara-cu-baza.sh                     # față de f5ae5ea (ultimul commit dinaintea pluginurilor)
#   tools/dev/compara-cu-baza.sh <commit>            # față de alt commit
#   tools/dev/compara-cu-baza.sh --licenta=bioscem   # cu licența reală a Bioscem (fără unealta internă),
#                                                    # pusă înainte; la final se pune la loc „toate”
#
# Codul de bază se scoate cu `git archive` în $CMS_DEV_DIR/baza-<commit> și
# pornește pe portul următor ($CMS_DEV_PORT + 1), cu același mediu și aceeași
# bază; ambele servere primesc aceleași cereri (vizitator, client, admin), iar
# răspunsurile se compară după ce se scot tokenurile și versiunile fișierelor.
# Diferențele se scriu în $CMS_DEV_DIR/compara/<pagina>.diff.
# Cod de ieșire 0 doar dacă toate paginile sunt identice.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
verifica_mediul
exporta_mediul_aplicatiei

COMMIT="f5ae5ea"
LICENTA_COMPARATIE=""
for argument in "$@"; do
    case "$argument" in
        --licenta=*) LICENTA_COMPARATIE="${argument#--licenta=}" ;;
        *) COMMIT="$argument" ;;
    esac
done
COMMIT_SCURT="$(git -C "$RADACINA" rev-parse --short "$COMMIT")" || die "commit necunoscut: $COMMIT"
BAZA_DIR="$CMS_DEV_DIR/baza-$COMMIT_SCURT"
PORT_BAZA=$((CMS_DEV_PORT + 1))
PID_BAZA="$CMS_DEV_DIR/server-baza.pid"

if [ ! -f "$BAZA_DIR/public/index.php" ]; then
    rm -rf "$BAZA_DIR"
    mkdir -p "$BAZA_DIR"
    git -C "$RADACINA" archive "$COMMIT_SCURT" | tar -x -C "$BAZA_DIR"
fi
# Sesiunile și cache-ul de pagini ale copiei de bază, de la rularea trecută.
find "$BAZA_DIR/storage/sessions" -maxdepth 1 -type f -name 'sess_*' -delete 2>/dev/null || true
find "$BAZA_DIR/storage/cache" -mindepth 1 -type f ! -name '.gitkeep' -delete 2>/dev/null || true

opreste_baza() {
    if [ -s "$PID_BAZA" ] && kill -0 "$(cat "$PID_BAZA")" 2>/dev/null; then
        kill "$(cat "$PID_BAZA")" 2>/dev/null || true
    fi
    rm -f "$PID_BAZA"
}
am_pornit_serverul=0
curata() {
    opreste_baza
    if [ -n "$LICENTA_COMPARATIE" ]; then
        "${PHP_DEV[@]}" "$RADACINA/tools/dev/licenta.php" toate >/dev/null 2>&1 || true
    fi
    [ "$am_pornit_serverul" = 1 ] && "$RADACINA/tools/dev/server.sh" --opreste >/dev/null 2>&1 || true
}
trap curata EXIT

if ! curl -fsS --noproxy '*' "http://127.0.0.1:$CMS_DEV_PORT/health" >/dev/null 2>&1; then
    "$RADACINA/tools/dev/server.sh" >/dev/null
    am_pornit_serverul=1
fi

opreste_baza
(
    cd "$BAZA_DIR"
    PHP_CLI_SERVER_WORKERS=4 nohup "${PHP_DEV[@]}" -d "error_log=$CMS_DEV_DIR/php-erori-baza.log" \
        -S "127.0.0.1:$PORT_BAZA" -t "$BAZA_DIR/public" "$BAZA_DIR/tools/dev/router.php" \
        >> "$CMS_DEV_DIR/server-baza.log" 2>&1 &
    echo $! > "$PID_BAZA"
)
for _ in $(seq 1 50); do
    curl -fsS --noproxy '*' "http://127.0.0.1:$PORT_BAZA/health" >/dev/null 2>&1 && break
    sleep 0.2
done
curl -fsS --noproxy '*' "http://127.0.0.1:$PORT_BAZA/health" >/dev/null 2>&1 || die "serverul copiei de bază nu pornește; vezi $CMS_DEV_DIR/server-baza.log"

cd "$RADACINA"
if [ -n "$LICENTA_COMPARATIE" ]; then
    "${PHP_DEV[@]}" tools/dev/licenta.php "$LICENTA_COMPARATIE" >/dev/null || die "licența „$LICENTA_COMPARATIE” nu s-a pus"
fi
"${PHP_DEV[@]}" tools/dev/compara-cu-baza.php "$PORT_BAZA" "$COMMIT_SCURT" "$BAZA_DIR"
