<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Html5\Editare;
use App\Support\Html5\Token;
use App\Support\Html5\Tokenizer;
use PDO;
use Throwable;

/**
 * Serverul formularelor de contact Divi ale unui site capturat (adoptarea WordPress, pasul 9b; docs/DEPLOY.md, „Formularele de
 * contact ale site-ului capturat”). Divi trimite formularul prin POST la adresa paginii (`action` = calea cererii), cu
 * `et_pb_contactform_submit_N` (N = numărul formularului în pagină, `et_pb_contact_form_N`), câmpurile `et_pb_contact_<id>_N`,
 * nonce-ul `_wpnonce-et-pb-contact-form-submitted-N` și capcana `ax_capcana` (pusă de import, Divi4Taiere::CAPCANA).
 *
 * public/index.php îl cheamă pentru orice POST, după mentenanță și înaintea cache-ului de pagini (un POST nu intră niciodată în
 * cache). Răspunde doar pe cadrul capturat, doar cu `et_pb_contactform_submit_N`, doar pe o cale care dă un document capturat
 * (o pagină, un card embed sau o arhivă; altfel 404-ul capturat, ca WordPress, care randa formularul din subsolul lui 404) și
 * doar pentru un formular care e în `formulare_compat` (configurația capturată la import: câmpurile din DOM, destinatarul și
 * mesajele din baza WordPress). Altfel nu face nimic (cererea merge mai departe, ca înainte).
 *
 * Ordinea, fiecare pas fail-closed:
 *  1. mărimea: corpul cel mult MARIME_CERERE octeți și cel mult MAX_CAMPURI câmpuri (413);
 *  2. originea: `Sec-Fetch-Site`, altfel `Origin`, ale site-ului însuși (GardaOrigine::aceeasiOrigine; 403);
 *  3. bugetul vizitatorului (LimitaCereri, pe rețeaua lui, IPv6 /64 ca una: INCERCARI_15_MIN încercări în 15 minute, INCERCARI_ZI pe
 *     zi; 429). E singurul refuz din cauza numărului: plafoanele site-ului opresc doar emailurile (pasul 10), niciodată mesajul;
 *  4. capcana: `ax_capcana` (sau `et_pb_contactform_validate_N` al Divi) completat = un robot: refuzat, nimic scris;
 *  5. nonce-ul: CadruCapturat::nonceFormular pe calea și numărul formularului, al zilei de azi sau de ieri (hash_equals);
 *  6. câmpurile, DOAR după configurația capturată (definițiile trimise de browser, `et_pb_contact_email_fields_N`, și câmpurile
 *     necunoscute se ignoră): obligatoriu, emailul valid, text UTF-8 valid, lungimea (un rând: LUNGIME_RAND, un text: LUNGIME_TEXT;
 *     numele, emailul și telefonul în coloanele lor, COLOANE); rândurile câmpurilor de un rând fără caractere de control (nicio
 *     linie nouă în antete). Captcha-ul
 *     Divi (o adunare, verificată doar de scriptul Divi; serverul WordPress cerea doar să nu fie gol) nu se verifică: apărarea sunt
 *     nonce-ul, capcana și bugetul;
 *  7. același mesaj de la aceeași adresă în ultimele 10 minute (o pagină reîncărcată după trimitere): succes, nescris de două ori;
 *  8. mesajul se SCRIE în `contact_form_messages` (aceleași mesaje din Admin → Emailuri → Formulare de contact), ÎNAINTE de email;
 *  9. plafoanele emailurilor: cel mult EMAILURI_IP_ZI emailuri pe zi de pe aceeași rețea, EMAILURI_ORA pe oră și EMAILURI_ZI pe zi pe
 *     tot site-ul (numărate în `register_submit_logs`, starea `l:formular_wp_email`). Peste oricare, mesajul rămâne în admin cu
 *     starea `retinut` (marcat, cu numărul celor din ultima zi deasupra listei), fără email, iar vizitatorul vede succesul: zece
 *     rețele nu pot umple căsuța proprietarului și nici nu pot opri mesajele altora;
 *  10. emailul, prin OrderMailer (setările Admin → Emailuri: SMTP și expeditorul), cu Reply-To adresa vizitatorului; unul care nu
 *     pleacă lasă mesajul în admin cu starea `netrimis`. Vizitatorul vede succesul: mesajul nu s-a pierdut.
 *
 * Răspunsul, fără JavaScript (forma de azi a paginilor, fără scripturile Divi): pagina întreagă, cu mesajul în
 * `.et-pb-contact-message` al formularului și, la succes, fără formular, ca Divi; la o eroare, formularul rămâne, cu valorile
 * trimise (escapate); cu o adresă de după succes a formularului (Divi „redirect”), 303 spre ea, doar pe același site
 * (redirectPermis). Cu antetul `X-Ax-Formular: 1` (trimiterea din runtime-ul compat, prin fetch), JSON: `{"stare": "trimis" |
 * "eroare", "mesaje": [...], "campuri": [...], "redirect"?: "..."}`, ca runtime-ul să schimbe doar formularul (fără HTML
 * construit din text în browser). Textele Divi (succesul, erorile) urmează limba documentului capturat (`<html lang>`), ca
 * WordPress; mesajul de succes al formularului vine din baza WordPress.
 */
final class FormularCapturat
{
    public const MARIME_CERERE = 65536;
    public const MAX_CAMPURI = 60;
    public const LUNGIME_RAND = 500;
    public const LUNGIME_TEXT = 10000;
    public const INCERCARI_15_MIN = 20;
    public const INCERCARI_ZI = 60;
    public const EMAILURI_IP_ZI = 5;
    public const EMAILURI_ORA = 60;
    public const EMAILURI_ZI = 300;

    /** Coloanele din `contact_form_messages` cu lungimea lor (caractere): un câmp mai lung e o eroare, nu un mesaj tăiat sau pierdut. */
    public const COLOANE = ['nume' => 190, 'email' => 190, 'telefon' => 60];

    /** Scopul LimitaCereri al emailurilor trimise (plafoanele vizitatorului și ale site-ului). */
    public const SCOP_EMAIL = 'formular_wp_email';

    /** Antetul trimiterii din runtime-ul compat (fetch): răspunsul e JSON. */
    public const ANTET_JSON = 'X-Ax-Formular';

    /** Câmpul-capcană pus de import în fiecare formular (Divi4Taiere::CAPCANA). */
    public const CAPCANA = 'ax_capcana';

    /** Starea mesajului scris în `contact_form_messages` când emailul n-a plecat. */
    public const STARE_NETRIMIS = 'netrimis';

    /** Starea mesajului scris fără email, fiindcă un plafon al emailurilor era atins (EMAILURI_IP_ZI, EMAILURI_ORA, EMAILURI_ZI). */
    public const STARE_RETINUT = 'retinut';

    /** Textele Divi (et_builder), în limba site-ului WordPress (`<html lang>` al capturii). */
    private const DIVI = [
        'en' => ['obligatorii' => 'Make sure you fill in all required fields.', 'email' => 'Invalid Email.', 'reincarca' => 'Please refresh the page and try again.',
            'succes' => 'Thanks for contacting us', 'subiect' => 'New Message From %1$s%2$s'],
        'ro' => ['obligatorii' => 'Asigură-te că ai completat toate câmpurile obligatorii.', 'email' => 'Adresă de email nevalidă.',
            'reincarca' => 'Te rugăm să reîncarci pagina și să încerci din nou.', 'succes' => 'Îți mulțumim că ne-ai contactat', 'subiect' => 'Mesaj nou de la %1$s%2$s'],
    ];

    /** Textele noastre (refuzuri care nu existau pe site-ul WordPress), în română (ce-se-schimba.md). */
    private const NOI = [
        'prea_lung' => 'Un câmp e prea lung. Scurtează textul și trimite din nou.',
        'prea_mare' => 'Mesajul e prea mare. Scurtează textul și trimite din nou.',
        'prea_multe' => 'Ai trimis prea multe mesaje într-un timp scurt. Încearcă din nou mai târziu.',
    ];

    /**
     * Pentru public/index.php: o trimitere a unui formular capturat primește aici răspunsul (true); orice altă cerere merge mai
     * departe (false).
     */
    public static function intercepteaza(?PDO $db, string $metoda, string $uri): bool
    {
        if (strtoupper($metoda) !== 'POST' || !$db instanceof PDO || Tema::cadru() !== 'capturat') {
            return false;
        }
        $n = self::numar($_POST);
        if ($n === null) {
            return false;
        }
        // N6 (docs/SECURITATE.md, „Adresele din care parse_url nu scoate o cale”): o adresă din care parse_url nu scoate o cale
        // („/x:1/”, „//x”) nu e formularul primei pagini; merge mai departe, la 404-ul routerului, ca orice adresă necunoscută.
        $cale = \App\Http\Router::caleCerere($uri);
        if ($cale === null) {
            return false;
        }
        $cale = $cale === '' ? '/' : $cale;
        if (GardaOrigine::caleAdmin($cale) || preg_match('#^/(api|_andaxi|webhook|checkout|cont|contul-meu)(/|$)#i', rawurldecode($cale)) === 1) {
            return false;
        }
        try {
            $gasit = self::documentPentru($db, $cale);
            $formular = $gasit !== null ? self::formular($db, $gasit['doc'], $n) : null;
        } catch (Throwable $e) {
            error_log('ANDAXI-CMS: formularul capturat: documentul căii nu se poate citi (' . get_class($e) . ': ' . $e->getMessage() . ')');
            return false;
        }
        if ($gasit === null || $formular === null) {
            return false;
        }
        $limba = self::limba((string) ($gasit['doc']['html_atribute'] ?? ''));
        $rez = self::proceseaza($db, $formular, $n, $cale, $_POST, $_SERVER, $limba, (string) ($gasit['doc']['cheie'] ?? ''));
        if (self::cuJson($_SERVER)) {
            self::raspundeJson($rez);
            return true;
        }
        if ($rez['stare'] === 'trimis' && $rez['redirect'] !== '') {
            // Divi „redirect” după succes, și fără JavaScript: 303, doar spre același site (redirectPermis), fără cache.
            http_response_code(303);
            header('Location: ' . $rez['redirect']);
            header('Cache-Control: no-store, private');
            header('Content-Type: text/html; charset=UTF-8');
            echo self::paginaSimpla($rez);
            return true;
        }
        $status = $rez['cod'] !== 200 ? $rez['cod'] : $gasit['status'];
        CadruCapturat::raspunde($db, $gasit['doc'], ['cale' => $cale, 'nonce' => CadruCapturat::nonceZi(), 'an' => date('Y'),
            'seo' => CadruCapturat::seoPagina($db, $gasit['pagina'])], $status, static function (string $html) use ($n, $rez): string {
                try {
                    return self::aplica($html, $n, $rez);
                } catch (Throwable $e) {
                    error_log('ANDAXI-CMS: formularul capturat: mesajul nu se poate pune în pagină (' . $e->getMessage() . '); răspund cu o pagină simplă');
                    return self::paginaSimpla($rez);
                }
            }, true);
        return true;
    }

    /** Numărul formularului trimis (`et_pb_contactform_submit_N` cu valoarea Divi), sau null; două trimiteri deodată: null. */
    public static function numar(array $post): ?int
    {
        $gasite = [];
        foreach ($post as $k => $v) {
            if (is_string($k) && preg_match('/^et_pb_contactform_submit_(\d{1,3})$/D', $k, $m) === 1 && is_string($v)) {
                $gasite[] = (int) $m[1];
            }
        }
        return count($gasite) === 1 ? $gasite[0] : null;
    }

    /**
     * Documentul capturat servit la calea dată, cum îl găsesc rutele: prima pagină (`/`), o pagină după slug (doar literele mari
     * ASCII pot diferi, WpCompat::acelasiSlug), un card embed sau o arhivă după cale, altfel 404-ul capturat (cu statusul 404).
     *
     * @return array{doc: array<string, mixed>, status: int, pagina: int}|null
     */
    public static function documentPentru(PDO $db, string $cale): ?array
    {
        $slug = trim(rawurldecode($cale), '/');
        if ($slug === '') {
            $q = $db->query("SELECT id, slug FROM pages WHERE (slug = '' OR LOWER(slug) IN ('acasa', 'home')) AND is_published = 1 AND deleted_at IS NULL
                ORDER BY CASE WHEN slug = '' THEN 0 WHEN LOWER(slug) = 'acasa' THEN 1 ELSE 2 END, id DESC LIMIT 1");
            $pagina = $q !== false ? $q->fetch(PDO::FETCH_ASSOC) : false;
        } else {
            $q = $db->prepare('SELECT id, slug FROM pages WHERE slug = ? AND is_published = 1 AND deleted_at IS NULL LIMIT 1');
            $q->execute([$slug]);
            $pagina = $q->fetch(PDO::FETCH_ASSOC);
            if (is_array($pagina) && ((WpCompat::activ() && !WpCompat::acelasiSlug($slug, (string) $pagina['slug'])) || trim((string) $pagina['slug'], '/') === '404')) {
                $pagina = false;
            }
        }
        if (is_array($pagina) && ($doc = CadruCapturat::document($db, pagina: (int) $pagina['id'])) !== null) {
            return ['doc' => $doc, 'status' => 200, 'pagina' => (int) $pagina['id']];
        }
        if (($doc = CadruCapturat::document($db, cale: $cale)) !== null) {
            return ['doc' => $doc, 'status' => 200, 'pagina' => 0];
        }
        $doc = CadruCapturat::document($db, cheie: '404');
        return $doc !== null ? ['doc' => $doc, 'status' => 404, 'pagina' => 0] : null;
    }

    /**
     * Configurația formularului N din document: unul din conținut (N mai mic decât numărul formularelor din conținut:
     * `doc:<cheia documentului>:<N>`), altfel cel din subsolul documentului (numărul lui e câte formulare are conținutul).
     *
     * @param array<string, mixed> $doc
     * @return array{cheie: string, config: array<string, mixed>}|null
     */
    public static function formular(PDO $db, array $doc, int $n): ?array
    {
        $continut = (string) ($doc['continut'] ?? '');
        if (($doc['tip'] ?? '') === 'pagina') {
            $q = $db->prepare('SELECT html_content FROM pages WHERE id = ?');
            $q->execute([(int) $doc['page_id']]);
            $continut = (string) $q->fetchColumn();
        }
        $inContinut = CadruCapturat::formulareInContinut($continut);
        if ($n < $inContinut) {
            $q = $db->prepare('SELECT cheie, config FROM formulare_compat WHERE cheie = ?');
            $q->execute(['doc:' . (string) $doc['cheie'] . ':' . $n]);
        } elseif ($n === $inContinut && (string) ($doc['subsol'] ?? '') !== '') {
            $q = $db->prepare('SELECT cheie, config FROM formulare_compat WHERE fragment = ? ORDER BY cheie LIMIT 1');
            $q->execute([(string) $doc['subsol']]);
        } else {
            return null;
        }
        $r = $q->fetch(PDO::FETCH_ASSOC);
        $config = is_array($r) ? json_decode((string) $r['config'], true) : null;
        if (!is_array($config) || !is_array($config['campuri'] ?? null) || $config['campuri'] === []) {
            return null;
        }
        return ['cheie' => (string) $r['cheie'], 'config' => $config];
    }

    /** Limba textelor Divi, din atributele `<html>` ale documentului capturat: `ro` sau `en` (implicit, sursa textelor Divi). */
    public static function limba(string $htmlAtribute): string
    {
        return preg_match('/\blang=["\']?ro\b/i', $htmlAtribute) === 1 ? 'ro' : 'en';
    }

    /**
     * Trimiterea, de la mărime până la email (pașii din capul clasei). Rezultatul: `stare` (`trimis`, `eroare`), `mesaje`
     * (textele de arătat), `campuri` (id-urile câmpurilor greșite), `valori` (numele câmpului → valoarea trimisă, pentru
     * formularul arătat din nou), `cod` (statusul HTTP), `redirect` (adresa de după succes din Divi, doar pe același site).
     *
     * @param array{cheie: string, config: array<string, mixed>} $formular
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @return array{stare: string, mesaje: list<string>, campuri: list<string>, valori: array<string, string>, cod: int, redirect: string}
     */
    public static function proceseaza(PDO $db, array $formular, int $n, string $cale, array $post, array $server, string $limba = 'en', string $document = ''): array
    {
        $t = self::DIVI[$limba] ?? self::DIVI['en'];
        $c = $formular['config'];
        $eroare = static fn (string $mesaj, int $cod = 200, array $campuri = [], array $valori = []): array => ['stare' => 'eroare', 'mesaje' => [$mesaj],
            'campuri' => $campuri, 'valori' => $valori, 'cod' => $cod, 'redirect' => ''];
        $marime = (int) ($server['CONTENT_LENGTH'] ?? 0);
        if ($marime > self::MARIME_CERERE || count($post, COUNT_RECURSIVE) > self::MAX_CAMPURI) {
            return $eroare(self::NOI['prea_mare'], 413);
        }
        if (GardaOrigine::aceeasiOrigine($server, (string) Env::get('APP_URL', '')) !== null) {
            return $eroare($t['reincarca'], 403);
        }
        if (!LimitaCereri::incearca($db, 'formular_wp', self::INCERCARI_15_MIN, 15) || !LimitaCereri::incearca($db, 'formular_wp_zi', self::INCERCARI_ZI, 1440)) {
            return $eroare(self::NOI['prea_multe'], 429);
        }
        $capcana = $post[self::CAPCANA] ?? '';
        $validate = $post['et_pb_contactform_validate_' . $n] ?? '';
        if (!is_string($capcana) || trim($capcana) !== '' || !is_string($validate) || trim($validate) !== '') {
            error_log('ANDAXI-CMS: formularul capturat ' . $formular['cheie'] . ': trimitere refuzată (capcana completată)');
            return $eroare($t['reincarca']);
        }
        $nonce = $post['_wpnonce-et-pb-contact-form-submitted-' . $n] ?? '';
        $bun = false;
        foreach ([date('Y-m-d'), date('Y-m-d', time() - 86400)] as $zi) {
            $asteptat = CadruCapturat::nonceFormular($cale, $n, $zi);
            $bun = $bun || ($asteptat !== '' && is_string($nonce) && hash_equals($asteptat, $nonce));
        }
        if (!$bun) {
            return $eroare($t['reincarca']);
        }

        // Câmpurile, după configurația capturată.
        $valori = [];
        $campuri = [];
        $mesaje = [];
        $gresite = [];
        foreach ((array) $c['campuri'] as $camp) {
            if (!is_array($camp) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', (string) ($camp['id'] ?? '')) !== 1) {
                continue;
            }
            $id = (string) $camp['id'];
            $nume = 'et_pb_contact_' . $id . '_' . $n;
            $tip = (string) ($camp['tip'] ?? 'input');
            $fel = (string) ($camp['fel'] ?? $tip);
            $text = $tip === 'text' || $fel === 'text';
            $v = $post[$nume] ?? '';
            if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
                $mesaje[] = $t['obligatorii'];
                $gresite[] = $id;
                continue;
            }
            $v = $text ? self::curataText($v) : self::curataRand($v);
            $valori[$nume] = $v;
            $limita = $text ? self::LUNGIME_TEXT : self::LUNGIME_RAND;
            if (mb_strlen($v) > $limita || mb_strlen($v) > (self::COLOANE[self::rol($camp)] ?? PHP_INT_MAX)) {
                $mesaje[] = self::NOI['prea_lung'];
                $gresite[] = $id;
                continue;
            }
            if (!empty($camp['obligatoriu']) && $v === '') {
                $mesaje[] = $t['obligatorii'];
                $gresite[] = $id;
                continue;
            }
            if (($fel === 'email' || $tip === 'email') && $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                $mesaje[] = $t['email'];
                $gresite[] = $id;
                continue;
            }
            $campuri[] = ['id' => $id, 'eticheta' => self::curataRand((string) ($camp['eticheta'] ?? $id)), 'valoare' => $v, 'rol' => self::rol($camp), 'text' => $text];
        }
        if ($mesaje !== []) {
            return ['stare' => 'eroare', 'mesaje' => array_values(array_unique($mesaje)), 'campuri' => array_values(array_unique($gresite)), 'valori' => $valori,
                'cod' => 200, 'redirect' => ''];
        }

        $setari = Settings::all($db);
        $titlu = self::curataRand(html_entity_decode(strip_tags((string) ($c['titlu'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $subiect = self::curataRand(sprintf($t['subiect'], Brand::numeSite($setari), $titlu !== '' ? ' - ' . $titlu : ''));
        $nume = '';
        $email = '';
        $telefon = '';
        $corp = '';
        foreach ($campuri as $x) {
            if ($x['rol'] === 'nume' && $nume === '') {
                $nume = $x['valoare'];
            } elseif ($x['rol'] === 'email' && $email === '') {
                $email = $x['valoare'];
            } elseif ($x['rol'] === 'telefon' && $telefon === '') {
                $telefon = $x['valoare'];
            }
        }
        foreach ($campuri as $x) {
            if ($x['rol'] === 'mesaj') {
                $corp .= ($corp !== '' ? "\n\n" : '') . $x['valoare'];
            }
        }
        foreach ($campuri as $x) {
            if (!in_array($x['rol'], ['nume', 'email', 'mesaj'], true)) {
                $corp .= ($corp !== '' ? "\n" : '') . ($x['eticheta'] !== '' ? $x['eticheta'] : $x['id']) . ': ' . $x['valoare'];
            }
        }
        $succes = trim(html_entity_decode(strip_tags((string) ($c['mesaj_succes'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $ok = ['stare' => 'trimis', 'mesaje' => [$succes !== '' ? $succes : $t['succes']], 'campuri' => [], 'valori' => [], 'cod' => 200,
            'redirect' => self::redirectPermis((string) ($c['redirect'] ?? ''))];
        $ip = mb_substr(IpClient::adresa(), 0, 64);
        try {
            $q = $db->prepare('SELECT id FROM contact_form_messages WHERE email = ? AND message = ? AND ip_address <=> ? AND created_at >= (NOW() - INTERVAL 10 MINUTE) LIMIT 1');
            $q->execute([$email, $corp, $ip !== '' ? $ip : null]);
            if ($q->fetchColumn() !== false) {
                return $ok; // aceeași trimitere, din nou (o pagină reîncărcată): scrisă deja
            }
            $db->prepare('INSERT INTO contact_form_messages (name, email, phone, subject, message, status, source_url, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, \'new\', ?, ?, ?, NOW())')->execute([$nume, $email, $telefon !== '' ? $telefon : null, mb_substr($subiect, 0, 255), $corp,
                mb_substr($cale, 0, 500), $ip !== '' ? $ip : null, mb_substr(self::curataRand((string) ($server['HTTP_USER_AGENT'] ?? '')), 0, 255) ?: null]);
            $id = (int) $db->lastInsertId();
        } catch (Throwable $e) {
            // Fără mesajul scris nu trimitem nimic și nu spunem „trimis”: vizitatorul încearcă din nou, nimic nu se pierde în tăcere.
            error_log('ANDAXI-CMS: formularul capturat ' . $formular['cheie'] . ': mesajul nu se poate scrie (' . get_class($e) . ': ' . $e->getMessage() . ')');
            return $eroare($t['reincarca'], 500);
        }
        $trimise = 0;
        $plafon = self::plafonEmail($db);
        if ($plafon === null) {
            $html = self::corpEmail($campuri, $c, $cale, $document);
            foreach (self::destinatari($db, $setari, $c) as $destinatar) {
                try {
                    OrderMailer::sendCustom($destinatar, $subiect, $html, $setari, $db, ['email_type' => 'contact_form_notification', 'source' => 'formular_capturat',
                        'trigger' => 'formular_capturat_submit', 'from_email' => $email, 'from_name' => $nume]);
                    $trimise++;
                } catch (Throwable $e) {
                    error_log('ANDAXI-CMS: formularul capturat ' . $formular['cheie'] . ': emailul mesajului ' . $id . ' n-a plecat (' . $e->getMessage() . ')');
                }
            }
        } else {
            error_log('ANDAXI-CMS: formularul capturat: ' . $plafon . '; mesajul ' . $id . ' rămâne doar în admin, fără email');
        }
        if ($trimise === 0) {
            try {
                $db->prepare('UPDATE contact_form_messages SET status = ? WHERE id = ?')->execute([$plafon !== null ? self::STARE_RETINUT : self::STARE_NETRIMIS, $id]);
            } catch (Throwable) {
            }
        }
        return $ok;
    }

    /**
     * Un plafon al emailurilor atins (motivul), sau null: emailul poate pleca, iar plecarea lui se numără (pe rețeaua vizitatorului
     * și pe site). Întâi plafoanele site-ului (fără să scrie nimic), apoi al vizitatorului (LimitaCereri, care numără).
     */
    public static function plafonEmail(PDO $db): ?string
    {
        try {
            $stare = LimitaCereri::PREFIX . self::SCOP_EMAIL;
            foreach ([[60, self::EMAILURI_ORA, 'pe oră'], [1440, self::EMAILURI_ZI, 'pe zi']] as [$minute, $maxim, $cand]) {
                $q = $db->prepare('SELECT COUNT(*) FROM register_submit_logs WHERE status = ? AND created_at >= (NOW() - INTERVAL ' . $minute . ' MINUTE)');
                $q->execute([$stare]);
                if ((int) $q->fetchColumn() >= $maxim) {
                    return 'plafonul site-ului de ' . $maxim . ' emailuri ' . $cand . ' e atins';
                }
            }
        } catch (Throwable) {
            return null; // fără tabel, fără plafon (LimitaCereri face la fel): mesajul e oricum scris
        }
        return LimitaCereri::incearca($db, self::SCOP_EMAIL, self::EMAILURI_IP_ZI, 1440) ? null
            : 'plafonul de ' . self::EMAILURI_IP_ZI . ' emailuri pe zi de pe aceeași rețea e atins';
    }

    /**
     * Destinatarii: adresele din Admin → Emailuri → destinatarii formularului de contact (`contact_form_recipients`, dacă
     * proprietarul le-a pus), altfel adresa formularului din baza WordPress (`email` al modulului Divi), altfel adresa de contact
     * a site-ului (Brand::destinatariContact). @return list<string>
     */
    public static function destinatari(?PDO $db, array $setari, array $config): array
    {
        $dinAdmin = Brand::adrese((string) ($setari['contact_form_recipients'] ?? ''));
        if ($dinAdmin !== []) {
            return $dinAdmin;
        }
        $dinWp = Brand::adrese((string) ($config['destinatar'] ?? ''));
        return $dinWp !== [] ? $dinWp : Brand::destinatariContact($db, $setari);
    }

    /**
     * Pagina randată cu rezultatul în formularul N: mesajele în `.et-pb-contact-message` (`<p>` la succes, `<p
     * class="et_pb_contact_error_text">` la erori, ca Divi), formularul scos la succes, valorile trimise puse la loc la o eroare.
     * Doar editări pe intervale de octeți (Html5\Editare); un formular care nu e în pagină e o eroare (RuntimeException).
     *
     * @param array{stare: string, mesaje: list<string>, valori: array<string, string>} $rez
     */
    public static function aplica(string $html, int $n, array $rez): string
    {
        $e = new Editare($html);
        $tk = $e->tokeni();
        $k = null;
        foreach ($tk as $i => $t) {
            if ($t->tip === Token::DESCHIDERE && $t->nume === 'div' && $t->valoare($html, 'id') === 'et_pb_contact_form_' . $n) {
                $k = $i;
                break;
            }
        }
        $p = $k !== null ? Tokenizer::pereche($tk, $k) : null;
        if ($k === null || $p === null) {
            throw new \RuntimeException('formularul et_pb_contact_form_' . $n . ' nu e în pagină');
        }
        $mesaj = null;
        $formular = null;
        for ($i = $k + 1; $i < $p; $i++) {
            $t = $tk[$i];
            if ($t->tip !== Token::DESCHIDERE) {
                continue;
            }
            $clase = $t->clase($html);
            if ($mesaj === null && $t->nume === 'div' && in_array('et-pb-contact-message', $clase, true)) {
                $mesaj = $i;
            } elseif ($formular === null && $t->nume === 'div' && in_array('et_pb_contact', $clase, true)) {
                $formular = $i;
            }
        }
        if ($mesaj === null) {
            throw new \RuntimeException('formularul et_pb_contact_form_' . $n . ' n-are .et-pb-contact-message');
        }
        $text = '';
        foreach ($rez['mesaje'] as $m) {
            $text .= $rez['stare'] === 'trimis' ? '<p>' . self::scapa($m) . '</p>' : '<p class="et_pb_contact_error_text">' . self::scapa($m) . '</p>';
        }
        $e->interior($mesaj, $text);
        if ($rez['stare'] === 'trimis') {
            if ($formular !== null) {
                $e->stergeNod($formular);
            }
            return $e->aplica();
        }
        for ($i = $k + 1; $i < $p; $i++) {
            $t = $tk[$i];
            if ($t->tip !== Token::DESCHIDERE || !in_array($t->nume, ['input', 'textarea'], true)) {
                continue;
            }
            $nume = (string) $t->valoare($html, 'name');
            if (!array_key_exists($nume, $rez['valori'])) {
                continue;
            }
            if ($t->nume === 'textarea') {
                $e->interior($i, self::scapa($rez['valori'][$nume]));
            } elseif (!in_array(strtolower((string) $t->valoare($html, 'type')), ['hidden', 'submit', 'checkbox', 'radio', 'file'], true)) {
                $e->valoareAtribut($i, 'value', self::scapa($rez['valori'][$nume]));
            }
        }
        return $e->aplica();
    }

    /** O cerere a runtime-ului compat (fetch), care vrea JSON? */
    public static function cuJson(array $server): bool
    {
        return trim((string) ($server['HTTP_X_AX_FORMULAR'] ?? '')) === '1';
    }

    /** @param array{stare: string, mesaje: list<string>, campuri: list<string>, cod: int, redirect: string} $rez */
    private static function raspundeJson(array $rez): void
    {
        http_response_code($rez['cod']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        $date = ['stare' => $rez['stare'], 'mesaje' => $rez['mesaje'], 'campuri' => $rez['campuri']];
        if ($rez['redirect'] !== '') {
            $date['redirect'] = $rez['redirect'];
        }
        echo json_encode($date, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** O pagină minimă, cu mesajul (escapat), când formularul nu se poate pune în pagina capturată (n-ar trebui să se întâmple). */
    private static function paginaSimpla(array $rez): string
    {
        $text = '';
        foreach ($rez['mesaje'] as $m) {
            $text .= '<p>' . self::scapa($m) . '</p>';
        }
        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex\"><title>Mesaj</title></head><body>" . $text
            . '<p><a href="/">&larr;</a></p></body></html>' . "\n";
    }

    /** Rolul unui câmp în rândul din `contact_form_messages`: `nume`, `email`, `telefon`, `mesaj` sau `alt`. */
    private static function rol(array $camp): string
    {
        $id = strtolower((string) ($camp['id'] ?? ''));
        $tip = (string) ($camp['tip'] ?? '');
        $fel = (string) ($camp['fel'] ?? $tip);
        if ($fel === 'email' || $tip === 'email') {
            return 'email';
        }
        if (in_array($id, ['name', 'nume', 'numele', 'nume_prenume', 'nume-prenume'], true)) {
            return 'nume';
        }
        if (in_array($id, ['message', 'mesaj', 'mesajul'], true) || $tip === 'text') {
            return 'mesaj';
        }
        if (in_array($id, ['phone', 'telefon', 'tel'], true)) {
            return 'telefon';
        }
        return 'alt';
    }

    /**
     * Corpul emailului (HTML, fiecare valoare escapată): câmpurile cu etichetele lor, sau tiparul Divi al formularului
     * (`custom_message`, cu `%%id%%` înlocuit de valoare), și pagina de pe care a venit.
     *
     * @param list<array{id: string, eticheta: string, valoare: string, text: bool}> $campuri
     */
    private static function corpEmail(array $campuri, array $c, string $cale, string $document): string
    {
        $tipar = trim(html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) ($c['tipar'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($tipar !== '') {
            $corp = self::scapa($tipar);
            foreach ($campuri as $x) {
                $corp = str_ireplace('%%' . $x['id'] . '%%', self::scapa($x['valoare']), $corp);
            }
            $corp = (string) preg_replace('/%%[A-Za-z0-9_-]{1,80}%%/', '', $corp);
            $corp = '<div>' . nl2br($corp) . '</div>';
        } else {
            $corp = '';
            foreach ($campuri as $x) {
                $corp .= '<p style="margin:0 0 8px;"><strong>' . self::scapa($x['eticheta'] !== '' ? $x['eticheta'] : $x['id']) . ':</strong> '
                    . ($x['text'] ? '<br>' . nl2br(self::scapa($x['valoare'])) : self::scapa($x['valoare'])) . '</p>';
            }
        }
        return '<h2 style="margin:0 0 12px;">Mesaj nou din formularul de pe site</h2>' . $corp
            . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:12px 0;"><p style="margin:0;color:#64748b;font-size:13px;">Pagina: '
            . self::scapa($cale) . '</p>';
    }

    /**
     * Adresa de după succes (opțiunea Divi „redirect”), doar spre același site: o cale relativă (`/…`, nu `//…`) sau o adresă cu
     * schema și gazda din APP_URL (sau perechea ei www), fără utilizator și parolă, doar ASCII vizibil (fără spații, `\`,
     * caractere de control). Altfel '' (fără redirecționare: mesajul de succes, ca fără opțiune).
     */
    public static function redirectPermis(string $url, ?string $appUrl = null): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[^\x21-\x7E]|\\\\/', $url) === 1) {
            return '';
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        $baza = parse_url($appUrl ?? (string) Env::get('APP_URL', ''));
        $gazda = strtolower((string) ($baza['host'] ?? ''));
        $schema = strtolower((string) ($baza['scheme'] ?? 'https'));
        $p = parse_url($url);
        $gazde = [$gazda, 'www.' . $gazda, (string) preg_replace('/^www\./', '', $gazda)];
        return is_array($p) && $gazda !== '' && strtolower((string) ($p['scheme'] ?? '')) === $schema && in_array(strtolower((string) ($p['host'] ?? '')), $gazde, true)
            && ($p['port'] ?? null) === ($baza['port'] ?? null) && !isset($p['user']) && !isset($p['pass']) ? $url : '';
    }

    /** Un câmp de un rând: fără caractere de control (nici linii noi), spațiile strânse la capete. */
    private static function curataRand(string $v): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $v));
    }

    /** Un câmp de text: liniile noi „\n”, fără alte caractere de control (tabul rămâne). */
    private static function curataText(string $v): string
    {
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $v));
    }

    private static function scapa(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
