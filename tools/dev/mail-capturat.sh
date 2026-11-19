#!/bin/sh
# Înlocuiește sendmail în dezvoltare: emailul trimis de mail() se scrie într-un
# fișier și nu pleacă nicăieri. Folderul: CMS_DEV_MAIL_DIR (un server al comparației,
# tools/dev/compara-cu-baza.sh), altfel $CMS_DEV_DIR/mail (tools/dev/comun.sh).
#
# Numele are lățime fixă, deci ordinea alfabetică (glob) e ordinea trimiterii: data, ora și nanosecundele (dintr-un
# singur apel date) și PID-ul cu zerouri în față. Cu „secunda-PID” două emailuri din aceeași secundă se ordonau după
# PID ca text („…-1003” înaintea lui „…-998”), iar comparația cu baza citea alt „prim” email pe fiecare server.
dir="${CMS_DEV_MAIL_DIR:-${CMS_DEV_DIR:-$(dirname "$0")/../../storage/logs/dev}/mail}"
mkdir -p "$dir"
moment="$(date +%Y%m%d-%H%M%S-%N 2>/dev/null)"
case "$moment" in
    [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]) ;;
    *) moment="$(date +%Y%m%d-%H%M%S)-000000000" ;;
esac
cat > "$dir/$moment-$(printf '%010d' "$$").eml"
exit 0
