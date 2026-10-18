#!/usr/bin/env bash
# Rulează un script PHP al proiectului (de exemplu un cron) pe baza de test,
# cu aceleași setări ca serverul de dezvoltare: emailuri capturate, cereri
# spre internet blocate, erori în $CMS_DEV_DIR/php-erori.log.
#
#   tools/dev/php.sh scripts/erp-sync.php
#   tools/dev/php.sh scripts/abandoned-cart-emails.php --limit=10

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
verifica_mediul
exporta_mediul_aplicatiei
cd "$RADACINA"
exec "${PHP_DEV[@]}" "$@"
