#!/usr/bin/env bash
# deploy/plati-in-curs.sh: vezi docs/DEPLOY.md („Comutarea Bioscem”) și capul fișierului deploy/lib/plati-in-curs.php.
source "$(dirname "${BASH_SOURCE[0]}")/lib/php.sh"
exec "${PHP_COMANDA[@]}" "$DOSAR_DEPLOY/lib/plati-in-curs.php" "$@"
