# ANDAXI-CMS

Codul comun al site-urilor făcute de ANDAXI WEB SOLUTIONS: un singur cod PHP
pentru toate site-urile clienților, în locul câte unei copii pentru fiecare.

Are un nucleu, pe care îl primește orice site (pagini, meniu, galerie, SEO,
emailuri, formular de contact, administratori), și pluginuri (magazin, plăți,
livrare FAN, legătura cu ERP-ul, blog, newsletter etc.), pornite pentru fiecare
site doar din CRM, după contract.

Punctul de plecare este codul site-ului Bioscem.

Cerință: PHP 8.0 sau mai nou (`php_min` în `config/app.php`; versiunea minimă finală e
decizia OD-48, până atunci 8.0), MariaDB sau MySQL.

Pentru programatori: [docs/DEZVOLTARE.md](docs/DEZVOLTARE.md),
[docs/PLUGINURI.md](docs/PLUGINURI.md) (pluginurile, licența semnată, garda rutelor),
[docs/SEO.md](docs/SEO.md) (adresa unică, redirecționările adreselor vechi,
sitemap, robots.txt, date structurate; datele fiecărui site în `site-uri/`),
[docs/de-portat.md](docs/de-portat.md) (ce trebuie adus din codurile vechi ale site-urilor)
și [docs/ui/](docs/ui/README.md) (machetele adminului nou).
