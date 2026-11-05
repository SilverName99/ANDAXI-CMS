#!/usr/bin/env bash
# deploy/actualizeaza.sh: vezi docs/DEPLOY.md și capul fișierului deploy/lib/actualizeaza.php (opțiunile).
source "$(dirname "${BASH_SOURCE[0]}")/lib/php.sh"
exec "${PHP_COMANDA[@]}" "$DOSAR_DEPLOY/lib/actualizeaza.php" "$@"
