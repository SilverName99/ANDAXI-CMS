#!/usr/bin/env bash
# Compară HTML-ul paginilor-cheie ale site-ului cu cel al unui cod de bază, pe
# aceeași bază de date: cu pluginurile Bioscem pornite, site-ul trebuie să arate
# exact ca Bioscem azi.
#
#   tools/dev/compara-cu-baza.sh                                # față de codul Bioscem însuși ($CMS_DEV_REPO_BS),
#                                                               # la „ultimul commit revizuit” din docs/de-portat.md
#   tools/dev/compara-cu-baza.sh --baza-repo=<clonă>@<commit>   # față de un repo vechi, la un commit anume
#   tools/dev/compara-cu-baza.sh --baza=f5ae5ea                 # față de un commit din acest repo (istoric: codul
#                                                               # dinaintea pluginurilor; scoate diferențele SEO și porturile)
#   tools/dev/compara-cu-baza.sh --licenta=bioscem              # cu licența de dezvoltare a Bioscem (config/plugins.php,
#                                                               # licente_dev), pusă înainte; la final se pune la loc „toate”
#   tools/dev/compara-cu-baza.sh --vizual                       # pe aceleași servere, stilurile calculate (G-vizual) în
#                                                               # locul HTML-ului; îl folosește tools/dev/capturi-publice.sh
#
# Codul de bază se scoate cu `git archive`: din repo-ul vechi în
# $CMS_DEV_DIR/baza-<repo>-<commit>, dintr-un commit al acestui repo în
# $CMS_DEV_DIR/baza-<commit>. Pornește pe portul următor ($CMS_DEV_PORT + 1), cu
# același mediu, aceeași bază și același PHP (CMS_DEV_PHP); ambele servere primesc
# aceleași cereri (vizitator, client, admin), iar răspunsurile (codul, Location,
# anteturile: Content-Type, Cache-Control, X-Robots-Tag, cookie-urile … și corpul) se
# compară după ce se scot tokenurile și versiunile fișierelor. Diferențele (anteturile
# în capul fiecărui fișier): $CMS_DEV_DIR/compara/<pagina>.diff.
# Cod de ieșire 0 doar dacă toate paginile sunt identice.
#
# Față de Bioscem (implicit) nu se scoate nimic din livrarea 1 SEO și nimic din
# $porturiBioscem: codul Bioscem le are deja, deci trebuie să iasă octet cu octet.
# Se scot doar diferențele declarate explicit, cu motivul lor, în compara-cu-baza.php
# ($deBioscem, $porturiBiovitality, $porturiVertij, $porturiGrafo și marcajele
# <!--port:…-->), în ambele moduri. O pagină care în codul de bază cade și aici merge
# ($coduriDeBioscem, de exemplu /checkout/succes deschis direct) trece doar cu exact
# codurile declarate și cu textul declarat pe pagina curentă.
#
# Tot față de un repo vechi: harta adreselor vechi se cere din pachetul site-ului și din
# cea scrisă în codul de bază (config/redirecturi.php), iar cele două liste trebuie să fie
# aceleași. Față de Bioscem, și salvările din admin (ce rămâne în bază după un POST: cheia
# ofertelor BBD, categoriile unei postări, duplicarea): aceeași cerere pe ambele servere,
# pe rând, cu baza pusă la loc înainte de fiecare și la final (tools/dev/lib/salvari-admin.php).
#
# Diferențele față de Bioscem care nu se văd în HTML (deci nu le prinde comparația;
# fiecare e scrisă și în docs/DEZVOLTARE.md sau docs/PLUGINURI.md):
#  - scripts/fan-tracking-sync.php: fără cele două `use` care scriau avertismente la
#    fiecare rulare (reparația f5ae5ea, „Erori moștenite” 1) și fără cele trei ALTER TABLE
#    de la fiecare rulare (coloanele le fac migrările, WP-05);
#  - cronurile (erp-sync, bt-ipay-sync, fan-*, newsletter-campaigns, abandoned-cart-emails):
#    garda Plugins::cronPermis la început (cu pluginul pornit rulează ca înainte);
#  - scripts/install.php: instalarea prin migrări, seed-ul neutru și pașii de activare, iar pe
#    o bază care are administratori refuză (iese cu 1, fără să scrie nimic; docs/MIGRARI.md,
#    „Instalarea”); fără parola de admin de rezervă; config/app.php are în plus licența și
#    php_min; scripts/diagnostic.php: licența și php_min;
#  - public/index.php, src/Http/rute.php, src/Http/Router.php: rutele mutate într-un fișier
#    și garda pluginurilor; public/.htaccess: doar un comentariu;
#  - public/index.php: poarta (App\Support\Verificare, docs/VERIFICARE.md), prima. Fără nicio
#    bază configurată (nici .env, nici DB_DATABASE) răspunde 503 „neconfigurat”, unde Bioscem
#    ar merge fără bază, cu textele implicite; cu SITE_COPIE_VERIFICARE=1 cere parola. Aici ambele
#    servere au baza și nu au variabila, deci comparația arată doar că, așa, poarta nu face nimic
#    (pe Bioscem .env există mereu);
#  - public/assets/js/floating-cart.js: fără câmpul de cupon doar când cupoanele sunt oprite
#    (cu pluginul pornit, la fel ca Bioscem);
#  - bootstrap.php: în linia de comandă (cronurile, scripturile) nu mai pornește o sesiune PHP,
#    deci nu mai lasă câte un fișier gol în storage/sessions la fiecare rulare („Erori moștenite” 5,
#    site-uri/bioscem/ce-se-schimba.md); paginile (serverul web, și `php -S` de aici) pornesc
#    sesiunea exact ca Bioscem. Dovada: test-migrari.php (S) și smoke.sh (cronurile) numără
#    fișierele de sesiune;
#  - scripts/migreaza.php și src/Support/Migrari/ (runner-ul migrărilor, docs/MIGRARI.md): noi;
#    database/migrari/ (migrările de bază 0001–0024, WP-03): pe o bază făcută de cod găsesc tot,
#    fără nicio schimbare de schemă (tools/dev/migrari/genereaza-fixture.php --verifica);
#  - garda R1 (Migrari\Garda, WP-05) în capul celor 36 de funcții ensure* și asigura*: pe o bază
#    la zi cu migrările nu mai trimit nicio instrucțiune de schemă, la nicio cerere (dovada:
#    tools/dev/test-instalare.php și smoke.sh numără instrucțiunile cu ContorSql, doar în
#    dezvoltare). Ambele servere de aici lucrează pe aceeași bază la zi, deci HTML-ul nu se
#    schimbă; pe o bază fără registru (Bioscem înainte de adoptare) funcțiile lucrează ca înainte;
#  - /health: a doua linie „schema: <stare>” și 503 „indisponibil” cu baza în urma codului;
#    bannerul „Baza de date nu e la zi cu codul” din admin (doar administratorul general, doar
#    cu baza în urmă sau fără registru: aici nu apare);
#  - pașii de activare ai pluginurilor (config/activari.php, App\Support\Activari): la prima
#    cerere după ce un plugin devine „pornit” (și în cronul erp-sync), o singură dată pe bază,
#    doar pe o bază la zi cu migrările (înainte de --aplica nu scriu nimic);
#    pagina /acorduri-gdpr ștearsă nu mai reapare (ensureOptionalSchema o readucea);
#    erp_trimite_din se scrie doar dacă lipsește (pe Bioscem e înghețat la '' și rămâne);
#  - tot din garda R1, ștergerile adminului rămân (site-uri/bioscem/ce-se-schimba.md): toate
#    categoriile de blog șterse nu mai revin ca cele patru ale Bioscem (ensureOptionalSchema le
#    punea într-un tabel gol), iar o recenzie copiată din tabelul vechi `reviews` și ștearsă nu
#    se mai copiază din nou (ensureOptionalSchema și ensureProductCustomSchema o copiau la fiecare
#    cerere; acum o dată, recenzii/0023). Aici nu se văd (datele demo au categorii și nicio
#    recenzie veche ștearsă); dovada: T10 din test-migrari.php (codul comun nu le readuce, codul
#    Bioscem da);
#  - fișierele scoase la copiere (docs/templates, scripturile unice Bioscem, vezi
#    docs/DEZVOLTARE.md) și config/redirecturi.php, mutat în site-uri/bioscem/redirecturi.json;
#  - livrarea 2 SEO și avertismentul pe produs (WP-R, Bioscem 6c8c789 … 4254ca4): tabelele
#    avertismentului le face migrarea magazin/0032, nu AvertismentProduse::ensureSchema (scos);
#    scripts/seo-texte.php și scripts/avertisment-produse.php sunt generice, cu textele și produsele
#    Bioscem în site-uri/bioscem/ (seo-texte.json, avertismente-produse.json), iar al doilea refuză
#    fără tabelele migrării (în loc să le facă); ce era scris pentru Bioscem în SeoMeta, LogoAntet,
#    PoliticaLivrare, ImagineDistribuire și FluxGoogleMerchant vine din site-uri/bioscem/seo.json
#    (aceleași valori, deci aceleași pagini); variantele WebP ale logo-ului sunt fișiere ale pachetului
#    (site-uri/bioscem/public/, copiate peste public/ la adoptare și, aici, pe durata comparației);
#    schimbările din docs/templates (6c8c789, df21b30) nu se portează (decizia OD-34);
#  - din alt site vechi: videoul postărilor (vertij 0fc2b2c, App\Support\VideoEmbed), cu textul
#    editorului declarat în $porturiVertij și rândul din site-uri/bioscem/ce-se-schimba.md;
#  - căile site-ului prin App\Support\Paths (WP-07): .env, storage/ (sesiunile, cache-ul, jurnalele,
#    licența, importurile, copiile scripturilor, dosarul site-ului de verificare), docroot-ul cu uploads/,
#    sitemap-urile scrise de admin și .htaccess-ul de cache; în așezarea de azi aceleași căi ca până
#    acum, deci aceleași pagini (aici, ambele servere). Așezarea din Etapa 2 (deploy/stub/, sesiunile și
#    jurnalele fiecărui site în dosarul lui) o verifică tools/dev/test-paths.php. Jurnalul erorilor PHP al
#    paginilor: storage/logs/php.log, doar dacă serverul nu are deja unul (error_log gol; aici e cel al
#    uneltelor); linia de comandă rămâne neatinsă (erorile pe stderr, ca în Bioscem);
#    bootstrap.php pornește sesiunea după autoloader (același dosar, aceleași cookie-uri);
#  - GET /_andaxi/ping și /_andaxi/ping/ (App\Support\Ping, nou: în Bioscem 404), fără sesiune și fără
#    cookie: declarate în $coduriDeBioscem; robots.txt are în plus „Disallow: /_andaxi/” ($deBioscem).
#  - gărzile site-ului de verificare (WP-08, App\Support\Verificare, docs/VERIFICARE.md, „Gărzile
#    copiei”): câte o linie în OrderMailer, ErpClient, FanCourierGateway, StripeGateway, BtIpayGateway,
#    EuPlatescGateway, ChatLive, Robots, ImagineDistribuire, SiteController (Google), public/index.php
#    (/uploads/ lipsă, webhookurile) și în cele două layouturi (etichetele terților, bannerul). Fără
#    SITE_COPIE_VERIFICARE=1 nu fac nimic (aici ambele servere sunt fără variabilă, deci aceleași
#    pagini); cu ea le verifică tools/dev/test-verificare.php, cu o „gaură neagră” care numără orice
#    conexiune spre afară.
#  - reparațiile WP-12 care nu se văd în paginile cerute aici (site-uri/bioscem/ce-se-schimba.md; dovada:
#    tools/dev/test-reparatii.php și smoke.sh): finalizarea comenzii verifică și stocul rămas al ofertei BBD
#    (C1b; un POST /checkout peste stocul ofertei duce înapoi în coș, cu mesaj, fără comandă); stocul din ERP
#    cu timeout de 3 secunde, eșecul și SKU-urile necunoscute ținute minte 60 de secunde (C3; aici ERP-ul e
#    oprit, deci nimic din pagini); heartbeat-ul, sumarul coșului, catalogul și cele mai vândute eliberează
#    lacătul sesiunii după ce au citit-o (C4; răspunsurile, la fel); JSON-ul gărzii pe /api/… are și `error`
#    (X6; nicio adresă /api/… a unui plugin oprit nu se cere aici); pragul trimiterii în ERP (E1): cu
#    erp_trimite_din gol (înghețarea Bioscem), cronul erp-sync, „Retrimite” și contoarele din Setări → ERP
#    ANDAXI rămân ca în Bioscem. Comparația pune pe durata ei, în baza de test, setările înghețate ale
#    Bioscem (site-uri/bioscem/setari-inghetate.json, fără cele private; azi erp_trimite_din gol), cum le
#    are baza Bioscem după adoptare, iar la final valorile de dinainte (pe baza de dezvoltare pragul e
#    momentul instalării, pus de pasul de activare). Ce se vede în admin (CodeMirror doar în cele patru
#    editoare, C6; pozele leneșe ale selectoarelor, C7; categoria doar ca text a unei postări, X1b) e
#    declarat în compara-cu-baza.php ($porturiBiovitality, $deBioscem), iar salvările C2 și X1b sunt
#    cazurile de tip „reparatie” de acolo.
#  - reparațiile de securitate WP-12b (site-uri/bioscem/ce-se-schimba.md; dovada: tools/dev/test-securitate.php,
#    din smoke.sh, și G-sonde pentru numărătoarea parolelor): logarea adminului (App\Support\ParolaAdmin) verifică
#    un hash doar ca hash (și argon2, și bcrypt-ul vechi $2a$/$2b$; alt hash, ca $6$ sau $P$, nu intră deloc), compară
#    în clar doar o parolă salvată necriptat și o criptează la prima logare, cu un rând „parola_convertita” în Jurnal
#    activitate, iar un hash cu un cost mai mic decât cel implicit îl reface (nu coboară costul); la fiecare logare (adminul, clientul) sesiunea primește alt id (App\Support\Sesiune;
#    id-ul de dinainte rămâne fără nicio logare, iar coșul abandonat își păstrează cheia de dinainte de logare), deci cookie-ul
#    răspunsului la POST /admin/login și POST /login e altul (comparația nu compară răspunsurile login-ului, doar
#    unde duce, iar borcanele de cookie-uri iau id-ul nou); adresa vizitatorului vine doar din App\Support\IpClient
#    (limitele checkout-ului și ale înregistrării, cu IPv6 pe /64, jurnalul adminului, GDPR, contactul, newsletterul,
#    mentenanța, cu IP-urile ei comparate ca adrese). Aici nicio cerere nu trimite X-Forwarded-For, adminii demo au
#    parole bcrypt (o bază făcută de php8.3, costul 10, servită de PHP 8.4 își ridică o dată costul la prima logare
#    pe codul comun; hash-ul nu apare în nicio pagină și nu e în rândurile cazurilor de salvare), iar pe 127.0.0.1
#    adresa e aceeași pentru ambele coduri, deci paginile, Securitate formulare și „IP-ul tău curent” din Setări
#    magazin ies la fel.
#  - tema site-ului (WP-13, App\Support\Tema, docs/PACHETE.md): fără tema.json (pachetul Bioscem n-are încă una) fiecare
#    punct al temei din views/layout.php și din vederile de rezervă (404, mulțumirea, linkul de plată, verificarea BT)
#    scrie exact ce scria. Lista de pagini nu vede vederile de rezervă (baza demo are paginile ei „404” și
#    „checkout/succes”), nici sertarul de meniu ({{mobile_menu}}): le compară cu codul Bioscem cazul „vederi” din
#    compara-cu-baza.php (cu cele două pagini din bază nepublicate și un antet cu sertarul, pe durata cazului: 404,
#    un produs care nu există, verificarea plății BT, mulțumirea, prima pagină); linkul de plată e în lista de pagini.
#    tools/dev/test-pluginuri.php T3 arată în plus că tema.json gol și fiecare cheie scrisă la implicit dau aceleași
#    pagini ca fără temă, iar pentru CSS e G-vizual (--vizual, mai sus; tools/dev/capturi-publice.sh). Noi, fără efect
#    în pagini: fonturile și Bootstrap-ul în public/assets/vendor/, /tema/… în routerul de dezvoltare, pachet.json în
#    pachete, scripts/diagnostic.php --pachet, .gitattributes (export-ignore).
#
# Fiecare logare de la început (administratorul, clientul demo, adminii cu rol parțial) trebuie să ajungă unde duce o
# logare reușită; altfel comparația se oprește (codul 1), în loc să compare în gol paginile de login ale ambelor coduri.

source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
verifica_mediul
exporta_mediul_aplicatiei

MOD="repo"
REPO_BAZA=""
COMMIT=""
LICENTA_COMPARATIE=""
VIZUAL=0
for argument in "$@"; do
    case "$argument" in
        --licenta=*) LICENTA_COMPARATIE="${argument#--licenta=}" ;;
        --vizual) VIZUAL=1 ;;
        --baza-repo=*)
            MOD="repo"
            valoare="${argument#--baza-repo=}"
            REPO_BAZA="${valoare%@*}"
            [ "$valoare" != "$REPO_BAZA" ] && COMMIT="${valoare##*@}"
            ;;
        --baza-repo) MOD="repo" ;;
        --baza=*) MOD="commit"; COMMIT="${argument#--baza=}" ;;
        --*) die "argument necunoscut: $argument" ;;
        # Ca până acum: un commit din acest repo.
        *) MOD="commit"; COMMIT="$argument" ;;
    esac
done

# G-vizual compară doar față de codul unui site vechi: față de un commit al acestui repo (istoric) diferențele HTML
# declarate în $porturiBioscem ar schimba și stilurile, iar acolo nu sunt declarate.
[ "$VIZUAL" = 1 ] && [ "$MOD" = "commit" ] && die "--vizual merge doar față de codul unui site vechi (--baza-repo), nu față de --baza=<commit>"
PORT_CURENT="$CMS_DEV_PORT"
if [ "$MOD" = "repo" ]; then
    REPO_BAZA="${REPO_BAZA:-$CMS_DEV_REPO_BS}"
    [ -d "$REPO_BAZA" ] || die "clona de bază $REPO_BAZA lipsește (CMS_DEV_REPO_BS sau --baza-repo=<clonă>@<commit>)."
    # Al cărui site e clona (pentru commit-ul revizuit și pentru pachetul de date al site-ului).
    SITE_BAZA=""
    for pereche in "bioscem:$CMS_DEV_REPO_BS" "biovitality:$CMS_DEV_REPO_BV" "vertij:$CMS_DEV_REPO_VT" "grafoanaytis:$CMS_DEV_REPO_GR"; do
        [ "$(realpath "$REPO_BAZA")" = "$(realpath -m "${pereche#*:}")" ] && SITE_BAZA="${pereche%%:*}"
    done
    if [ -z "$COMMIT" ]; then
        # Ultimul commit revizuit (și portat) al site-ului, din docs/de-portat.md.
        [ -n "$SITE_BAZA" ] || die "pentru $REPO_BAZA dă și commit-ul: --baza-repo=$REPO_BAZA@<commit>"
        COMMIT="$("$CMS_DEV_PHP" "$RADACINA/tools/dev/de-portat.php" --revizuit="$SITE_BAZA")" \
            || die "nu pot citi „ultimul commit revizuit” ($SITE_BAZA) din docs/de-portat.md"
    fi
    COMMIT_SCURT="$(git -C "$REPO_BAZA" rev-parse --short "$COMMIT^{commit}")" || die "commit necunoscut în $REPO_BAZA: $COMMIT"
    NUME_REPO="$(basename "$REPO_BAZA")"
    BAZA_DIR="$CMS_DEV_DIR/baza-$NUME_REPO-$COMMIT_SCURT"
    ETICHETA_BAZA="$NUME_REPO@$COMMIT_SCURT"
    # Codul vechi își are datele site-ului scrise în cod (harta adreselor vechi, regulile
    # SEO); codul comun le citește din pachetul site-ului. Comparația folosește deci
    # pachetul lui din site-uri/<site>/, nu cel inventat din tools/dev/site-demo.
    if [ -n "$SITE_BAZA" ] && [ -d "$RADACINA/site-uri/$SITE_BAZA" ]; then
        export SITE_DATE_DOSAR="$RADACINA/site-uri/$SITE_BAZA"
    fi
    # Un server al codului curent doar pentru comparație (portul +4), cu datele de mai sus:
    # serverul de dezvoltare, dacă merge, rămâne neatins.
    PORT_CURENT=$((CMS_DEV_PORT + 4))
else
    COMMIT="${COMMIT:-f5ae5ea}"
    COMMIT_SCURT="$(git -C "$RADACINA" rev-parse --short "$COMMIT^{commit}")" || die "commit necunoscut: $COMMIT"
    BAZA_DIR="$CMS_DEV_DIR/baza-$COMMIT_SCURT"
    ETICHETA_BAZA="$COMMIT_SCURT"
fi
PORT_BAZA=$((CMS_DEV_PORT + 1))
PID_BAZA="$CMS_DEV_DIR/server-baza.pid"
PID_CURENT="$CMS_DEV_DIR/server-compara.pid"

if [ ! -f "$BAZA_DIR/public/index.php" ]; then
    rm -rf "$BAZA_DIR"
    mkdir -p "$BAZA_DIR"
    if [ "$MOD" = "repo" ]; then
        git -C "$REPO_BAZA" archive "$COMMIT_SCURT" | tar -x -C "$BAZA_DIR"
    else
        git -C "$RADACINA" archive "$COMMIT_SCURT" | tar -x -C "$BAZA_DIR"
    fi
fi
[ -e "$BAZA_DIR/.env" ] && die "copia de bază are un .env ($BAZA_DIR/.env); n-ar trebui (git archive nu-l scoate)."
# Sesiunile și cache-ul de pagini ale copiei de bază, de la rularea trecută.
find "$BAZA_DIR/storage/sessions" -maxdepth 1 -type f -name 'sess_*' -delete 2>/dev/null || true
find "$BAZA_DIR/storage/cache" -mindepth 1 -type f ! -name '.gitkeep' -delete 2>/dev/null || true
# Fluxul Merchant Center al codului curent stă 15 minute în cache: o rulare veche nu răspunde în locul codului.
rm -f "$RADACINA/storage/cache/feed-google-merchant.xml"

# Ce pun aici în public/ (și scot la final): logo-ul din antet și fișierele publice ale pachetului.
FISIERE_PUSE=()
DOSARE_PUSE=()
scoate_fisierele_puse() {
    local f d i
    for f in "${FISIERE_PUSE[@]}"; do rm -f "$f"; done
    for (( i=${#DOSARE_PUSE[@]}-1; i>=0; i-- )); do
        d="${DOSARE_PUSE[$i]}"
        rmdir "$d" 2>/dev/null || true
    done
    FISIERE_PUSE=()
    DOSARE_PUSE=()
}
trap scoate_fisierele_puse EXIT
if [ "$MOD" = "repo" ] && [ -n "${SITE_DATE_DOSAR:-}" ]; then
    # Logo-ul din antet al site-ului (seo.json → logo_antet.png, de exemplu la Bioscem
    # /uploads/gallery/bioscem-logo-2.png, 977×240): datele demo nu-l au, iar fără el nici
    # codul de bază, nici cel curent nu pun variantele WebP și nici poza de distribuire
    # implicită (Bioscem a14e803, 7455b77). O poză inventată, de exact acea mărime, doar pe
    # durata comparației (încărcările se copiază mai jos și în codul de bază).
    LOGO_ANTET="$("${PHP_DEV[@]}" -r '$j = json_decode((string) @file_get_contents($argv[1] . "/seo.json"), true);
        $l = is_array($j) ? ($j["logo_antet"] ?? null) : null;
        if (is_array($l) && is_string($l["png"] ?? null) && is_array($l["dimensiuni"] ?? null)) {
            echo $l["png"], " ", (int) $l["dimensiuni"][0], " ", (int) $l["dimensiuni"][1];
        }' "$SITE_DATE_DOSAR")"
    if [ -n "$LOGO_ANTET" ]; then
        read -r LOGO_CALE LOGO_LATIME LOGO_INALTIME <<< "$LOGO_ANTET"
        case "$LOGO_CALE" in /uploads/*) ;; *) die "seo.json → logo_antet.png nu e în /uploads/: $LOGO_CALE" ;; esac
        LOGO_FISIER="$RADACINA/public$LOGO_CALE"
        if [ ! -e "$LOGO_FISIER" ]; then
            LOGO_DOSAR="$(dirname "$LOGO_FISIER")"
            [ -d "$LOGO_DOSAR" ] || { mkdir -p "$LOGO_DOSAR"; DOSARE_PUSE+=("$LOGO_DOSAR"); }
            "${PHP_DEV[@]}" -r '$i = imagecreatetruecolor((int) $argv[2], (int) $argv[3]);
                imagefill($i, 0, 0, imagecolorallocate($i, 30, 110, 60));
                imagepng($i, $argv[1]) || exit(1);' "$LOGO_FISIER" "$LOGO_LATIME" "$LOGO_INALTIME" \
                || die "nu pot face logo-ul de test $LOGO_FISIER"
            FISIERE_PUSE+=("$LOGO_FISIER")
        fi
    fi
    # Fișierele publice ale pachetului (site-uri/<site>/public/, de exemplu variantele WebP ale
    # logo-ului) se copiază la adoptare peste public/ (site-uri/README.md); aici, la fel, doar
    # pe durata comparației. Unul care există deja în public/ cu alt conținut oprește comparația.
    if [ -d "$SITE_DATE_DOSAR/public" ]; then
        while IFS= read -r -d '' sursa; do
            relativ="${sursa#"$SITE_DATE_DOSAR/public/"}"
            tinta="$RADACINA/public/$relativ"
            if [ -e "$tinta" ]; then
                cmp -s "$sursa" "$tinta" || die "public/$relativ există deja și diferă de cel din pachet ($sursa)"
                continue
            fi
            dosar_tinta="$(dirname "$tinta")"
            [ -d "$dosar_tinta" ] || { mkdir -p "$dosar_tinta"; DOSARE_PUSE+=("$dosar_tinta"); }
            cp -p "$sursa" "$tinta"
            FISIERE_PUSE+=("$tinta")
        done < <(find "$SITE_DATE_DOSAR/public" -type f -print0)
    fi
fi
# Aceleași fișiere încărcate ca site-ul curent (pozele demo, refăcute la fiecare instalare):
# codul de bază își calculează și el width/height din fișiere. Copie, nu legătură: ca și
# codul curent, nu citește poze din afara dosarului lui public/.
rm -rf "$BAZA_DIR/public/uploads"
[ -d "$RADACINA/public/uploads" ] && cp -a "$RADACINA/public/uploads" "$BAZA_DIR/public/uploads"
# Codurile vechi nu au uneltele de dezvoltare: routerul lui `php -S` vine de aici (face doar
# ce fac cele două .htaccess, deci nu schimbă ce răspunde codul de bază).
ROUTER_BAZA="$BAZA_DIR/tools/dev/router.php"
[ -f "$ROUTER_BAZA" ] || ROUTER_BAZA="$RADACINA/tools/dev/router.php"

opreste_pid() {
    local fisier="$1"
    if [ -s "$fisier" ] && kill -0 "$(cat "$fisier")" 2>/dev/null; then
        opreste_server_php "$(cat "$fisier")"
    fi
    rm -f "$fisier"
}
am_pornit_serverul=0
curata() {
    opreste_pid "$PID_BAZA"
    opreste_pid "$PID_CURENT"
    scoate_fisierele_puse
    if [ -n "$LICENTA_COMPARATIE" ]; then
        "${PHP_DEV[@]}" "$RADACINA/tools/dev/licenta.php" toate >/dev/null 2>&1 || true
    fi
    [ "$am_pornit_serverul" = 1 ] && "$RADACINA/tools/dev/server.sh" --opreste >/dev/null 2>&1 || true
}
trap curata EXIT

# porneste_server <pid> <port> <dosar> <router> <jurnal-erori> <jurnal-server>
porneste_server() {
    local pid="$1" port="$2" dosar="$3" router="$4" erori="$5" jurnal="$6"
    opreste_pid "$pid"
    # Un server rămas pe port (pornit de altcineva) ar răspunde în locul celui de aici.
    if curl -fsS --noproxy '*' -o /dev/null "http://127.0.0.1:$port/health" 2>/dev/null; then
        die "pe portul $port răspunde deja un server (nepornit de această comparație); oprește-l întâi"
    fi
    (
        cd "$dosar"
        PHP_CLI_SERVER_WORKERS=4 nohup "${PHP_DEV[@]}" -d "error_log=$erori" \
            -S "127.0.0.1:$port" -t "$dosar/public" "$router" >> "$jurnal" 2>&1 &
        echo $! > "$pid"
    )
    for _ in $(seq 1 50); do
        if curl -fsS --noproxy '*' "http://127.0.0.1:$port/health" >/dev/null 2>&1; then
            kill -0 "$(cat "$pid")" 2>/dev/null || die "serverul de pe portul $port s-a oprit; vezi $jurnal"
            return 0
        fi
        sleep 0.2
    done
    die "serverul de pe portul $port nu pornește; vezi $jurnal"
}

if [ "$PORT_CURENT" = "$CMS_DEV_PORT" ]; then
    if ! curl -fsS --noproxy '*' "http://127.0.0.1:$CMS_DEV_PORT/health" >/dev/null 2>&1; then
        "$RADACINA/tools/dev/server.sh" >/dev/null
        am_pornit_serverul=1
    fi
else
    porneste_server "$PID_CURENT" "$PORT_CURENT" "$RADACINA" "$RADACINA/tools/dev/router.php" \
        "$CMS_DEV_DIR/php-erori.log" "$CMS_DEV_DIR/server-compara.log"
fi
# Codul de bază nu scrie marcaje de port (doar codul curent le are).
(
    unset CMS_DEV_MARCAJE_PORT
    porneste_server "$PID_BAZA" "$PORT_BAZA" "$BAZA_DIR" "$ROUTER_BAZA" \
        "$CMS_DEV_DIR/php-erori-baza.log" "$CMS_DEV_DIR/server-baza.log"
)

cd "$RADACINA"
if [ -n "$LICENTA_COMPARATIE" ]; then
    "${PHP_DEV[@]}" tools/dev/licenta.php "$LICENTA_COMPARATIE" >/dev/null || die "licența „$LICENTA_COMPARATIE” nu s-a pus"
fi
[ "$MOD" = "repo" ] && echo "  INFO  bază $ETICHETA_BAZA (port $PORT_BAZA), cod curent pe portul $PORT_CURENT, datele site-ului: ${SITE_DATE_DOSAR#"$RADACINA"/}"
if [ "$VIZUAL" = 1 ]; then
    # Aceleași două servere, aceleași date ale site-ului; în locul HTML-ului, stilurile calculate ale paginilor publice
    # (tools/dev/capturi-publice.php și .cjs; poarta G-vizual).
    "${PHP_DEV[@]}" tools/dev/capturi-publice.php compara --baza="http://127.0.0.1:$PORT_BAZA" --curent="http://127.0.0.1:$PORT_CURENT" \
        --eticheta="$ETICHETA_BAZA (licența ${LICENTA_COMPARATIE:-toate})" --dosar="compara-${LICENTA_COMPARATIE:-toate}"
    exit $?
fi
"${PHP_DEV[@]}" tools/dev/compara-cu-baza.php "$PORT_BAZA" "$ETICHETA_BAZA" "$BAZA_DIR" "$MOD" "$PORT_CURENT" "${SITE_BAZA:-}"
