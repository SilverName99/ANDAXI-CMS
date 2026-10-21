#!/usr/bin/env bash
# Poarta G-calc (WP-11): golden master-ul local și amprenta paginilor, față de același cod de bază ca G-cmp (codul
# Bioscem însuși, la „ultimul commit revizuit” din docs/de-portat.md), pe datele demo. Ce rulează pe site-ul de
# verificare, noaptea (tools/verificare/compara-calcule.php, tools/verificare/amprenta-site.php), se încearcă aici întâi.
#
#   tools/dev/compara-calcule-local.sh                               # față de $CMS_DEV_REPO_BS la commit-ul revizuit
#   tools/dev/compara-calcule-local.sh --baza-repo=<clonă>@<commit>  # față de alt cod vechi
#
# 1. tools/dev/test-calcule.php (G2): compara-calcule.php --local pe o copie a bazei de dezvoltare cu comenzi inventate
#    („REZULTAT: TRECE”, „diferite 0”), prețurile fixe ale transportului, opțiunile, câte o mutație în codul comun care
#    trebuie să pice cu câmpul ei, refuzurile, apoi aceeași comparație pe un site de verificare simulat (docs/VERIFICARE.md,
#    „Comparația calculelor”).
# 2. tools/dev/test-amprenta-site.php (G3): amprenta-site.php pe codul Bioscem și pe codul comun, pe aceeași bază (aceleași
#    câmpuri structurale), normalizarea, comparația și politețea ei („Amprenta paginilor”).
#
# Codul de bază se scoate cu `git archive` în $CMS_DEV_DIR/baza-<repo>-<commit>, exact ca la compara-cu-baza.sh (aceeași
# copie). Baza de dezvoltare trebuie să fie făcută de tools/dev/instaleaza.sh; testele o copiază și n-o schimbă.
# Rapoartele: $CMS_DEV_DIR/test-calcule-raport.txt și test-amprenta-raport.txt. Cod de ieșire 0 doar dacă ambele trec.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
verifica_mediul
exporta_mediul_aplicatiei

REPO_BAZA="$CMS_DEV_REPO_BS"
COMMIT=""
for argument in "$@"; do
    case "$argument" in
        --baza-repo=*)
            valoare="${argument#--baza-repo=}"
            REPO_BAZA="${valoare%@*}"
            [ "$valoare" != "$REPO_BAZA" ] && COMMIT="${valoare##*@}"
            ;;
        *) die "argument necunoscut: $argument (vezi începutul fișierului)" ;;
    esac
done
[ -d "$REPO_BAZA" ] || die "clona de bază $REPO_BAZA lipsește (CMS_DEV_REPO_BS sau --baza-repo=<clonă>@<commit>)."
if [ -z "$COMMIT" ]; then
    [ "$(realpath "$REPO_BAZA")" = "$(realpath -m "$CMS_DEV_REPO_BS")" ] || die "pentru $REPO_BAZA dă și commit-ul: --baza-repo=$REPO_BAZA@<commit>"
    COMMIT="$("$CMS_DEV_PHP" "$RADACINA/tools/dev/de-portat.php" --revizuit=bioscem)" \
        || die "nu pot citi „ultimul commit revizuit” (bioscem) din docs/de-portat.md"
fi
COMMIT_INTREG="$(git -C "$REPO_BAZA" rev-parse "$COMMIT^{commit}")" || die "commit necunoscut în $REPO_BAZA: $COMMIT"
COMMIT_SCURT="$(git -C "$REPO_BAZA" rev-parse --short "$COMMIT_INTREG")"
BAZA_DIR="$CMS_DEV_DIR/baza-$(basename "$REPO_BAZA")-$COMMIT_SCURT"
if [ ! -f "$BAZA_DIR/public/index.php" ]; then
    rm -rf "$BAZA_DIR"
    mkdir -p "$BAZA_DIR"
    git -C "$REPO_BAZA" archive "$COMMIT_SCURT" | tar -x -C "$BAZA_DIR"
fi
[ -e "$BAZA_DIR/.env" ] && die "copia de bază are un .env ($BAZA_DIR/.env); n-ar trebui (git archive nu-l scoate)."
echo "  INFO  cod de bază $(basename "$REPO_BAZA")@$COMMIT_SCURT ($BAZA_DIR)"

cd "$RADACINA"
cod=0
"${PHP_DEV[@]}" tools/dev/test-calcule.php --cod-vechi="$BAZA_DIR" --commit-vechi="$COMMIT_INTREG" || cod=1
"${PHP_DEV[@]}" tools/dev/test-amprenta-site.php --cod-vechi="$BAZA_DIR" || cod=1
if [ "$cod" = 0 ]; then
    echo "compara-calcule-local: golden master-ul (diferite 0) și amprenta paginilor trec față de $(basename "$REPO_BAZA")@$COMMIT_SCURT"
else
    echo "compara-calcule-local: EȘEC (rapoartele: $CMS_DEV_DIR/test-calcule-raport.txt, $CMS_DEV_DIR/test-amprenta-raport.txt)"
fi
exit "$cod"
