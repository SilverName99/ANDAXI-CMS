#!/usr/bin/env bash
# Harness-ul de paritate al adoptării WordPress (P3; DECIZIE §6, C2 §10, pista H): referința și candidatul se iau cu aceeași
# unealtă (Playwright Chromium, 6 UA, ferestrele și stările din paritate.json), apoi se compară sondă cu sondă și se scrie
# raportul pe VC-ID-uri. Toate ieșirile stau în afara repo-ului (o ieșire în repo e refuzată, codul 2: lib/iesire.cjs);
# configurația unui site real e doar în pachetul lui privat (site-uri/<site>/privat/paritate.json, ignorat de git; cea din
# pachetul public e refuzată), fixture-ul inventat în tools/dev/paritate-wp/fixture/.
# Stilurile head-ului declarate în normalizari.head.stiluri_pe_reguli (id-urile lor, opt-in) se compară pe reguli (selector →
# declarații, lib/reguli-css.cjs), nu pe text; raportul le arată „comparat pe reguli”.
#
#   paritate-wp.sh captura <configurație.json> [--doar=<pagina>/<profil>/<fereastra>/<stare>] [--reia]
#       configurația: rol (referinta|candidat), site, iesire, cache, jurnal, buget, lacat, din_paritate=<paritate.json>, …
#       (capul lui captura.cjs); la referința live: GET doar spre site, ≤ 2 cereri în zbor, ≥ 3 s între navigări, bugetul
#       numărat în fișier, trackerele și celelalte gazde terțe abortate și scrise în jurnal, non-GET abortat
#   paritate-wp.sh compara --referinta=<dosar> --candidat=<dosar> --config=<paritate.json> --iesire=<dosar> [--felie=1]
#   paritate-wp.sh zgomot --incarcari=<d1>,<d2>,<d3> --config=<paritate.json> --iesire=<dosar> [--scrie=1]
#       încărcările proaspete ale referinței, comparate două câte două: zonele instabile devin măști DECLARATE în config
#       („instabil pe referință (k/N încărcări)”, propus_automat, cu dovada), listate în raport
#   paritate-wp.sh raport scrie --comparatii=<f> --verificare=<verificare-per-id.json> --config=<paritate.json> --iesire=<d>
#       [--felie=1] [--dovezi=<a.json>,…] [--declaratii=<ce-se-schimba.md>] [--commit=<sha>] [--amprenta-baza=<t>] [--manifest=<f>]
#   paritate-wp.sh raport verifica <raport.json> [--sha256=<așteptat>]       # pentru --paritate-ok= (pasul 11)
#   paritate-wp.sh --autotest [--iesire=<dosar>]                              # poarta G-paritate, pe fixture-ul inventat
#
# Parola porții copiei de verificare (candidatul pe copie) doar în mediu: PARITATE_POARTA_UTILIZATOR, PARITATE_POARTA_PAROLA.

set -euo pipefail

AICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Fetch-ul din Node trece prin proxy-ul din mediu doar cu NODE_USE_ENV_PROXY (Node ≥ 22.21); adresele locale merg direct.
export NODE_USE_ENV_PROXY=1 NODE_NO_WARNINGS=1
export PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-/opt/pw-browsers}"
if ! node -e "require.resolve('playwright')" >/dev/null 2>&1; then
    export NODE_PATH="/opt/node22/lib/node_modules${NODE_PATH:+:$NODE_PATH}"
fi
node -e "require.resolve('playwright')" >/dev/null 2>&1 || { echo "EROARE: nu găsesc modulul playwright (NODE_PATH)" >&2; exit 2; }

comanda="${1:-}"
[ -n "$comanda" ] && shift
case "$comanda" in
    captura) exec nice -n 10 node "$AICI/captura.cjs" "$@" ;;
    compara) exec nice -n 10 node "$AICI/compara.cjs" "$@" ;;
    zgomot) exec nice -n 10 node "$AICI/zgomot.cjs" "$@" ;;
    raport) exec node "$AICI/raport.cjs" "$@" ;;
    --autotest) exec nice -n 10 node "$AICI/autotest.cjs" "$@" ;;
    *) sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//' >&2; exit 2 ;;
esac
