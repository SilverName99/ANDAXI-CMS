#!/usr/bin/env bash
# Poarta G-compat (adoptarea unui site WordPress, pasul 8a; INSTRUCTIUNI-BUILDER-C2 §8): runtime-ul compat din
# public/assets/compat/ (comun/v1: incarcare, tarziu, clase-ua; manifestul sloturilor divi4/v1/sloturi.json) în Chromium,
# cu Playwright, pe pagini INVENTATE (tools/dev/compat-fixturi/, „Pensiunea Probă”; nicio dată a unui client). Ce verifică:
# începutul lui tools/dev/compat-browser.cjs.
#
#   tools/dev/compat.sh
#
# Fără bază și fără serverul PHP: un server HTTP mic al testului, pe 127.0.0.1 (CMS_DEV_COMPAT_PORT; implicit un port liber),
# cu CSP-ul site-ului. Nicio cerere nu pleacă spre alte gazde (oprite în browser). Playwright se ia din NODE_PATH, Chromium
# din instalarea lui. Raport: $CMS_DEV_DIR/compat-raport.txt. Cod de ieșire 0 doar dacă trec toate verificările.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

command -v node >/dev/null 2>&1 || die "lipsește node (Playwright rulează sub Node)"
mkdir -p "$CMS_DEV_DIR"
export CMS_DEV_DIR
cd "$RADACINA"
exec node "$RADACINA/tools/dev/compat-browser.cjs" "$@"
