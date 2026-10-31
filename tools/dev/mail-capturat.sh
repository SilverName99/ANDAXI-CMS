#!/bin/sh
# Înlocuiește sendmail în dezvoltare: emailul trimis de mail() se scrie într-un
# fișier și nu pleacă nicăieri. Folderul: CMS_DEV_MAIL_DIR (un server al comparației,
# tools/dev/compara-cu-baza.sh), altfel $CMS_DEV_DIR/mail (tools/dev/comun.sh).
dir="${CMS_DEV_MAIL_DIR:-${CMS_DEV_DIR:-$(dirname "$0")/../../storage/logs/dev}/mail}"
mkdir -p "$dir"
cat > "$dir/$(date +%Y%m%d-%H%M%S)-$$.eml"
exit 0
