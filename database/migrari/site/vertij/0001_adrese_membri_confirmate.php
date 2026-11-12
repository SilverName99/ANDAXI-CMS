<?php

declare(strict_types=1);

use App\Support\Migrari\Schema;

/**
 * Pasul site-ului vertij (aplicat la adoptare, cu `migreaza.php --aplica --site=vertij`; decizia proprietarului din 9 noiembrie
 * 2026): adresa membrilor care și-au confirmat-o pe codul vertij cu linkul cererii devine dovedită și pentru nucleu
 * (`users.email_confirmed_at`, WP-25). Fără pasul acesta, un membru vechi și-ar vedea comenzile și donațiile făcute fără cont cu
 * adresa lui abia după o nouă confirmare.
 *
 * Doar dovezile reale (revizuirea din 9 noiembrie): o cerere cu `confirmed_at` scris de linkul de confirmare. Codul vertij mai
 * scria `confirmed_at` și pe o „cerere nouă” făcută din cont (rândul se naște deja confirmat, fără niciun link: `confirmed_at`
 * = momentul creării), iar o cerere decisă de admin cât aștepta confirmarea rămâne cu `confirmed_at` gol; niciuna nu dovedește
 * adresa, deci contul lor rămâne neconfirmat (se poate confirma oricând din cont). Momentul creării se citește exact
 * (`UNIX_TIMESTAMP` al coloanei TIMESTAMP), iar `confirmed_at` (DATETIME: scris de PHP în ora României pe cererea nouă, de MySQL
 * în ora serverului pe link) se compară în ora României: un rând născut confirmat are cele două la cel mult
 * `SECUNDE_CERERE_NOUA` distanță; un om nu se înscrie, își deschide emailul și apasă linkul atât de repede, iar pe un server
 * MySQL în UTC diferența linkului e oricum de ore. Valoarea scrisă: `confirmed_at` al ultimei cereri confirmate cu linkul.
 * Idempotent: doar unde `email_confirmed_at` e încă NULL. Planul (`--plan`) spune câte conturi primesc adresa dovedită și câte
 * cereri confirmate sunt lăsate deoparte (avertismentul pasului).
 */
return [
    'descriere' => 'Membrii vertij cu adresa confirmată cu linkul cererii pe codul vechi: users.email_confirmed_at (o singură dată, doar unde lipsește)',
    'tip' => 'site',
    'blocanta' => false,
    'sus' => static function (Schema $s): void {
        if (!$s->existaTabel('account_verifications')) {
            return;
        }
        $secundeCerereNoua = 10;
        $fus = new DateTimeZone('Europe/Bucharest');
        // Fără coloana nucleului în interogare: în plan (`--plan`, pe baza încă nemigrată) `users.email_confirmed_at` nu există încă,
        // iar numărătoarea trebuie să se vadă dinainte; scrierea de mai jos o atinge doar unde e NULL.
        $randuri = $s->citeste('SELECT v.user_id, UNIX_TIMESTAMP(v.created_at) AS creata, v.confirmed_at
            FROM account_verifications v INNER JOIN users u ON u.id = v.user_id
            WHERE v.confirmed_at IS NOT NULL ORDER BY v.user_id, v.id');
        $dovezi = [];
        $deoparte = 0;
        foreach ($randuri as $rand) {
            $confirmata = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $rand['confirmed_at'], $fus);
            $creata = (int) $rand['creata'];
            if ($confirmata === false || $creata <= 0 || abs($confirmata->getTimestamp() - $creata) <= $secundeCerereNoua) {
                $deoparte++; // născută confirmată (cererea nouă din cont) sau un moment care nu se citește: nu e o dovadă
                continue;
            }
            $id = (int) $rand['user_id'];
            if (!isset($dovezi[$id]) || strcmp((string) $rand['confirmed_at'], $dovezi[$id]) > 0) {
                $dovezi[$id] = (string) $rand['confirmed_at'];
            }
        }
        $s->avertisment(count($dovezi) . ' conturi primesc adresa dovedită (users.email_confirmed_at, unde lipsește) din linkul cererii confirmat pe codul vertij; '
            . $deoparte . ' cereri cu confirmed_at lăsate deoparte (născute deja confirmate, fără link)');
        foreach ($dovezi as $id => $moment) {
            $s->date('Adresa confirmată cu linkul cererii pe codul vertij devine users.email_confirmed_at',
                'UPDATE users SET email_confirmed_at = :moment WHERE id = :id AND email_confirmed_at IS NULL', ['moment' => $moment, 'id' => $id]);
        }
    },
];
