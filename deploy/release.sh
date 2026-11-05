#!/usr/bin/env bash
# deploy/release.sh: vezi docs/DEPLOY.md și capul fișierului deploy/lib/release.php (opțiunile).
source "$(dirname "${BASH_SOURCE[0]}")/lib/php.sh"
exec "${PHP_COMANDA[@]}" "$DOSAR_DEPLOY/lib/release.php" "$@"
