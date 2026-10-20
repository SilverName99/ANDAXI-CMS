#!/usr/bin/env bash
# Porțile unui pachet de lucru (WP) din planul Etapei 1: rulează, în ordine, verificările
# pe care planul le cere pentru acel WP și se oprește la prima care pică. Nu există CI
# (.github/ lipsește), deci acesta e CI-ul: un WP se termină doar cu porțile lui trecute.
#
#   tools/dev/porti.sh --wp=WP-01                 # toate porțile WP-01
#   tools/dev/porti.sh --wp=WP-01 --porti=G-rute  # doar unele (de exemplu după o reparație)
#   tools/dev/porti.sh --lista                    # tabelul WP → porți și ce face fiecare poartă
#
# Raportul: $CMS_DEV_DIR/porti-<WP>.txt (doar date demo); ieșirea fiecărei comenzi în
# $CMS_DEV_DIR/porti-<WP>-<poartă>.log. Ultimul rând al raportului (rezumatul) intră în
# mesajul ultimului commit al WP-ului. Cod de ieșire 0 doar dacă toate porțile au trecut.
#
# O poartă a cărei unealtă nu există încă (vine într-un WP următor) pică, cu numele
# WP-ului care o aduce: nu se sare niciodată în tăcere.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"

WP=""
DOAR=""
LISTA=0
for argument in "$@"; do
    case "$argument" in
        --wp=*) WP="${argument#--wp=}" ;;
        --porti=*) DOAR="${argument#--porti=}" ;;
        --lista) LISTA=1 ;;
        *) die "argument necunoscut: $argument (vezi începutul fișierului)" ;;
    esac
done

MINIM="$(php_min)"
[ -n "$MINIM" ] || die "nu găsesc 'php_min' în config/app.php"

# Porțile (planul, §2.5). Fiecare: descriere | comenzi (separate prin „ ;; ”) | WP-ul care aduce unealta.
declare -A DESCRIERE=() COMENZI=() ADUSA_DE=()
poarta() { DESCRIERE["$1"]="$2"; COMENZI["$1"]="$3"; ADUSA_DE["$1"]="$4"; }
poarta G-rute "fiecare rută are proprietar; instantaneul rutelor pe fiecare licență de test" \
    "tools/dev/php.sh tools/dev/verifica-rute.php --scurt ;; tools/dev/php.sh tools/dev/instantaneu-rute.php" WP-01
poarta G-cmp "HTML identic cu codul Bioscem (ultimul commit revizuit), licența „toate” și licența Bioscem" \
    "tools/dev/compara-cu-baza.sh ;; tools/dev/compara-cu-baza.sh --licenta=bioscem" WP-01
poarta G-cmp-istoric "la fel, față de f5ae5ea (codul dinaintea pluginurilor; SEO și porturile din Bioscem scoase explicit)" \
    "tools/dev/compara-cu-baza.sh --baza=f5ae5ea ;; tools/dev/compara-cu-baza.sh --baza=f5ae5ea --licenta=bioscem" WP-01
poarta G-plug "testele pluginurilor (A–G)" "tools/dev/php.sh tools/dev/test-pluginuri.php" WP-01
poarta G-plug-fiecare "testele pluginurilor plus fiecare plugin oprit pe rând (~20 min)" \
    "tools/dev/php.sh tools/dev/test-pluginuri.php --fiecare-plugin" WP-01
poarta G-smoke "testul de fum pe fiecare pachet și pe licența grafoanaytis" \
    "tools/dev/smoke.sh --toate-pachetele ;; tools/dev/smoke.sh --pachet=grafoanaytis" WP-01
poarta G-seo "testele SEO pe fiecare pachet" "tools/dev/php.sh tools/dev/test-seo.php" WP-01
poarta G-php "PHP-ul minim ($MINIM): sintaxa codului de pe site-uri, testul de fum și testele pluginurilor cu php$MINIM" \
    "CMS_DEV_PHP=php$MINIM tools/dev/verifica-php.sh ;; CMS_DEV_PHP=php$MINIM tools/dev/smoke.sh --toate-pachetele ;; CMS_DEV_PHP=php$MINIM tools/dev/php.sh tools/dev/test-pluginuri.php" WP-01
poarta G-runner "runner-ul migrărilor pe MariaDB și MySQL 8 (Schema, Amprenta, fiecare cod de ieșire, reluarea), și cu php$MINIM" \
    "tools/dev/php.sh tools/dev/test-migrari.php --runner ;; CMS_DEV_PHP=php$MINIM tools/dev/php.sh tools/dev/test-migrari.php --runner" WP-02
poarta G-baza "migrările de bază față de schema codului: instalarea nouă = asteptat.json = schema codului (install.php + ensure*) minus excluderile; pe baza făcută de cod 0 operații în grupul B; MariaDB și MySQL 8, și cu php$MINIM" \
    "tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --verifica ;; CMS_DEV_PHP=php$MINIM tools/dev/php.sh tools/dev/migrari/genereaza-fixture.php --verifica" WP-03
poarta G-mig "testele migrărilor: runner-ul și migrările adevărate pe schemele celor cinci coduri (T1–T11, cu G-baza în T1), pe MariaDB și MySQL 8, și cu php$MINIM; verificarea fișierelor de migrare și a DDL-ului din cod" \
    "tools/dev/php.sh tools/dev/test-migrari.php ;; CMS_DEV_PHP=php$MINIM tools/dev/php.sh tools/dev/test-migrari.php ;; tools/dev/php.sh tools/dev/verifica-migrari.php" WP-04
poarta G-inst "instalarea prin migrări (install.php, seed-ul neutru, adminul), garda și /health, pașii de activare ai pluginurilor, 0 DDL pe cerere; și cu php$MINIM; apoi baza de dezvoltare din nou, prin migrări (pentru porțile de după)" \
    "tools/dev/php.sh tools/dev/test-instalare.php ;; CMS_DEV_PHP=php$MINIM tools/dev/php.sh tools/dev/test-instalare.php ;; tools/dev/instaleaza.sh" WP-05
poarta G-vechi "aceleași teste pe o bază făcută ca înainte de migrări și trecută prin baseline (cms_dev_vechi, portul 8711): comparația cu Bioscem (ambele licențe), pluginurile, SEO, fumul pe fiecare pachet; apoi baza se șterge" \
    "CMS_DEV_DB=cms_dev_vechi CMS_DEV_PORT=8711 tools/dev/instaleaza.sh --pe-vechi ;; CMS_DEV_DB=cms_dev_vechi CMS_DEV_PORT=8711 tools/dev/compara-cu-baza.sh ;; CMS_DEV_DB=cms_dev_vechi CMS_DEV_PORT=8711 tools/dev/compara-cu-baza.sh --licenta=bioscem ;; CMS_DEV_DB=cms_dev_vechi CMS_DEV_PORT=8711 tools/dev/php.sh tools/dev/test-pluginuri.php ;; CMS_DEV_DB=cms_dev_vechi CMS_DEV_PORT=8711 tools/dev/php.sh tools/dev/test-seo.php ;; CMS_DEV_DB=cms_dev_vechi CMS_DEV_PORT=8711 tools/dev/smoke.sh --toate-pachetele --pe-vechi ;; CMS_DEV_DB=cms_dev_vechi tools/dev/instaleaza.sh --sterge-baza" WP-05
poarta G-verif "testele site-ului de verificare, și cu php$MINIM (codul lui rulează pe servere)" \
    "tools/dev/php.sh tools/dev/test-verificare.php ;; CMS_DEV_PHP=php$MINIM tools/dev/php.sh tools/dev/test-verificare.php" WP-08a
poarta G-sonde "verificările pe site-urile live (agregate, sonda schemei, din-sonda): doar citire, nimic scris, nicio valoare personală; și cu php$MINIM" \
    "tools/dev/php.sh tools/dev/test-sonde.php ;; CMS_DEV_PHP=php$MINIM tools/dev/php.sh tools/dev/test-sonde.php" WP-10
poarta G-calc "calculele (golden master local) față de același cod de bază ca G-cmp" "tools/dev/compara-calcule-local.sh" WP-11
poarta G-vizual "stilurile calculate ale paginilor publice (capturile, pentru ochi)" "tools/dev/capturi-publice.sh" WP-13
poarta G-pachet "pachetul Bioscem și pachetul neutru" \
    "tools/dev/php.sh tools/dev/test-pachet-site.php bioscem ;; tools/dev/php.sh tools/dev/test-pachet-site.php neutru" WP-14
poarta G-lic "sincronizarea licenței cu CRM-ul (CRM fals)" "tools/dev/php.sh tools/dev/test-licenta-crm.php" WP-27
poarta G-admin "contractul adminului (formulare, câmpuri, linkuri) față de eticheta dinaintea refacerii" \
    "tools/dev/php.sh tools/dev/contract-admin.php" WP-29

# WP → porți, din „Acceptance” și „Bioscem identity” ale fiecărui WP (planul, §5). „Toate porțile”
# = cele care există la acel WP. WP-14 mută G-cmp pe --site=bioscem (se schimbă aici, atunci).
# G-runner (testele noi ale WP-02, „New tests”) e și la WP-03; de la WP-04 îl cuprinde G-mig
# (același fișier, toate secțiunile, și cu PHP-ul minim). G-baza e acceptanța WP-03
# (genereaza-fixture.php --verifica); de la WP-04 o rulează T1 din G-mig (cu ambele PHP-uri), deci
# nu mai apare separat. WP-10 vine imediat după WP-02 (planul, §3), înaintea lui WP-04, deci are
# G-runner, nu G-mig (verifica-migrari.php nu exista încă); fixture-urile din sondă le folosește T2-live
# din WP-04, care are și G-sonde. G-sonde e la fiecare WP care atinge uneltele de pe servere
# (tools/verificare, tools/sonda) sau fixture-urile făcute din sondă. G-inst (WP-05) e la WP-urile care
# ating instalarea, runner-ul sau calea cererii (WP-06, WP-07, WP-41) și reface la final baza de dezvoltare
# prin migrări, deci G-cmp, G-plug și G-seo de după ea rulează pe o bază migrată; G-vechi (WP-05) le rulează
# încă o dată pe o bază veche trecută prin baseline (Bioscem neschimbat pe ambele, planul §5 WP-05).
declare -A PORTI=(
    [WP-01]="G-rute G-cmp G-cmp-istoric G-plug G-smoke G-php"
    [WP-02]="G-rute G-runner G-cmp G-smoke G-php"
    [WP-03]="G-rute G-runner G-baza G-cmp G-smoke G-php"
    [WP-04]="G-rute G-mig G-sonde G-cmp G-smoke G-php"
    [WP-05]="G-rute G-mig G-inst G-cmp G-plug G-smoke G-seo G-vechi G-php"
    [WP-06]="G-rute G-mig G-inst G-cmp G-plug G-smoke G-php"
    [WP-07]="G-rute G-mig G-inst G-cmp G-plug G-smoke G-seo G-php"
    [WP-08a]="G-rute G-verif G-cmp G-php"
    [WP-08]="G-rute G-verif G-sonde G-cmp G-smoke G-php"
    [WP-09]="G-rute G-verif G-sonde G-cmp G-php"
    [WP-10]="G-rute G-sonde G-verif G-runner G-cmp G-php"
    [WP-11]="G-rute G-calc G-cmp G-php"
    [WP-11b]="G-rute G-calc G-sonde G-cmp G-php"
    [WP-12]="G-rute G-mig G-cmp G-plug G-smoke G-php"
    [WP-12b]="G-rute G-mig G-cmp G-plug G-smoke G-calc G-php"
    [WP-13]="G-rute G-mig G-cmp G-vizual G-plug G-smoke G-php"
    [WP-14]="G-rute G-mig G-cmp G-pachet G-plug G-smoke G-php"
    [WP-15]="G-rute G-mig G-cmp G-plug G-smoke G-verif G-php"
    [WP-16]="G-rute G-mig G-cmp G-pachet G-vizual G-plug G-smoke G-php"
    [WP-17]="G-rute G-mig G-cmp G-pachet G-plug G-smoke G-php"
    [WP-18]="G-rute G-mig G-cmp G-pachet G-plug G-smoke G-php"
    [WP-19]="G-rute G-mig G-cmp G-plug-fiecare G-seo G-smoke G-php"
    [WP-20]="G-rute G-mig G-cmp G-calc G-plug G-smoke G-seo G-php"
    [WP-21]="G-rute G-mig G-cmp G-vizual G-plug G-smoke G-php"
    [WP-22]="G-rute G-mig G-cmp G-vizual G-plug G-smoke G-php"
    [WP-23]="G-rute G-mig G-cmp G-plug G-smoke G-php"
    [WP-24]="G-rute G-mig G-cmp G-plug-fiecare G-smoke G-php"
    [WP-25]="G-rute G-mig G-cmp G-plug G-smoke G-calc G-php"
    [WP-26]="G-rute G-mig G-cmp G-plug G-smoke G-seo G-php"
    [WP-27]="G-rute G-lic G-plug G-cmp G-php"
    [WP-28]="G-rute G-mig G-cmp G-plug G-smoke G-php"
    [WP-29]="G-rute G-admin G-cmp G-php"
    [WP-30]="G-rute G-admin G-vizual G-cmp G-plug G-smoke G-php"
    [WP-31]="G-rute G-admin G-cmp G-plug G-php"
    [WP-32]="G-rute G-admin G-cmp G-plug G-smoke G-php"
    [WP-33]="G-rute G-admin G-cmp G-plug G-smoke G-php"
    [WP-34]="G-rute G-admin G-cmp G-plug G-smoke G-php"
    [WP-35]="G-rute G-admin G-vizual G-cmp G-plug G-smoke G-php"
    [WP-36]="G-rute G-admin G-cmp G-plug G-php"
    [WP-37]="G-rute G-mig G-cmp G-seo G-plug G-admin G-php"
    [WP-38]="G-rute G-mig G-cmp G-seo G-plug G-php"
    [WP-39]="G-rute G-mig G-cmp G-plug-fiecare G-seo G-php"
    [WP-40]="G-rute G-mig G-calc G-smoke G-vizual G-cmp G-seo G-php"
    [WP-41]="G-rute G-mig G-inst G-cmp G-plug G-smoke G-seo G-vechi G-calc G-vizual G-pachet G-verif G-sonde G-admin G-php"
    [WP-R]="G-rute G-cmp G-plug G-php"
)
ORDINE_WP="WP-01 WP-02 WP-03 WP-04 WP-05 WP-06 WP-07 WP-08a WP-08 WP-09 WP-10 WP-11 WP-11b WP-12 WP-12b WP-13 WP-14
WP-15 WP-16 WP-17 WP-18 WP-19 WP-20 WP-21 WP-22 WP-23 WP-24 WP-25 WP-26 WP-27 WP-28 WP-29 WP-30 WP-31 WP-32 WP-33
WP-34 WP-35 WP-36 WP-37 WP-38 WP-39 WP-40 WP-41 WP-R"

if [ "$LISTA" = 1 ]; then
    echo "Porțile:"
    for p in G-rute G-cmp G-cmp-istoric G-plug G-plug-fiecare G-smoke G-seo G-php G-runner G-baza G-mig G-inst G-vechi G-verif G-sonde G-calc G-vizual G-pachet G-lic G-admin; do
        printf '  %-15s %s (din %s)\n' "$p" "${DESCRIERE[$p]}" "${ADUSA_DE[$p]}"
    done
    echo
    echo "WP → porți:"
    for w in $ORDINE_WP; do
        printf '  %-7s %s\n' "$w" "${PORTI[$w]}"
    done
    exit 0
fi

[ -n "$WP" ] || die "lipsește --wp=<id> (de exemplu --wp=WP-01; --lista arată tabelul)"
[ -n "${PORTI[$WP]+x}" ] || die "WP necunoscut: $WP (--lista arată tabelul)"
verifica_mediul

alese="${PORTI[$WP]}"
if [ -n "$DOAR" ]; then
    alese=""
    for p in ${DOAR//,/ }; do
        [ -n "${COMENZI[$p]+x}" ] || die "poartă necunoscută: $p"
        alese+="$p "
    done
fi

RAPORT="$CMS_DEV_DIR/porti-$WP.txt"
COMMIT="$(git -C "$RADACINA" rev-parse --short HEAD 2>/dev/null || echo '?')"
MODIFICAT=""
[ -n "$(git -C "$RADACINA" status --porcelain 2>/dev/null)" ] && MODIFICAT=" (+ modificări necomise)"
{
    echo "Porțile $WP — $(date '+%Y-%m-%d %H:%M:%S'), commit $COMMIT$MODIFICAT, PHP $("$CMS_DEV_PHP" -r 'echo PHP_VERSION;'), php_min $MINIM"
    echo "Porți: $alese"
    echo
} > "$RAPORT"

trecute=()
start_total=$SECONDS
for p in $alese; do
    adusa="${ADUSA_DE[$p]}"
    jurnal="$CMS_DEV_DIR/porti-$WP-$p.log"
    : > "$jurnal"
    echo "== $p: ${DESCRIERE[$p]}" | tee -a "$RAPORT"
    start=$SECONDS
    cod=0
    IFS=$'\n' read -r -d '' -a comenzi < <(echo "${COMENZI[$p]}" | sed 's/ ;; /\n/g'; printf '\0')
    for comanda in "${comenzi[@]}"; do
        [ -n "$comanda" ] || continue
        unealta="$(echo "$comanda" | sed -E 's/^([A-Z_]+=[^ ]+ )*//' | awk '{ print ($1 ~ /php\.sh$/) ? $2 : $1 }')"
        if [ ! -e "$RADACINA/$unealta" ]; then
            echo "  EȘEC  $unealta nu există încă (vine în $adusa)" | tee -a "$RAPORT"
            cod=1
            break
        fi
        echo "  ...   $comanda" | tee -a "$RAPORT"
        if (cd "$RADACINA" && eval "$comanda") >> "$jurnal" 2>&1; then
            echo "  OK    $(grep -v '^\s*$' "$jurnal" | tail -1 | cut -c1-200 || true)" | tee -a "$RAPORT"
        else
            cod=$?
            echo "  EȘEC  cod $cod: $comanda (ultimele rânduri mai jos; tot în $jurnal)" | tee -a "$RAPORT"
            { grep -E 'EȘEC|DIFERĂ|EROARE|PORT |SEO ' "$jurnal" || true; } | head -20 | sed 's/^/        /' | tee -a "$RAPORT"
            tail -5 "$jurnal" | sed 's/^/        /' | tee -a "$RAPORT"
            break
        fi
    done
    durata=$((SECONDS - start))
    if [ "$cod" != 0 ]; then
        rezumat="porti $WP: EȘEC la $p după ${#trecute[@]} porți trecute (${trecute[*]:-niciuna}), commit $COMMIT$MODIFICAT"
        { echo; echo "$rezumat"; } | tee -a "$RAPORT"
        exit 1
    fi
    trecute+=("$p")
    echo "  ($p: ${durata}s)" | tee -a "$RAPORT"
done

total=$((SECONDS - start_total))
rezumat="porti $WP: ${#trecute[@]}/${#trecute[@]} porți trecute (${trecute[*]}), commit $COMMIT$MODIFICAT, PHP $("$CMS_DEV_PHP" -r 'echo PHP_VERSION;') + php$MINIM, $((total / 60)) min"
{ echo; echo "$rezumat"; } | tee -a "$RAPORT"
