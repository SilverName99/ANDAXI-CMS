#!/usr/bin/env bash
# deploy/adopta.sh: vezi docs/DEPLOY.md și capul fișierului deploy/lib/adopta.php (opțiunile).
source "$(dirname "${BASH_SOURCE[0]}")/lib/php.sh"
exec "${PHP_COMANDA[@]}" "$DOSAR_DEPLOY/lib/adopta.php" "$@"
