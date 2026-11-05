#!/usr/bin/env bash
# deploy/revino.sh: vezi docs/DEPLOY.md și capul fișierului deploy/lib/revino.php (opțiunile).
source "$(dirname "${BASH_SOURCE[0]}")/lib/php.sh"
exec "${PHP_COMANDA[@]}" "$DOSAR_DEPLOY/lib/revino.php" "$@"
