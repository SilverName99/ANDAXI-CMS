#!/usr/bin/env bash
# deploy/adopta-wp.sh: vezi docs/DEPLOY.md („Adoptarea unui site WordPress”) și capul fișierului deploy/lib/adopta-wp.php (opțiunile).
source "$(dirname "${BASH_SOURCE[0]}")/lib/php.sh"
exec "${PHP_COMANDA[@]}" "$DOSAR_DEPLOY/lib/adopta-wp.php" "$@"
