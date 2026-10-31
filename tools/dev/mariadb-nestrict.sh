#!/usr/bin/env bash
# Al doilea server MariaDB de test, în modul Hostinger: `sql_mode = NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` (fără
# STRICT_TRANS_TABLES) și `max_allowed_packet = 1G`, ca serverul celor patru site-uri live și al site-ului de verificare
# (MariaDB 11.8.9, citit de stare.php pe 21 oct; planul Etapei 1, §0c, predarea (a) către WP-14). Pe serverul comun de
# dezvoltare (strict, ca implicitul MariaDB 10.11) nu se face niciodată SET GLOBAL: modul se dă doar acestui server, la
# pornire. Doar un socket, fără port TCP, cu dosarul lui de date (bazele de test se fac și se șterg de testele care le
# folosesc, doar nume cms_*).
#
#   tools/dev/mariadb-nestrict.sh porneste   # pornește serverul dacă nu merge deja (cod 0 și dacă mergea); îl inițializează prima dată
#   tools/dev/mariadb-nestrict.sh opreste    # îl oprește (doar dacă l-a pornit acest script: are fișierul pid)
#   tools/dev/mariadb-nestrict.sh stare      # merge sau nu, și modul lui (cod 0 / 1)
#   tools/dev/mariadb-nestrict.sh socket     # calea socketului (pentru CMS_DEV_SOCK=…)
#   tools/dev/mariadb-nestrict.sh ruleaza tools/dev/compara-cu-baza.sh   # o unealtă pe acest server: CMS_DEV_SOCK spre el și
#                                            # CMS_DEV_DIR=$CMS_DEV_DIR/nestrict (parola adminului, cheile licenței și copiile
#                                            # lui, deci baza cms_dev a serverului comun rămâne neatinsă); poarta G-nestrict
#
# Dosarul: $CMS_DEV_NESTRICT_DIR (implicit /tmp/claude-0/maria-nestrict) cu data/ și run/. Local e MariaDB 10.11 (pe
# Hostinger 11.8): modul și pachetul maxim sunt aceleași, versiunea nu.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

DOSAR="${CMS_DEV_NESTRICT_DIR:-/tmp/claude-0/maria-nestrict}"
DATE="$DOSAR/data"
RUN="$DOSAR/run"
SOCK="$RUN/sock"
PID="$RUN/pid"
MOD="NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION"
MARIADBD="${CMS_DEV_MARIADBD:-/usr/sbin/mariadbd}"

merge() {
    [ -S "$SOCK" ] && "$CMS_DEV_PHP" -r '
        try { new PDO("mysql:unix_socket=" . $argv[1], "root", ""); exit(0); } catch (Throwable $e) { exit(1); }' "$SOCK"
}

modul() {
    "$CMS_DEV_PHP" -r '$p = new PDO("mysql:unix_socket=" . $argv[1], "root", "");
        echo $p->query("SELECT @@GLOBAL.sql_mode")->fetchColumn(), " | max_allowed_packet ",
            $p->query("SELECT @@GLOBAL.max_allowed_packet")->fetchColumn(), " | ", $p->query("SELECT VERSION()")->fetchColumn();' "$SOCK"
}

case "${1:-}" in
    porneste)
        if merge; then
            echo "MariaDB nestrict merge deja ($SOCK): $(modul)"
            exit 0
        fi
        [ -x "$MARIADBD" ] || die "nu găsesc $MARIADBD (CMS_DEV_MARIADBD)"
        mkdir -p "$RUN"
        if [ ! -d "$DATE/mysql" ]; then
            mkdir -p "$DATE"
            mariadb-install-db --no-defaults --user="$(id -un)" --datadir="$DATE" --auth-root-authentication-method=normal \
                --skip-test-db > "$RUN/install.log" 2>&1 || die "inițializarea în $DATE nu a mers (vezi $RUN/install.log)"
        fi
        rm -f "$SOCK" "$SOCK.lock"
        nohup "$MARIADBD" --no-defaults --user="$(id -un)" --datadir="$DATE" --socket="$SOCK" --skip-networking \
            --pid-file="$PID" --log-error="$RUN/err.log" --sql-mode="$MOD" --max-allowed-packet=1G \
            --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci > /dev/null 2>&1 &
        for _ in $(seq 1 60); do
            merge && { echo "MariaDB nestrict pornit ($SOCK): $(modul)"; exit 0; }
            sleep 0.5
        done
        die "MariaDB nestrict nu a pornit în 30 s (vezi $RUN/err.log)"
        ;;
    opreste)
        proces="$(cat "$PID" 2>/dev/null || true)"
        if [[ "$proces" =~ ^[0-9]+$ ]] && kill -0 "$proces" 2>/dev/null; then
            kill "$proces"
            for _ in $(seq 1 60); do
                kill -0 "$proces" 2>/dev/null || { echo "MariaDB nestrict oprit."; exit 0; }
                sleep 0.5
            done
            die "MariaDB nestrict nu s-a oprit în 30 s"
        fi
        echo "MariaDB nestrict nu era pornit de acest script."
        ;;
    stare)
        if merge; then echo "MariaDB nestrict merge ($SOCK): $(modul)"; exit 0; fi
        echo "MariaDB nestrict nu merge."
        exit 1
        ;;
    socket)
        echo "$SOCK"
        ;;
    ruleaza)
        shift
        [ "$#" -gt 0 ] || die "folosire: tools/dev/mariadb-nestrict.sh ruleaza <comanda> [argumente]"
        merge || die "MariaDB nestrict nu merge ($SOCK): tools/dev/mariadb-nestrict.sh porneste"
        cd "$RADACINA"
        CMS_DEV_SOCK="$SOCK" CMS_DEV_DIR="$CMS_DEV_DIR/nestrict" exec "$@"
        ;;
    *)
        die "folosire: tools/dev/mariadb-nestrict.sh porneste|opreste|stare|socket|ruleaza <comanda>"
        ;;
esac
