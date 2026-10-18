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
CMS_DEV_DB_USER="${CMS_DEV_DB_USER:-root}"
CMS_DEV_DB_PAROLA="${CMS_DEV_DB_PAROLA:-}"
CMS_DEV_PORT="${CMS_DEV_PORT:-8701}"
# Jurnalele serverului, emailurile capturate și parola adminului de test.
CMS_DEV_DIR="${CMS_DEV_DIR:-$RADACINA/storage/logs/dev}"
CMS_DEV_ADMIN_EMAIL="${CMS_DEV_ADMIN_EMAIL:-admin@exemplu.ro}"

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
    mkdir -p "$CMS_DEV_DIR" "$CMS_DEV_DIR/mail"
}

parola_admin() {
    local fisier="$CMS_DEV_DIR/admin-parola.txt"
    if [ ! -s "$fisier" ]; then
        # Parolă nouă, aleatoare, la fiecare bază nouă; nu e scrisă nicăieri în cod.
        php -r 'echo bin2hex(random_bytes(9)), "\n";' > "$fisier"
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
    php
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

mysql_dev() {
    mysql --default-character-set=utf8mb4 --socket="$CMS_DEV_SOCK" -u"$CMS_DEV_DB_USER" ${CMS_DEV_DB_PAROLA:+-p"$CMS_DEV_DB_PAROLA"} "$@"
}
