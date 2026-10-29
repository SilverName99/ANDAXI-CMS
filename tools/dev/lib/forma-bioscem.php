<?php

declare(strict_types=1);

/**
 * Baza de dezvoltare „în forma Bioscem”, pe durata unei comparații cu codul Bioscem (tools/dev/compara-cu-baza.php și
 * G-vizual, tools/dev/capturi-publice.php): ce are baza Bioscem după adoptare și nu are baza demo. Ambele servere
 * (codul Bioscem și codul curent cu pachetul site-uri/bioscem/) citesc aceeași bază, deci paginile trebuie să iasă la fel.
 *
 *  1. **Setările înghețate ale Bioscem** (`site-uri/bioscem/setari-inghetate.json`, docs/MIGRARI.md, „Înghețarea
 *     setărilor”), toate, scrise peste ce e în bază (ca pasul înghețării pe o bază Bioscem în care codul vechi a scris
 *     implicitele lui la instalare): codul Bioscem le are scrise în cod sau în implicitele lui, codul curent le ia din
 *     bază. Și valorile private (adresele, telefonul și numele de pe acordul GDPR; OD-49), din
 *     `site-uri/bioscem/privat/setari-inghetate.json`, pe care îl scrie local `tools/inghetare/genereaza-setari.php`
 *     (din clona Bioscem, ignorat de git): fără ele pagina /acorduri-gdpr n-ar putea ieși octet cu octet. Nimic nu
 *     pleacă din dezvoltare (mail() scrie în fișiere, SMTP-ul e gol, cererile spre afară merg în gaura neagră).
 *  2. **Un antet ca al Bioscem** (`design_header_html`), cu {{menu}}, {{cart_count}} și {{mobile_menu}}: Bioscem are
 *     antetul lui în bază (docs/templates/header.html în codul Bioscem), deci antetul de rezervă al nucleului (fără antet
 *     în bază, cu numele site-ului; WP-16, A2) nu se vede pe Bioscem. Baza demo n-are antet, iar fără acesta fiecare
 *     pagină ar arăta antetul de rezervă, scris altfel de cele două coduri. Antetul de rezervă îl verifică
 *     tools/dev/test-pachet-site.php neutru.
 *
 * WP-14 face o bază separată, cms_dev_bioscem, construită așa de la instalare; până atunci, aceeași stare se pune aici
 * la începutul comparației și se scoate la final (și la o oprire), fiecare setare cum era (și o cheie lipsă).
 */

use App\Support\Migrari\Inghetare;

/** Antetul ca al Bioscem (cu sertarul de meniu mobil, ca antetul Bioscem: docs/templates/header.html:74 în codul lui). */
const FORMA_BIOSCEM_ANTET = '<header class="antet-demo-bioscem"><a href="/" class="antet-demo-bioscem__logo">Firma Demo</a>'
    . '<nav class="antet-demo-bioscem__meniu">{{menu}}</nav><a href="/cos" class="antet-demo-bioscem__cos">Coș ({{cart_count}})</a>'
    . '{{mobile_menu}}</header>';

/**
 * Setările de pus: cheie → valoare (înghețarea întreagă, cu valorile private, plus antetul).
 *
 * @return array<string, string>
 * @throws RuntimeException fișierul privat lipsește sau e al altei versiuni (mesajul spune comanda)
 */
function forma_bioscem_setari(string $radacina): array
{
    $fisier = $radacina . '/site-uri/bioscem/setari-inghetate.json';
    $inghetare = Inghetare::dinFisier($fisier);
    try {
        $private = $inghetare->valoriPrivate('site/bioscem/setari-inghetate');
    } catch (Throwable $e) {
        throw new RuntimeException($e->getMessage() . '. Scrie-l din clona Bioscem: tools/dev/php.sh tools/inghetare/genereaza-setari.php --site=bioscem');
    }
    $setari = [];
    foreach ($inghetare->setari() as $cheie => $intrare) {
        $setari[(string) $cheie] = $intrare['privat'] ? $private[$cheie] : (string) $intrare['valoare'];
    }
    $setari['design_header_html'] = FORMA_BIOSCEM_ANTET;
    return $setari;
}

/**
 * Pune forma Bioscem în bază; întoarce ce trebuie pus la loc (cheie → valoarea de dinainte, false = lipsea).
 *
 * @return array<string, string|false>
 */
function forma_bioscem_pune(PDO $db, string $radacina): array
{
    $deRestaurat = [];
    $citeste = $db->prepare('SELECT `value` FROM settings WHERE `key` = :k LIMIT 1');
    $scrie = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach (forma_bioscem_setari($radacina) as $cheie => $valoare) {
        $citeste->execute(['k' => $cheie]);
        $deRestaurat[$cheie] = $citeste->fetchColumn();
        $scrie->execute(['k' => $cheie, 'v' => $valoare]);
    }
    return $deRestaurat;
}

/** Pune la loc setările de dinainte (o cheie care lipsea se șterge). */
function forma_bioscem_restaureaza(PDO $db, array $deRestaurat): void
{
    $scrie = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $sterge = $db->prepare('DELETE FROM settings WHERE `key` = :k');
    foreach ($deRestaurat as $cheie => $valoare) {
        if ($valoare === false) {
            $sterge->execute(['k' => $cheie]);
        } else {
            $scrie->execute(['k' => $cheie, 'v' => (string) $valoare]);
        }
    }
}
