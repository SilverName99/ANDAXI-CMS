#!/bin/sh
# Înlocuiește sendmail în dezvoltare: emailul trimis de mail() se scrie într-un
# fișier și nu pleacă nicăieri. Folderul vine din CMS_DEV_DIR (tools/dev/comun.sh).
dir="${CMS_DEV_DIR:-$(dirname "$0")/../../storage/logs/dev}/mail"
mkdir -p "$dir"
cat > "$dir/$(date +%Y%m%d-%H%M%S)-$$.eml"
exit 0
