# Ce se schimbă pentru vertij când trece pe codul comun

Lista se predă clientului la adoptare. Fiecare rând e scris în commit-ul care face schimbarea
(sau care o decide) și spune de ce. Lista e la început: o completează porturile vertij din planul
Etapei 1 (de exemplu ce înseamnă pentru VT emailurile, conturile membrilor și sesiunile adminului,
`port-vertij.md` §8). Tot ce nu e aici rămâne exact ca azi.

## Ce se vede

| Unde | Ce se schimbă | De ce | De când |
|---|---|---|---|
| Blog: videoul unei postări (câmpul „Video”) și al evenimentului următor, cu un link YouTube sau Vimeo | Playerul YouTube sau Vimeo se încarcă singur doar la un vizitator care a apăsat „Accept toate” în bannerul de cookie-uri; la ceilalți, în locul lui e o casetă de aceeași mărime cu „Pornește videoclipul”, iar playerul se încarcă abia când o apasă (fără JavaScript, linkul deschide videoul pe YouTube sau Vimeo). Playerul Vimeo are `dnt=1` (fără cookie-uri de urmărire). Azi playerul se încarcă la oricine. Fișierele video urcate în Galerie rămân ca azi. | Playerul altui site e conținut care poate urmări vizitatorul (Vimeo pune cookie-uri fără `dnt=1`); codul comun nu încarcă nimic de felul acesta fără acord, ca la analiză și la chat (`CookieConsent`) | WP-R (20 octombrie, revizuirea portului): `App\Support\VideoEmbed`, portat din vertij `0fc2b2c` |
| Toate paginile site-ului, la prima vizită | Apare bannerul de cookie-uri (textul neutru al codului comun, de schimbat din Admin → Setări magazin → Cookies). Microsoft Clarity (statisticile vizitelor), când e pornit în setări, se încarcă doar după ce vizitatorul acceptă; azi se încarcă la oricine, fără întrebare. | Codul comun nu pornește nicio urmărire fără acord. Înghețarea setărilor nu păstrează lipsa bannerului (`cookie_banner_enabled` în `fara_inghetare` din `setari-inghetate.json`), fiindcă fără banner Clarity n-ar mai porni niciodată | înghețarea setărilor (WP-06); comportarea Clarity e a codului comun (`views/layout.php`, `CookieConsent::permiteUrmarire`) |

## Ce nu se vede

| Unde | Ce se schimbă | De ce |
|---|---|---|
| `/robots.txt` (WP-07) | Un rând în plus, `Disallow: /_andaxi/`: adresele tehnice ale platformei (pingul de mai jos) nu sunt pagini. | Ca `/webhook/`, o adresă pentru programe, nu pentru motoarele de căutare |
| `/_andaxi/ping` (adresă nouă, WP-07; la fel `/_andaxi/ping/`, cu „/” la sfârșit) | Răspunde cu starea site-ului pentru monitorizare: JSON cu „baza răspunde”, versiunea codului și starea schemei, fără cookie și fără sesiune; 503 dacă baza nu răspunde în 2 secunde. Azi ambele adrese dau pagina 404. | Monitorul din CRM și verificarea de după o actualizare (Etapa 2) văd așa dacă site-ul și baza merg |
| Jurnalul erorilor PHP (WP-07) | Dacă serverul nu are deja un jurnal ales pentru site, erorile PHP ale paginilor se scriu în `storage/logs/php.log` al site-ului; dacă are, rămâne cel de azi. Erorile cronurilor și ale scripturilor de pe server apar ca azi în ieșirea lor (terminalul, e-mailul cronului); după mutarea pe așezarea din Etapa 2 se scriu și în jurnalul site-ului. Sesiunile, încărcările și `.env` rămân unde sunt. | Erorile fiecărui site într-un loc al lui, pe care îl citește verificarea de după o actualizare |
