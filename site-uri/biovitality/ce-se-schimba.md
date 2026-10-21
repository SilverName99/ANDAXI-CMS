# Ce se schimbă pentru Biovitality când trece pe codul comun

Lista se predă clientului la adoptare (Etapa 4). Fiecare rând e scris în commit-ul care face schimbarea
(sau care o decide) și spune de ce. Lista e la început (WP-07, revizuirea): o completează porturile
Biovitality din planul Etapei 1 (`port-biovitality.md`) și, până la adoptare, ce primește Biovitality odată
cu codul comun din alte site-uri (de exemplu livrarea 2 SEO, avertismentul pe produs și reparațiile Bioscem
pe care BV nu le are, scrise în `docs/de-portat.md`, secțiunea Biovitality). Tot ce nu e aici rămâne exact
ca azi.

## Ce nu se vede

| Unde | Ce se schimbă | De ce |
|---|---|---|
| `/robots.txt` (WP-07) | Un rând în plus, `Disallow: /_andaxi/`: adresele tehnice ale platformei (pingul de mai jos) nu sunt pagini. | Ca `/webhook/`, o adresă pentru programe, nu pentru motoarele de căutare |
| `/_andaxi/ping` (adresă nouă, WP-07; la fel `/_andaxi/ping/`, cu „/” la sfârșit) | Răspunde cu starea site-ului pentru monitorizare: JSON cu „baza răspunde”, versiunea codului și starea schemei, fără cookie și fără sesiune; 503 dacă baza nu răspunde în 2 secunde. Azi ambele adrese dau pagina 404. | Monitorul din CRM și verificarea de după o actualizare (Etapa 2) văd așa dacă site-ul și baza merg |
| Jurnalul erorilor PHP (WP-07) | Dacă serverul nu are deja un jurnal ales pentru site, erorile PHP ale paginilor se scriu în `storage/logs/php.log` al site-ului; dacă are, rămâne cel de azi. Erorile cronurilor și ale scripturilor de pe server apar ca azi în ieșirea lor (terminalul, e-mailul cronului); după mutarea pe așezarea din Etapa 2 se scriu și în jurnalul site-ului. Sesiunile, încărcările și `.env` rămân unde sunt. | Erorile fiecărui site într-un loc al lui, pe care îl citește verificarea de după o actualizare |
