# Ce se schimbă pentru site când trece de pe WordPress pe codul comun

Lista se predă proprietarului la adoptare. Implicitul e „identic”: paginile ies din documentele capturate de pe site-ul de azi, iar
părțile care se schimbă după vizitator (coșul, checkout-ul, contul, listele de articole, calendarul) din regiunile dinamice, în
markupul de azi. Fiecare abatere e scrisă aici, cu motivul, în commit-ul pistei care o face (docs/MAGAZIN-WC.md).

## Ce se vede

| ID | Unde | Ce se schimbă | De ce |
|---|---|---|---|
| VC-PLATA-001 | Scripturile Stripe | Se încarcă doar pe checkout și pe paginile metodelor de plată din cont, nu și pe paginile de produs și pe coș. Politica de securitate a paginilor (CSP) permite sursele Stripe doar acolo. | Pe celelalte pagini nu e nevoie de ele (butoanele de plată rapidă sunt oprite și azi). |

## Ce nu se vede (dar se verifică)

| Unde | Ce se schimbă | De ce |
|---|---|---|
| Paginile coșului, ale checkout-ului și ale contului | Nu se țin niciodată în cache (`Cache-Control: no-store, private`); celelalte pagini da, fără nicio dată a vizitatorului în ele. | Datele unui vizitator nu ajung niciodată la altul. |

## Newsletter: adresele vechi ale MailPoet, paginile abonamentului, formularele de abonare (pista E)

Linkurile din emailurile trimise de MailPoet merg mai departe (dezabonarea, administrarea, confirmarea, urmărirea, „vezi în
browser”), cu paginile de azi. Abaterile:

| ID | Unde | Ce se schimbă | De ce |
|---|---|---|---|
| VC-MP-01 | Dezabonarea „cu un clic” din emailurile vechi (antetul cu linkul urmărit) | Un POST pe linkul urmărit de dezabonare dezabonează pe loc și răspunde 200, nu cu o redirecționare. | RFC 8058 interzice redirecționarea; clienții de email n-o urmează. |
| VC-MP-02 | Pagina „Confirmă că vrei să te dezabonezi” | „Da, dezabonează-mă” e un buton cu aspectul legăturii (textul lui e o legătură fără adresă, deci are culoarea legăturilor temei); comentariul HTML de deasupra lipsește. În sondajul de pe pagina de după dezabonare, cu „Altele” pornit în setări, câmpul de text se vede mereu. | Fără script în pagină (CSP, politica HTML-ului capturat); merge și fără JavaScript. |
| VC-MP-03 | Formularul „Administrează-ți abonamentul” | Se trimite la propria adresă (cu un jeton anti-CSRF), nu la `/wp-admin/admin-post.php`, și răspunde cu o redirecționare 303. | `/wp-admin/` nu mai există. |
| VC-MP-04 | După „Da, dezabonează-mă” | Browserul ajunge (303) la pagina „Acum ești dezabonat.”, în loc s-o primească drept răspuns la POST. | Reîncărcarea nu retrimite. Aceeași pagină. |
| VC-MP-05 | Paginile abonamentului | `og:title`, `twitter:title` și numele din JSON-LD rămân cele ale paginii de administrare; foaia de stil MailPoet e și pe pagina „nu avem o înregistrare despre tine”. | Toate stările stau în același cadru capturat; paginile sunt noindex, iar foaia stilizează doar elementele MailPoet. |
| VC-MP-06 | Administrarea, pentru clienții magazinului și utilizatorii WordPress | „Autentifică-te în contul tău” duce la `/my-account/`, nu la `/wp-login.php`. | Autentificarea WordPress nu mai există. |
| VC-MP-07 | Linkuri vechi cu un „+” transformat în spațiu | Merg (MailPoet arăta „nu avem o înregistrare despre tine”). | Decodare îngăduitoare; nu schimbă niciun link pe care MailPoet îl decodează. |
| VC-MP-08 | Capetele MailPoet ale adminului | Răspund 404, nu 403. | Nu mai există adminul WordPress. |
| VC-MP-09 | Abonații din coșul MailPoet | Linkurile lor arată „nu avem o înregistrare despre tine”; o adresă din coș care se abonează din nou e o adresă nouă, cu emailul de confirmare (MailPoet o scotea din coș, iar una abonată rămânea abonată, fără email). | Cei din coș nu se importă (cei dezabonați sau săriți ajung pe lista „nu trimite”). |
| VC-MP-10 | Paginile și linkurile făcute după o vizită | Poartă jetonul cu care a venit vizitatorul, nu jetonul păstrat de MailPoet; emailurile trimise de site au un jeton al site-ului. | Din jetoanele MailPoet păstrăm doar amprenta (sha256). |
| VC-MP-11 | Jetoane greșite și linkuri necunoscute | Un clic urmărit cu jeton greșit → pagina 404 (MailPoet: 403 gol); un link valid cu hash necunoscut → prima pagină (MailPoet: 404); pixelul de deschidere cu jeton greșit → tot imaginea transparentă (MailPoet: 403); pagina „nu avem o înregistrare despre tine” are statusul 404, cu același conținut; în „vezi în browser”, un link fără adresă păstrată duce tot la prima pagină (MailPoet lăsa textul codului, o adresă stricată). | Poarta G-mailpoet-compat; nicio informație din răspunsul unei imagini. |
| VC-MP-12 | Clicul pe un link dintr-un email vechi și confirmarea abonării | Nu pun niciun cookie (MailPoet punea, cu urmărirea prin cookie pornită, `mailpoet_revenue_tracking` la clic și `mailpoet_subscriber`, pe 10 ani, la clic și la confirmare). | Politica de cookie-uri e refăcută fără MailPoet. |
| VC-MP-13 | „Vezi în browser” | Emailul se arată fără bara de partajare a lui MailPoet; pagina are o politică CSP proprie, fără scripturi. Un email cu fonturi Google se vede cu fonturile de rezervă din stilurile lui (pagina nu cere nimic de la Google). | Bara cere un script în pagină; fonturile Google ar trimite adresa IP a cititorului la Google, iar restul site-ului își servește fonturile de pe el. |
| VC-MP-14 | Limita de trimitere (cea importată din MailPoet) | E o „găleată cu jetoane”, nu fereastra glisantă a lui MailPoet; emailurile de confirmare nu așteaptă niciodată. | Un singur buget pentru campanii, automatizări și confirmări. |
| VC-MP-15 | Reabonarea cuiva dezabonat, inactiv sau sărit (din formular sau din bifa din checkout) | Rămâne în starea lui până dă clic pe linkul de confirmare (MailPoet: „neconfirmat” până atunci). Niciuna dintre stări nu primește emailuri. | Codul comun păstrează starea mai strictă până la consimțământ. |
| VC-MP-16 | Un link vechi de confirmare | Nu mai reabonează pe cineva care s-a dezabonat sau a devenit inactiv între timp: arată pagina de confirmare, fără nicio schimbare. O abonare nouă sau pagina de administrare reabonează. | Consimțământul trebuie să fie proaspăt. |
| VC-MP-17 | Un GET pe un link cu `request_method=POST` | Arată pagina de confirmare a dezabonării, nu dezabonează. | Un program care pre-încarcă linkurile nu trebuie să dezaboneze pe nimeni. |
| VC-MP-18 | Sondajul de după dezabonare și oprirea urmăririi, trimise de pe alt site sau cu un nonce expirat | O pagină simplă 403 („Verificarea a eșuat…”), nu pagina de eroare a WordPress. | Formularele au un nonce al site-ului și verificarea originii. |
| VC-MP-19 | „Vezi în browser” pentru emailurile automatizărilor | 404 până la importul automatizărilor (pista J); linkurile din ele (dezabonare, administrare, clicuri) merg de acum. | Arhiva emailurilor automatizărilor e a pistei J. |
| VC-MP-20 | Adresele publice de partajare (`/mailpoet-email/<hash>-<slug>/`) | Încă nu sunt servite (pagina 404). | Ruta lor (mereu deschisă) și cheia `?mailpoet_public_email=` sunt de înregistrat de pista A; randarea e a pistei E, după ele. Importul păstrează deja identificatorul fiecărei campanii partajabile (din exportul MailPoet). |
| VC-MP-21 | Formularele de abonare din subsol și de pe paginile de atelier | Aceeași validare și aceleași stări ca pe live; o adresă deja abonată (sau una care a primit deja emailurile de confirmare îngăduite) primește același mesaj de succes ca una nouă; cu pluginul newsletter oprit, formularul arată textul Divi al eșecului. | Pagina nu spune cine e abonat; cu pluginul oprit nu se fac abonări noi. |
| VC-MP-22 | Un abonat inactiv care deschide sau dă clic într-un email vechi | Redevine abonat pe loc (MailPoet: la rularea zilnică a regulii inactivității, după ce vede implicarea); data ultimei abonări rămâne aceeași, ca la MailPoet. Un clic pe un link al abonamentului (dezabonarea, administrarea, oprirea urmăririi) se notează, dar nu-l reactivează. | Regula inactivității nu rulează încă pe site-ul mutat; fără asta, un abonat inactiv n-ar mai reveni niciodată. Cine deschide pagina de dezabonare nu arată că vrea emailuri. |
| VC-MP-23 | Paginile abonamentului (administrarea, dezabonarea, confirmarea, oprirea urmăririi, „nu avem o înregistrare despre tine”) | Nu încarcă trackerele site-ului (Google Analytics, Tag Manager, Clarity), nici după acordul pentru cookie-uri. | Adresa acestor pagini poartă adresa de email a abonatului și jetonul lui, care nu expiră și dezabonează sau deschide administrarea: trackerele le-ar păstra în rapoarte (azi le primesc). Corectură de confidențialitate. |
| VC-MP-24 | Linkul de confirmare a abonării, cerut cu HEAD (scanerele de linkuri ale filtrelor de email) | Nu confirmă nimic: răspunde 200 gol. Un GET (omul care dă clic) confirmă, ca azi. De hotărât de proprietar: dacă linkurile de confirmare trimise de site, care reabonează o adresă dezabonată, inactivă sau sărită, cer și un buton (un POST), ca linkul de confirmare propriu al CMS-ului. | Un scaner care deschide linkul nu dă acordul în locul omului; GET-ul rămâne ca la MailPoet, fiindcă emailurile vechi au doar linkul. |
| VC-MP-25 | Paginile MailPoet (administrarea abonamentului și pagina simplă a abonării) | Sub titlu rămâne doar data; numele autorului și legătura lui spre pagina de autor dispar. | Autorul acestor pagini e contul care a instalat MailPoet, iar numele lui afișat e adesea adresa lui de email; paginile sunt deschise de oricine vine dintr-un email. |
| VC-MP-26 | Emailul „Abonat nou la …” către administrator | Cel mult unul pe zi pentru același abonat și aceeași listă; după 60 de liste anunțate într-o oră, pe tot site-ul, nu mai pleacă niciunul până se eliberează ora; nu pleacă nici când bugetul comun al trimiterilor e gol (MailPoet: la fiecare abonare). | Oricine poate retrimite formularul de abonare cu adresa unui abonat activ: fără limită, ar umple căsuța administratorului și ar consuma bugetul emailurilor comenzilor și confirmărilor. |
