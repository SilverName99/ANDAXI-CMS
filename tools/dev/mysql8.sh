#!/usr/bin/env bash
# Al doilea server de test, MySQL 8, lângă MariaDB: runner-ul de migrări trebuie să facă
# același lucru pe amândouă (docs/MIGRARI.md, „Serverele”). Doar un socket, fără port TCP.
#
#   tools/dev/mysql8.sh porneste   # pornește serverul dacă nu merge deja (cod 0 și dacă mergea)
#   tools/dev/mysql8.sh opreste    # îl oprește (doar dacă l-a pornit acest script: are fișierul pid)
#   tools/dev/mysql8.sh stare      # merge sau nu (cod 0 / 1)
#
# Serverul: $CMS_DEV_MYSQL8_DIR (implicit /tmp/claude-0/my8) cu root/usr/sbin/mysqld (pachetul
# mysql-server-core-8.0 despachetat cu dpkg-deb -x), data/ (inițializată o dată cu
# `mysqld --initialize-insecure --datadir=…/data`) și run/. Bazele de test se fac și se șterg
# de testele care le folosesc (doar nume cms_*).

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

MYSQLD="$CMS_DEV_MYSQL8_DIR/root/usr/sbin/mysqld"
LIB="$CMS_DEV_MYSQL8_DIR/root/usr/lib/x86_64-linux-gnu"
RUN="$CMS_DEV_MYSQL8_DIR/run"
PID="$RUN/pid"

merge() {
    [ -S "$CMS_DEV_SOCK_MYSQL8" ] && "$CMS_DEV_PHP" -r '
        try { new PDO("mysql:unix_socket=" . $argv[1], "root", ""); exit(0); } catch (Throwable $e) { exit(1); }' "$CMS_DEV_SOCK_MYSQL8"
}

case "${1:-}" in
    porneste)
        if merge; then
            echo "MySQL 8 merge deja ($CMS_DEV_SOCK_MYSQL8)."
            exit 0
        fi
        [ -x "$MYSQLD" ] || die "nu găsesc $MYSQLD (CMS_DEV_MYSQL8_DIR; vezi capul acestui fișier)"
        [ -d "$CMS_DEV_MYSQL8_DIR/data/mysql" ] || die "$CMS_DEV_MYSQL8_DIR/data nu e inițializat (mysqld --initialize-insecure)"
        mkdir -p "$RUN"
        rm -f "$CMS_DEV_SOCK_MYSQL8" "$CMS_DEV_SOCK_MYSQL8.lock"
        LD_LIBRARY_PATH="$LIB" nohup "$MYSQLD" --no-defaults --user="$(id -un)" --datadir="$CMS_DEV_MYSQL8_DIR/data" \
            --socket="$CMS_DEV_SOCK_MYSQL8" --skip-networking --secure-file-priv="" --pid-file="$PID" \
            --log-error="$RUN/err.log" > /dev/null 2>&1 &
        for _ in $(seq 1 60); do
            merge && { echo "MySQL 8 pornit ($CMS_DEV_SOCK_MYSQL8)."; exit 0; }
            sleep 0.5
        done
        die "MySQL 8 nu a pornit în 30 s (vezi $RUN/err.log)"
        ;;
    opreste)
        if [ -s "$PID" ] && kill -0 "$(cat "$PID")" 2>/dev/null; then
            kill "$(cat "$PID")"
            for _ in $(seq 1 60); do
                kill -0 "$(cat "$PID" 2>/dev/null || echo 0)" 2>/dev/null || { echo "MySQL 8 oprit."; exit 0; }
                sleep 0.5
            done
            die "MySQL 8 nu s-a oprit în 30 s"
        fi
        echo "MySQL 8 nu era pornit de acest script."
        ;;
    stare)
        if merge; then echo "MySQL 8 merge ($CMS_DEV_SOCK_MYSQL8)."; exit 0; fi
        echo "MySQL 8 nu merge."
        exit 1
        ;;
    *)
        die "folosire: tools/dev/mysql8.sh porneste|opreste|stare"
        ;;
esac
