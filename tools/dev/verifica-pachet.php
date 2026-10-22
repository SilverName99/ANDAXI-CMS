<?php

declare(strict_types=1);

/**
 * Verificarea pachetelor de date ale site-urilor (formatul: docs/PACHETE.md; WP-13, DB-01). Rulează în testul de fum
 * (tools/dev/smoke.sh, pasul 1) și deci în porțile G-smoke și G-php: un pachet greșit nu ajunge pe un server.
 *
 *   tools/dev/php.sh tools/dev/verifica-pachet.php                         # toate: site-uri/*, tools/dev/site-demo, tools/dev/site-demo-tema
 *   tools/dev/php.sh tools/dev/verifica-pachet.php site-uri/bioscem        # doar unele
 *   tools/dev/php.sh tools/dev/verifica-pachet.php --actualizeaza-manifest # după o schimbare într-un pachet: rescrie pachet.json
 *   tools/dev/php.sh tools/dev/verifica-pachet.php --autotest              # verificarea însăși: fiecare greșeală plantată e prinsă
 *
 * Ce verifică, în fiecare pachet:
 *  1. Fișierele, după locul lor (docs/PACHETE.md, „Ce poate conține un pachet”): în rădăcină doar .json și .md (unul
 *     necunoscut doar ATENȚIE); în tema/ doar tema.json și blocuri .css; în tema/public/ imagini (svg, png, jpg, jpeg,
 *     webp, gif, avif, ico), fonturi woff2, .css și doar scripturile declarate în tema.json → js_final; în public/
 *     (fișiere la o adresă fixă, copiate peste public/ la adoptare) doar imagini, niciuna peste un fișier al nucleului,
 *     în uploads/ sau în tema/; continut/ (GR-20) încă nu face parte din format; privat/ (ignorat de git) doar .json.
 *     Nicăieri PHP (.php, .phtml, .phar …), .htaccess, fișiere ascunse, legături simbolice sau fișiere peste 1 MB.
 *  2. Conținutul: fiecare .json e un obiect JSON valid; .md și .css sunt UTF-8; un .css nu are „</style”, @import sau
 *     url() spre alt server (adresa IP a vizitatorului ar pleca la un terț) și nici „javascript:”/expression();
 *     fiecare .svg trece de lista albă din App\Support\SvgSigur.
 *     setari-inghetate.json (și corecturile -<N>) se citește ca la runner-ul migrărilor (App\Support\Migrari\Inghetare)
 *     și e a site-ului pachetului; conținutul îl verifică tools/inghetare/verifica-inghetare.php.
 *  3. tema/tema.json: App\Support\Tema::valideaza (cheile, tipurile, adresele, fișierele lor din pachet, iconițele prin
 *     SvgSigur::fragment, blocurile css_inline fără „</style”); un bloc .css din tema/ nefolosit e ATENȚIE.
 *  4. pachet.json (App\Support\PachetSite::verifica): formatul 1, site-ul (numele dosarului pentru site-uri/<site>),
 *     exact fișierele pachetului cu sha256-ul lor, fișierele obligatorii prezente.
 *  5. site-uri/<site>/ce-se-schimba.md există (ce vede altfel clientul la trecerea pe codul comun; G-m4).
 *
 * Cod de ieșire: 0 = niciun pachet cu probleme; 1 = probleme (listate); 2 = argument greșit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$radacina = (string) realpath(dirname(__DIR__, 2));
require_once $radacina . '/bootstrap.php';

use App\Support\Migrari\Eroare;
use App\Support\Migrari\Inghetare;
use App\Support\PachetSite;
use App\Support\SvgSigur;
use App\Support\Tema;

const MARIME_MAXIMA = 1048576;
const IMAGINI = ['svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'ico'];
const FISIERE_RADACINA = [
    'README.md' => 'notele pachetului (nu le citește codul)',
    'ce-se-schimba.md' => 'ce vede altfel clientul la trecerea pe codul comun',
    'redirecturi.json' => 'adresele vechi ale site-ului (SeoRedirects)',
    'seo.json' => 'regulile SEO ale site-ului (DateSite::seo)',
    'site.json' => 'constantele tehnice ale site-ului (DateSite::site)',
    'setari-inghetate.json' => 'înghețarea setărilor, aplicată o dată la adoptare',
    'migrari.json' => 'parametrii migrărilor știuți doar de site',
    'pluginuri.json' => 'copia contractului (doar pentru unelte)',
    'seo-texte.json' => 'textele Google scrise de mână (scripts/seo-texte.php)',
    'avertismente-produse.json' => 'avertismentele produselor (scripts/avertisment-produse.php)',
];

$argumente = array_slice($argv, 1);
$actualizeaza = in_array('--actualizeaza-manifest', $argumente, true);
$autotest = in_array('--autotest', $argumente, true);
$dosare = [];
foreach ($argumente as $argument) {
    if (str_starts_with($argument, '--')) {
        if (!in_array($argument, ['--actualizeaza-manifest', '--autotest'], true)) {
            fwrite(STDERR, "argument necunoscut: $argument (vezi începutul fișierului)\n");
            exit(2);
        }
        continue;
    }
    $cale = $argument[0] === '/' ? $argument : $radacina . '/' . $argument;
    if (!is_dir($cale)) {
        fwrite(STDERR, "nu e un dosar: $argument\n");
        exit(2);
    }
    $dosare[] = rtrim((string) realpath($cale), '/');
}
if ($dosare === [] && !$autotest) {
    foreach (glob($radacina . '/site-uri/*', GLOB_ONLYDIR) ?: [] as $dosar) {
        $dosare[] = $dosar;
    }
    $dosare[] = $radacina . '/tools/dev/site-demo';
    $dosare[] = $radacina . '/tools/dev/site-demo-tema';
}

/** Fișierele nucleului din public/ (din git): un fișier al unui pachet nu are voie să le acopere. */
$fisiereNucleuPublic = (static function () use ($radacina): array {
    exec('git -C ' . escapeshellarg($radacina) . ' ls-files -z public 2>/dev/null', $iesire, $cod);
    $lista = [];
    foreach (explode("\0", implode("\n", $iesire)) as $fisier) {
        if (str_starts_with($fisier, 'public/')) {
            $lista[substr($fisier, strlen('public/'))] = true;
        }
    }
    return $lista;
})();

/** Al cui site e pachetul: numele dosarului pentru site-uri/<site>, null (oricare) pentru pachetele de dezvoltare. */
$siteAsteptat = static function (string $dosar) use ($radacina): ?string {
    return str_starts_with($dosar, $radacina . '/site-uri/') ? basename($dosar) : null;
};

/**
 * @return array{probleme: list<string>, atentionari: list<string>, fisiere: int}
 */
$verificaPachet = static function (string $dosar, ?string $site, bool $alUnuiClient) use ($fisiereNucleuPublic): array {
    $probleme = [];
    $atentionari = [];
    $fisiere = PachetSite::fisiere($dosar);
    $utf8 = static fn (string $text): bool => preg_match('//u', $text) === 1;

    // 3. tema.json întâi: lista js_final spune ce scripturi sunt voie în tema/public/.
    $scripturiDeclarate = [];
    $blocuriFolosite = [];
    if (is_file($dosar . '/' . Tema::FISIER)) {
        $brut = json_decode((string) file_get_contents($dosar . '/' . Tema::FISIER), true);
        if (!is_array($brut)) {
            $probleme[] = Tema::FISIER . ': nu e JSON valid';
        } else {
            [$valori, $problemeTema] = Tema::valideaza($brut, $dosar, true);
            foreach ($problemeTema as $problema) {
                $probleme[] = Tema::FISIER . ': ' . $problema;
            }
            foreach ((array) ($brut['js_final'] ?? []) as $adresa) {
                $relativ = is_string($adresa) ? Tema::relativTema($adresa, 'js') : null;
                if ($relativ !== null) {
                    $scripturiDeclarate['tema/public/' . $relativ] = true;
                }
            }
            $blocuriFolosite = array_flip(array_map(static fn ($f): string => 'tema/' . $f, array_values((array) $valori['css_inline'])));
        }
    }

    // 1, 2. Fiecare fișier, după locul lui.
    foreach ($fisiere as $relativ) {
        $cale = $dosar . '/' . $relativ;
        $parti = explode('/', $relativ);
        $extensie = strtolower(pathinfo($relativ, PATHINFO_EXTENSION));
        if (is_link($cale)) {
            $probleme[] = $relativ . ': legătură simbolică (un pachet are doar fișiere adevărate)';
            continue;
        }
        if (preg_match('/\.(php\d?|phtml|phar|phps|pht|inc|cgi|pl|py|sh)$/i', $relativ) === 1 || preg_match('/(^|\/)\.?htaccess$|\.user\.ini$/i', $relativ) === 1) {
            $probleme[] = $relativ . ': cod executabil pe server (PHP, .htaccess, scripturi): un pachet nu are voie să conțină';
            continue;
        }
        foreach ($parti as $parte) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $parte) !== 1) {
                $probleme[] = $relativ . ': nume nepermis (fișier ascuns, spațiu sau alt semn; doar litere, cifre, „.”, „-”, „_”)';
                continue 2;
            }
        }
        $marime = (int) filesize($cale);
        if ($marime > MARIME_MAXIMA) {
            $probleme[] = $relativ . ': ' . $marime . ' octeți, peste limita de 1 MB';
            continue;
        }
        $permis = false;
        if (count($parti) === 1) {
            $permis = in_array($extensie, ['json', 'md'], true);
            if ($permis && !array_key_exists($relativ, FISIERE_RADACINA) && preg_match(Inghetare::TIPAR_FISIER, $relativ) !== 1) {
                $atentionari[] = $relativ . ': fișier necunoscut în rădăcina pachetului (docs/PACHETE.md)';
            }
        } elseif ($parti[0] === 'tema' && count($parti) === 2) {
            $permis = $relativ === Tema::FISIER || $extensie === 'css';
            if ($permis && $extensie === 'css' && !isset($blocuriFolosite[$relativ])) {
                $atentionari[] = $relativ . ': bloc CSS pe care tema.json → css_inline nu-l folosește';
            }
        } elseif ($parti[0] === 'tema' && $parti[1] === 'public') {
            if ($extensie === 'js') {
                $permis = isset($scripturiDeclarate[$relativ]);
                if (!$permis) {
                    $probleme[] = $relativ . ': JavaScript nedeclarat în tema.json → js_final';
                    continue;
                }
            } else {
                $permis = in_array($extensie, array_merge(IMAGINI, ['woff2', 'css']), true);
            }
        } elseif ($parti[0] === 'public') {
            $tinta = substr($relativ, strlen('public/'));
            $permis = in_array($extensie, IMAGINI, true);
            if ($permis && (str_starts_with($tinta, 'uploads/') || str_starts_with($tinta, 'tema/'))) {
                $probleme[] = $relativ . ': public/' . $tinta . ' e locul pozelor clientului sau al temei, nu al unui fișier al pachetului';
                continue;
            }
            if ($permis && isset($fisiereNucleuPublic[$tinta])) {
                $probleme[] = $relativ . ': ar acoperi fișierul nucleului public/' . $tinta;
                continue;
            }
        } elseif ($parti[0] === PachetSite::DOSAR_PRIVAT) {
            $permis = $extensie === 'json';
        } elseif ($parti[0] === 'continut') {
            $probleme[] = $relativ . ': continut/ (conținutul de pornire al unui site nou, GR-20) încă nu face parte din format';
            continue;
        }
        if (!$permis) {
            $probleme[] = $relativ . ': tip de fișier nepermis aici (docs/PACHETE.md, „Ce poate conține un pachet”)';
            continue;
        }
        $continut = (string) file_get_contents($cale);
        if ($extensie === 'json') {
            $date = json_decode($continut, true);
            if (!is_array($date) || ($date !== [] && array_is_list($date))) {
                $probleme[] = $relativ . ': nu e un obiect JSON valid (' . json_last_error_msg() . ')';
            }
        } elseif (in_array($extensie, ['md', 'css', 'js'], true) && !$utf8($continut)) {
            $probleme[] = $relativ . ': nu e text UTF-8';
        }
        if ($extensie === 'css') {
            if (preg_match('#</style#i', $continut) === 1) {
                $probleme[] = $relativ . ': conține „</style”';
            }
            if (preg_match('#url\(\s*[\'"]?\s*(https?:|//)|@import\s+(url\(\s*)?[\'"]?\s*(https?:|//)#i', $continut) === 1) {
                $probleme[] = $relativ . ': încarcă ceva de pe alt server (url() sau @import spre http/https)';
            }
            if (preg_match('/javascript:|expression\s*\(|behavior\s*:/i', $continut) === 1) {
                $probleme[] = $relativ . ': conține „javascript:”, expression() sau behavior';
            }
        }
        if ($extensie === 'svg') {
            $problema = SvgSigur::problemaFisier($continut);
            if ($problema !== null) {
                $probleme[] = $relativ . ': SVG nesigur (' . $problema . ')';
            }
        }
    }

    // Înghețarea setărilor (și corecturile ei, setari-inghetate-<N>.json): se citește exact ca la runner-ul migrărilor și e
    // a site-ului pachetului. Conținutul (completitudinea, datele private) îl verifică tools/inghetare/verifica-inghetare.php.
    foreach ($fisiere as $relativ) {
        if (preg_match(Inghetare::TIPAR_FISIER, $relativ) !== 1 || is_link($dosar . '/' . $relativ)) {
            continue;
        }
        try {
            $inghetare = Inghetare::dinFisier($dosar . '/' . $relativ);
            if ($site !== null && $inghetare->site() !== $site) {
                $probleme[] = $relativ . ': e înghețarea site-ului „' . $inghetare->site() . '”, nu a lui „' . $site . '”';
            }
        } catch (Eroare $e) {
            $probleme[] = $relativ . ': runner-ul migrărilor n-o citește (' . $e->getMessage() . ')';
        }
    }

    // privat/ (valorile private ale înghețării, ignorate de git, OD-49): nu intră în manifest, dar tot doar JSON.
    $dosarPrivat = $dosar . '/' . PachetSite::DOSAR_PRIVAT;
    if (is_dir($dosarPrivat)) {
        foreach (PachetSite::fisiere($dosarPrivat) as $privat) {
            $cale = $dosarPrivat . '/' . $privat;
            if (is_link($cale) || str_contains($privat, '/') || strtolower(pathinfo($privat, PATHINFO_EXTENSION)) !== 'json') {
                $probleme[] = PachetSite::DOSAR_PRIVAT . '/' . $privat . ': în privat/ stau doar fișiere .json';
            } elseif (!is_array(json_decode((string) file_get_contents($cale), true))) {
                $probleme[] = PachetSite::DOSAR_PRIVAT . '/' . $privat . ': nu e JSON valid';
            }
        }
    }

    // 4. Manifestul.
    foreach (PachetSite::verifica($dosar, $site) as $problema) {
        $probleme[] = $problema;
    }
    // 5. Ce se schimbă pentru client.
    if ($alUnuiClient && !is_file($dosar . '/ce-se-schimba.md')) {
        $probleme[] = 'ce-se-schimba.md lipsește (fiecare site existent are lista schimbărilor pe care le vede la trecerea pe codul comun)';
    }
    return ['probleme' => $probleme, 'atentionari' => $atentionari, 'fisiere' => count($fisiere)];
};

$relativ = static fn (string $dosar): string => str_starts_with($dosar, $radacina . '/') ? substr($dosar, strlen($radacina) + 1) : $dosar;

// ---------------------------------------------------------------------
// --actualizeaza-manifest
// ---------------------------------------------------------------------
if ($actualizeaza) {
    foreach ($dosare as $dosar) {
        $existent = PachetSite::manifest($dosar);
        $site = $siteAsteptat($dosar) ?? (is_string($existent['site'] ?? null) ? (string) $existent['site'] : 'demo');
        $text = PachetSite::text(PachetSite::calculeaza($dosar, $site));
        $cale = $dosar . '/' . PachetSite::MANIFEST;
        if (is_file($cale) && file_get_contents($cale) === $text) {
            echo '  la zi   ', $relativ($dosar), '/', PachetSite::MANIFEST, "\n";
            continue;
        }
        file_put_contents($cale, $text);
        echo '  scris   ', $relativ($dosar), '/', PachetSite::MANIFEST, "\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------
// --autotest: fiecare greșeală plantată într-o copie a pachetului temei demo trebuie prinsă
// ---------------------------------------------------------------------
if ($autotest) {
    $temp = sys_get_temp_dir() . '/cms-verifica-pachet-' . getmypid();
    $esuate = 0;
    $trecute = 0;
    $copie = static function (string $nume) use ($temp, $radacina): string {
        $dosar = $temp . '/' . $nume;
        exec('mkdir -p ' . escapeshellarg($dosar) . ' && cp -R ' . escapeshellarg($radacina . '/tools/dev/site-demo-tema') . '/. ' . escapeshellarg($dosar));
        @unlink($dosar . '/' . PachetSite::MANIFEST);
        return $dosar;
    };
    $manifest = static function (string $dosar): void {
        file_put_contents($dosar . '/' . PachetSite::MANIFEST, PachetSite::text(PachetSite::calculeaza($dosar, 'demo')));
    };
    $caz = static function (string $eticheta, callable $planteaza, ?string $asteptat, bool $client = false, bool $faraManifest = false)
        use ($copie, $manifest, $verificaPachet, &$esuate, &$trecute): void {
        static $n = 0;
        $dosar = $copie('caz-' . (++$n));
        $planteaza($dosar);
        if (!$faraManifest) {
            $manifest($dosar);
        }
        $rez = $verificaPachet($dosar, null, $client);
        $toate = implode(' | ', $rez['probleme']);
        $ok = $asteptat === null ? $rez['probleme'] === [] : ($rez['probleme'] !== [] && str_contains($toate, $asteptat));
        if ($ok) {
            $trecute++;
            echo '  OK    ', $eticheta, $asteptat !== null ? ' → „' . $asteptat . '”' : '', "\n";
        } else {
            $esuate++;
            echo '  EȘEC  ', $eticheta, ': ', $toate !== '' ? $toate : 'nicio problemă găsită', "\n";
        }
    };
    $tema = static function (string $dosar, callable $schimba): void {
        $date = json_decode((string) file_get_contents($dosar . '/' . Tema::FISIER), true);
        $date = $schimba($date);
        file_put_contents($dosar . '/' . Tema::FISIER, json_encode($date, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    };
    echo "Autotestul verificării pachetelor (pe copii ale tools/dev/site-demo-tema)\n";
    $caz('pachetul temei demo, neatins', static fn (string $d) => null, null);
    $caz('un fișier PHP în tema/public/', static fn (string $d) => file_put_contents($d . '/tema/public/js/rau.php', "<?php echo 1;\n"), 'cod executabil');
    $caz('un .phtml în rădăcină', static fn (string $d) => file_put_contents($d . '/pagina.phtml', "<?= 1 ?>\n"), 'cod executabil');
    $caz('un .htaccess în tema/public/', static fn (string $d) => file_put_contents($d . '/tema/public/.htaccess', "Allow from all\n"), 'cod executabil');
    $caz('o legătură simbolică', static fn (string $d) => symlink('/etc/hostname', $d . '/tema/public/img/legatura.svg'), 'legătură simbolică');
    $caz('un SVG cu <script>', static fn (string $d) => file_put_contents($d . '/tema/public/img/rau.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'SVG nesigur');
    $caz('un SVG cu onload', static fn (string $d) => file_put_contents($d . '/tema/public/img/rau.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0h1"/></svg>'), 'SVG nesigur');
    $caz('un SVG cu o imagine de pe alt server', static fn (string $d) => file_put_contents($d . '/tema/public/img/rau.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://exemplu.test/a.svg#x"/></svg>'), 'SVG nesigur');
    $caz('un SVG cu DOCTYPE și entități', static fn (string $d) => file_put_contents($d . '/tema/public/img/rau.svg',
        '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'), 'SVG nesigur');
    $caz('un script nedeclarat în js_final', static fn (string $d) => file_put_contents($d . '/tema/public/js/nedeclarat.js', "void 0;\n"), 'nedeclarat');
    $caz('un fișier peste 1 MB', static fn (string $d) => file_put_contents($d . '/tema/public/img/mare.png', str_repeat('x', MARIME_MAXIMA + 1)), 'peste limita');
    $caz('o iconiță cu <script> în iconite_categorii', static fn (string $d) => $tema($d, static function (array $t): array {
        $t['iconite_categorii'][] = ['rau', '<script>alert(1)</script>'];
        return $t;
    }), 'element nepermis în iconiță');
    $caz('o iconiță cu un atribut on…', static fn (string $d) => $tema($d, static function (array $t): array {
        $t['iconite_categorii'][] = ['rau', '<path d="M0 0" onclick="alert(1)"/>'];
        return $t;
    }), 'atribut nepermis în iconiță');
    $caz('blocul css_inline cu „</style”', static fn (string $d) => file_put_contents($d . '/tema/pagina-succes.css', ".a{}</style><script>alert(1)</script>\n"), '</style');
    $caz('CSS-ul temei cu un font de la Google', static fn (string $d) => file_put_contents($d . '/tema/public/css/tema-demo.css',
        "@import url('https://fonts.googleapis.com/css2?family=Rubik');\n", FILE_APPEND), 'alt server');
    $caz('o cheie necunoscută în tema.json', static fn (string $d) => $tema($d, static function (array $t): array {
        $t['culoare'] = '#000';
        return $t;
    }), 'cheie necunoscută');
    $caz('o foaie de stil a temei care lipsește', static fn (string $d) => $tema($d, static function (array $t): array {
        $t['css'][] = '/tema/css/lipsa.css';
        return $t;
    }), 'lipsește din tema/public/');
    $caz('un fișier schimbat după manifest', static function (string $d) use ($manifest): void {
        $manifest($d);
        file_put_contents($d . '/tema/public/css/tema-demo.css', "/* schimbat */\n", FILE_APPEND);
    }, 'sha256', false, true);
    $caz('un fișier care nu e în manifest', static function (string $d) use ($manifest): void {
        $manifest($d);
        file_put_contents($d . '/tema/public/img/nou.png', 'png');
    }, 'nu e în pachet.json', false, true);
    $caz('fără pachet.json', static fn (string $d) => null, 'pachet.json lipsește', false, true);
    $caz('un pachet de client fără ce-se-schimba.md', static fn (string $d) => null, 'ce-se-schimba.md lipsește', true);
    $caz('un fișier public/ peste unul al nucleului', static fn (string $d) => @mkdir($d . '/public/assets/img', 0770, true)
        && file_put_contents($d . '/public/assets/img/product-placeholder.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), 'acoperi fișierul nucleului');
    $caz('un fișier public/ în uploads/', static fn (string $d) => @mkdir($d . '/public/uploads/gallery', 0770, true)
        && file_put_contents($d . '/public/uploads/gallery/x.png', 'png'), 'locul pozelor');
    $caz('un fișier în continut/ (încă nu e în format)', static fn (string $d) => @mkdir($d . '/continut', 0770, true)
        && file_put_contents($d . '/continut/pagina.html', '<p>x</p>'), 'continut/');
    $caz('un fișier ascuns', static fn (string $d) => file_put_contents($d . '/tema/public/img/.ascuns.png', 'png'), 'nume nepermis');
    $caz('un JSON stricat în rădăcină', static fn (string $d) => file_put_contents($d . '/seo.json', '{"a": '), 'nu e un obiect JSON');
    $caz('altceva decât JSON în privat/', static fn (string $d) => @mkdir($d . '/privat', 0770, true)
        && file_put_contents($d . '/privat/note.txt', 'x'), 'doar fișiere .json');
    $caz('o înghețare a setărilor pe care runner-ul n-o citește', static fn (string $d) => file_put_contents($d . '/' . Inghetare::FISIER,
        '{"format": 2, "site": "demo", "altceva": 1}'), 'runner-ul migrărilor n-o citește');
    exec('rm -rf ' . escapeshellarg($temp));
    echo 'verifica-pachet --autotest: ', $trecute, ' cazuri trecute, ', $esuate, " eșuate\n";
    exit($esuate === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------
// Verificarea pachetelor
// ---------------------------------------------------------------------
$total = 0;
$cuProbleme = 0;
$fisiereTotal = 0;
foreach ($dosare as $dosar) {
    $total++;
    $rez = $verificaPachet($dosar, $siteAsteptat($dosar), $siteAsteptat($dosar) !== null);
    $fisiereTotal += $rez['fisiere'];
    foreach ($rez['atentionari'] as $atentionare) {
        echo '  ATENȚIE  ', $relativ($dosar), ': ', $atentionare, "\n";
    }
    if ($rez['probleme'] === []) {
        echo '  OK    ', $relativ($dosar), ': ', $rez['fisiere'], ' fișiere, manifestul la zi', "\n";
        continue;
    }
    $cuProbleme++;
    foreach ($rez['probleme'] as $problema) {
        echo '  EȘEC  ', $relativ($dosar), ': ', $problema, "\n";
    }
}
if ($cuProbleme > 0) {
    echo "  (după o schimbare voită într-un pachet: tools/dev/php.sh tools/dev/verifica-pachet.php --actualizeaza-manifest)\n";
}
echo 'verifica-pachet: ', $total, ' pachete, ', $fisiereTotal, ' fișiere, ', $cuProbleme, " cu probleme\n";
exit($cuProbleme === 0 ? 0 : 1);
