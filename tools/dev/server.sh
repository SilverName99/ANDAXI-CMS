#!/usr/bin/env bash
# Pornește serverul de dezvoltare (php -S) pe baza de test.
#
#   tools/dev/server.sh            # pornește în fundal pe portul $CMS_DEV_PORT (8701)
#   tools/dev/server.sh --opreste  # oprește serverul pornit de aici
#   tools/dev/server.sh --fata     # rulează în prim-plan (Ctrl+C oprește)
#
# Jurnale: $CMS_DEV_DIR/server.log (cereri) și $CMS_DEV_DIR/php-erori.log
# (avertismente, notificări și erori PHP).

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
verifica_mediul
exporta_mediul_aplicatiei

PID_FISIER="$CMS_DEV_DIR/server.pid"

opreste() {
    if [ -s "$PID_FISIER" ] && kill -0 "$(cat "$PID_FISIER")" 2>/dev/null; then
        opreste_server_php "$(cat "$PID_FISIER")"
        echo "Server oprit."
    fi
    rm -f "$PID_FISIER"
}

case "${1:-}" in
    --opreste)
        opreste
        exit 0
        ;;
esac

opreste
cd "$RADACINA"
# Câteva procese în paralel, ca pagina să-și încarce imaginile și scripturile
# în același timp (php -S servește altfel o singură cerere odată).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
COMANDA=("${PHP_DEV[@]}" -S "127.0.0.1:$CMS_DEV_PORT" -t "$RADACINA/public" "$RADACINA/tools/dev/router.php")

if [ "${1:-}" = "--fata" ]; then
    exec "${COMANDA[@]}"
fi

nohup "${COMANDA[@]}" >> "$CMS_DEV_DIR/server.log" 2>&1 &
echo $! > "$PID_FISIER"
for _ in $(seq 1 50); do
    if curl -fsS --noproxy '*' "http://127.0.0.1:$CMS_DEV_PORT/health" >/dev/null 2>&1; then
        echo "Server pornit: http://127.0.0.1:$CMS_DEV_PORT  (admin: /admin, $CMS_DEV_ADMIN_EMAIL, parola în $CMS_DEV_DIR/admin-parola.txt)"
        exit 0
    fi
    sleep 0.2
done
die "serverul nu răspunde la /health; vezi $CMS_DEV_DIR/server.log"
