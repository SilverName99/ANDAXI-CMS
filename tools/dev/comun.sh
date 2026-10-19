# shellcheck shell=bash
# Setările comune ale uneltelor de dezvoltare locală (tools/dev/*.sh).
#
# Totul se dă aplicației prin variabile de mediu, nu printr-un fișier .env:
# așa nu rămâne în arborele de cod nicio configurare de test care ar putea
# ajunge, din greșeală, pe un site real. Dacă există un .env în rădăcină,
# uneltele refuză să pornească (un .env ar avea prioritate peste aceste valori).
#
# Orice valoare se poate schimba din mediu, de exemplu:
#   CMS_DEV_DB=cms_dev2 CMS_DEV_PORT=8702 tools/dev/smoke.sh

set -euo pipefail

RADACINA="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

CMS_DEV_DB="${CMS_DEV_DB:-cms_dev}"
CMS_DEV_SOCK="${CMS_DEV_SOCK:-/tmp/claude-0/maria/sock}"
# Al doilea server, MySQL 8 (testele runner-ului de migrări rulează pe amândouă: pe Hostinger
# nu se știe încă dacă e MariaDB sau MySQL). Un mysqld 8 despachetat într-un dosar, cu
# root/usr/sbin/mysqld, data/ și run/; tools/dev/mysql8.sh îl pornește și îl oprește.
CMS_DEV_MYSQL8_DIR="${CMS_DEV_MYSQL8_DIR:-/tmp/claude-0/my8}"
CMS_DEV_SOCK_MYSQL8="${CMS_DEV_SOCK_MYSQL8:-$CMS_DEV_MYSQL8_DIR/run/sock}"
export CMS_DEV_MYSQL8_DIR CMS_DEV_SOCK_MYSQL8
CMS_DEV_DB_USER="${CMS_DEV_DB_USER:-root}"
CMS_DEV_DB_PAROLA="${CMS_DEV_DB_PAROLA:-}"
CMS_DEV_PORT="${CMS_DEV_PORT:-8701}"
# Jurnalele serverului, emailurile capturate și parola adminului de test.
CMS_DEV_DIR="${CMS_DEV_DIR:-$RADACINA/storage/logs/dev}"
CMS_DEV_ADMIN_EMAIL="${CMS_DEV_ADMIN_EMAIL:-admin@exemplu.ro}"
# Comanda PHP a tuturor uneltelor, a serverului de dezvoltare și a copiei de bază.
# Implicit `php`; pentru versiunea minimă suportată (config/app.php → php_min):
#   CMS_DEV_PHP=php8.0 tools/dev/smoke.sh --toate-pachetele
CMS_DEV_PHP="${CMS_DEV_PHP:-php}"
export CMS_DEV_PHP
# Clonele codurilor vechi (doar citite: compara-cu-baza.sh --baza-repo, de-portat.php).
# Implicit, alături de acest repo.
CMS_DEV_REPO_BS="${CMS_DEV_REPO_BS:-$(dirname "$RADACINA")/Nou-site-bioscem}"
CMS_DEV_REPO_BV="${CMS_DEV_REPO_BV:-$(dirname "$RADACINA")/Mutare-Site-Biovitality}"
CMS_DEV_REPO_VT="${CMS_DEV_REPO_VT:-$(dirname "$RADACINA")/vertij-tinitus-refacut}"
CMS_DEV_REPO_GR="${CMS_DEV_REPO_GR:-$(dirname "$RADACINA")/site-nou-grafoanaytis.ro}"
export CMS_DEV_REPO_BS CMS_DEV_REPO_BV CMS_DEV_REPO_VT CMS_DEV_REPO_GR

# Un port pe care nu ascultă nimic: orice cerere trimisă acolo eșuează pe loc.
GAURA_NEAGRA="http://127.0.0.1:9"

die() {
    echo "EROARE: $*" >&2
    exit 1
}

verifica_mediul() {
    if [ -e "$RADACINA/.env" ]; then
        die "există $RADACINA/.env; uneltele de dezvoltare nu pornesc cu un .env în rădăcină (ar avea prioritate peste setările de test). Mută-l în altă parte."
    fi
    # Baza se șterge și se reface de la zero: numele trebuie să arate clar că e de test.
    if ! [[ "$CMS_DEV_DB" =~ ^cms_[a-z0-9_]+$ ]]; then
        die "CMS_DEV_DB='$CMS_DEV_DB' nu începe cu cms_; refuz să șterg o bază cu alt nume."
    fi
    [ -S "$CMS_DEV_SOCK" ] || die "MariaDB nu ascultă pe $CMS_DEV_SOCK (vezi docs/DEZVOLTARE.md)."
    command -v "$CMS_DEV_PHP" >/dev/null 2>&1 \
        || die "CMS_DEV_PHP='$CMS_DEV_PHP' nu există (versiunea minimă se instalează cu: tools/dev/instaleaza.sh --php-min)."
    mkdir -p "$CMS_DEV_DIR" "$CMS_DEV_DIR/mail"
}

parola_admin() {
    local fisier="$CMS_DEV_DIR/admin-parola.txt"
    if [ ! -s "$fisier" ]; then
        # Parolă nouă, aleatoare, la fiecare bază nouă; nu e scrisă nicăieri în cod.
        "$CMS_DEV_PHP" -r 'echo bin2hex(random_bytes(9)), "\n";' > "$fisier"
        chmod 600 "$fisier"
    fi
    tr -d '\n' < "$fisier"
}

exporta_mediul_aplicatiei() {
    export APP_NAME="Firma Demo"
    export APP_ENV="local"
    export APP_DEBUG="true"
    export APP_URL="http://127.0.0.1:$CMS_DEV_PORT"
    export APP_TIMEZONE="Europe/Bucharest"
    # host=localhost => PDO folosește socketul din pdo_mysql.default_socket.
    export DB_HOST="localhost"
    export DB_PORT="3306"
    export DB_DATABASE="$CMS_DEV_DB"
    export DB_USERNAME="$CMS_DEV_DB_USER"
    export DB_PASSWORD="$CMS_DEV_DB_PAROLA"
    export ADMIN_DEFAULT_EMAIL="$CMS_DEV_ADMIN_EMAIL"
    ADMIN_DEFAULT_PASSWORD="$(parola_admin)"
    export ADMIN_DEFAULT_PASSWORD

    # Banca Transilvania: doar modul de test, cu date inventate și adresa băncii
    # înlocuită cu gaura neagră; nicio cerere nu pleacă spre bancă.
    export BT_IPAY_MODE="test"
    export BT_IPAY_TEST_USER="demo-bt"
    export BT_IPAY_TEST_PASS="demo-bt-parola"
    export BT_IPAY_TEST_CALLBACK_KEY="demo-bt-cheie"
    export BT_IPAY_LIVE_USER=""
    export BT_IPAY_LIVE_PASS=""
    export BT_IPAY_LIVE_CALLBACK_KEY=""
    export BT_IPAY_BASE_URL_OVERRIDE="$GAURA_NEAGRA"
    # FAN Courier: adresa API-ului înlocuită cu gaura neagră.
    export FAN_API_BASE="$GAURA_NEAGRA"

    # Licența: în locul cheii CRM-ului, o pereche de chei locală (tools/dev/licenta.php,
    # cheia privată în $CMS_DEV_DIR, niciodată în git). Licențele de test le scrie
    # tot tools/dev/licenta.php (instaleaza.sh pune „toate”).
    export CMS_DEV_DIR
    LICENTA_CHEIE_PUBLICA="$("$CMS_DEV_PHP" "$RADACINA/tools/dev/licenta.php" cheie)"
    export LICENTA_CHEIE_PUBLICA
    export LICENTA_SITE="demo"
    unset LICENTA_DOSAR
    # Datele site-ului (redirecționările adreselor vechi, regulile SEO): pachetul
    # inventat din tools/dev/site-demo, nu un dosar site/ rămas în rădăcină.
    export SITE_DATE_DOSAR="${SITE_DATE_DOSAR:-$RADACINA/tools/dev/site-demo}"
    # Garda pluginurilor adaugă antetul X-Andaxi-Garda când ea a răspuns (doar
    # aici; pe site-uri variabila nu există), ca testele să știe cine a răspuns.
    export CMS_DEV_GARDA_ANTET="1"
    # Marcajele porturilor (App\Support\Dev::marcaj): bucățile de pagină aduse dintr-un
    # site vechi apar între <!--port:…--> și <!--/port:…-->, ca compara-cu-baza.php să
    # le scoată explicit. Doar aici; pe site-uri variabila nu există, deci HTML-ul nu
    # are niciun marcaj.
    export CMS_DEV_MARCAJE_PORT="1"

    # Plasa de siguranță pentru tot ce nu are adresă configurabilă (Stripe,
    # SendGrid, Google, ERP cu altă adresă): curl trece prin acest „proxy”, care
    # nu există, deci orice cerere spre internet eșuează imediat. Doar
    # 127.0.0.1/localhost ies direct.
    export http_proxy="$GAURA_NEAGRA" https_proxy="$GAURA_NEAGRA"
    export HTTP_PROXY="$GAURA_NEAGRA" HTTPS_PROXY="$GAURA_NEAGRA"
    export ALL_PROXY="$GAURA_NEAGRA" all_proxy="$GAURA_NEAGRA"
    export NO_PROXY="127.0.0.1,localhost" no_proxy="127.0.0.1,localhost"
}

# PHP cu socketul bazei de test, toate erorile jurnalizate într-un fișier (nu
# afișate în pagină) și mail() înlocuit cu un script care scrie mesajul în
# $CMS_DEV_DIR/mail/ în loc să-l trimită.
PHP_DEV=(
    "$CMS_DEV_PHP"
    -d "pdo_mysql.default_socket=$CMS_DEV_SOCK"
    -d "error_reporting=E_ALL"
    -d "display_errors=0"
    -d "log_errors=1"
    -d "error_log=$CMS_DEV_DIR/php-erori.log"
    -d "sendmail_path=$RADACINA/tools/dev/mail-capturat.sh"
    -d "memory_limit=512M"
    # file_get_contents()/fopen() pe adrese http(s) sunt oprite de tot.
    -d "allow_url_fopen=0"
)

# Oprește un server `php -S` pornit de unelte, cu tot cu procesele lui de lucru
# (PHP_CLI_SERVER_WORKERS): pe PHP 8.0 ele nu se opresc odată cu procesul principal și
# ar rămâne să răspundă pe port. Așteaptă până la 5 secunde.
opreste_server_php() {
    local pid="$1"
    [ -n "$pid" ] || return 0
    pkill -TERM -P "$pid" 2>/dev/null || true
    kill "$pid" 2>/dev/null || true
    for _ in $(seq 1 50); do
        kill -0 "$pid" 2>/dev/null || pgrep -P "$pid" >/dev/null 2>&1 || return 0
        sleep 0.1
    done
}

# php_min din config/app.php (versiunea minimă de PHP suportată de cod).
php_min() {
    sed -n "s/^[[:space:]]*'php_min' => '\([0-9][0-9.]*\)'.*/\1/p" "$RADACINA/config/app.php" | head -1
}

mysql_dev() {
    mysql --default-character-set=utf8mb4 --socket="$CMS_DEV_SOCK" -u"$CMS_DEV_DB_USER" ${CMS_DEV_DB_PAROLA:+-p"$CMS_DEV_DB_PAROLA"} "$@"
}
