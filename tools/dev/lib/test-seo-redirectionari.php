<?php

declare(strict_types=1);

/**
 * tools/dev/test-seo.php, secțiunea E2 (WP-38, SEO partea B, B2): managerul redirecționărilor (App\Support\Redirectionari),
 * harta din bază aplicată de SeoRedirects, jurnalul 404 (App\Support\Jurnal404) și redirecționările automate. Inclus de
 * test-seo.php după E1, cu variabilele lui ($verifica, $scrie, $cerere, $port, $db, $radacina, $jarNou, $adminEmail,
 * $parola, $locatie).
 *
 *  1. Harta din bază = pachetul: rândurile „pachet” sunt exact regulile din redirecturi.json (demo și Bioscem), iar
 *     SeoRedirects dă pe fiecare cheie același rezultat cu copia din bază ca doar cu pachetul (lanțuri, bucle, 410).
 *  2. Verificările unei reguli noi: fără redirecționare deschisă, nicio cale exclusă (lista nucleului, fiecare adresă
 *     mereu deschisă a pluginurilor, seo.json) ca adresă veche sau ca țintă, fără buclă, fără lanț; lanțurile se aplatizează.
 *  3. Din admin (cu jetonul formularului): o regulă nouă → 301 într-un salt, parametrii păstrați, fără Set-Cookie, accesările
 *     numărate; oprită → 404; o regulă din pachet ștearsă rămâne oprită și după reluarea pachetului; importul CSV (adresele
 *     vechi cu diacriticele tăiate, un rând refuzat, un 410); exportul CSV; fără jeton, refuzat.
 *  4. Jurnalul 404: doar calea (fără parametri), fără „@”, fără căile excluse și scanările, doar GET, doar gazda de pe care a
 *     venit omul; păstrarea (90 de zile, cel mult 5.000 de rânduri); „Redirecționează” din jurnal.
 *  5. Automat: un produs cu slug schimbat → 301 de la adresa veche la cea nouă (un singur rând, apoi 200), redenumit din nou →
 *     tot un singur salt, înapoi la primul slug → fără regulă pe o adresă care există; o categorie ștearsă propune lista
 *     produselor (categoriile n-au părinte) și se salvează 410 când e ales.
 */

use App\Support\DateSite;
use App\Support\Jurnal404;
use App\Support\Redirectionari;
use App\Support\SeoRedirects;

$scrie('');
$scrie('== E2. Redirecționările din admin, jurnalul 404, redirecționările automate (WP-38)');

$cerereSimpla = static fn (string $cale, array $antete = []): array => $cerere($port, 'GET', $cale, $antete);
$curataTest = static function () use ($db): void {
    $db->exec("DELETE FROM seo_redirects WHERE source <> 'pachet' AND (from_path LIKE '/e2-%' OR from_path LIKE '/blog/e2-%'
        OR from_path LIKE '/produs/produs-e2-%' OR from_path LIKE '/categorie/categorie-e2%' OR note LIKE 'E2%')");
    $db->exec("DELETE FROM seo_404_log WHERE path LIKE '/e2-%' OR path LIKE '/nu-exista-e2%'");
};
$curataTest();
$cerereSimpla('/'); // copia hărții la zi (refăcută la prima cerere, dacă lipsea)

// ---------------------------------------------------------------------------------------------------------------------
// 1. Harta din bază = pachetul.
// ---------------------------------------------------------------------------------------------------------------------
$randuriPachet = static function () use ($db): array {
    $r = [];
    foreach ($db->query("SELECT from_path, is_prefix, to_url, code FROM seo_redirects WHERE source = 'pachet' ORDER BY is_prefix, from_path")
        ->fetchAll(PDO::FETCH_ASSOC) as $rand) {
        $r[] = [(string) $rand['from_path'], (int) $rand['is_prefix'] === 1, $rand['to_url'] === null ? null : (string) $rand['to_url'], (int) $rand['code']];
    }
    return $r;
};
$dinFisier = static function (): array {
    $r = [];
    $f = DateSite::dosar() . '/redirecturi.json';
    foreach (Redirectionari::reguliPachet((array) json_decode((string) file_get_contents($f), true)) as $x) {
        $r[] = [$x['din'], $x['prefix'], $x['spre'], $x['cod']];
    }
    usort($r, static fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
    return $r;
};
$copie = json_decode((string) @file_get_contents(SeoRedirects::fisierCopie()), true);
$verifica('harta din bază: rândurile „pachet” sunt exact regulile din redirecturi.json al pachetului demo, iar copia aplicată e din bază',
    $randuriPachet() === $dinFisier() && ($copie['sursa'] ?? '') === 'baza' && ($copie['semnatura'] ?? '') === SeoRedirects::semnaturaPachet(),
    count($randuriPachet()) . ' rânduri, ' . count($dinFisier()) . ' reguli, copia ' . (string) ($copie['sursa'] ?? '?'));
// Aceeași hartă, cheie cu cheie, cu și fără copia din bază (în acest proces), pe demo și pe pachetul Bioscem.
$comparaHarta = static function () use ($db): array {
    $chei = [];
    foreach (Redirectionari::reguliPachet((array) json_decode((string) file_get_contents(DateSite::dosar() . '/redirecturi.json'), true)) as $x) {
        $chei[] = $x['din'];
        $chei[] = $x['din'] . '/altceva';
        $chei[] = strtoupper($x['din']) . '/';
    }
    $faraBaza = [];
    SeoRedirects::reseteaza();
    SeoRedirects::faraCopie(true);
    foreach ($chei as $k) {
        $r = SeoRedirects::regulaPentru($k);
        $faraBaza[$k] = [$r, $r !== null && $r[0] !== null ? SeoRedirects::tintaFinala($k, $r[0]) : null];
    }
    SeoRedirects::faraCopie(false);
    Redirectionari::reconstruieste($db);
    $cuBaza = [];
    foreach ($chei as $k) {
        $r = SeoRedirects::regulaPentru($k);
        $cuBaza[$k] = [$r, $r !== null && $r[0] !== null ? SeoRedirects::tintaFinala($k, $r[0]) : null];
    }
    return [count($chei), array_keys(array_filter($cuBaza, static fn ($v, $k) => $v !== $faraBaza[$k], ARRAY_FILTER_USE_BOTH))];
};
[$nrDemo, $diferiteDemo] = $comparaHarta();
$verifica('harta: cu copia din bază, aceeași regulă și aceeași țintă finală (lanț, buclă, 410, prefix) pe ' . $nrDemo . ' de adrese ale pachetului demo',
    $diferiteDemo === [], implode(', ', array_slice($diferiteDemo, 0, 5)));
$dosarDemoE2 = DateSite::dosar();
try {
    DateSite::reseteaza($radacina . '/site-uri/bioscem');
    SeoRedirects::reseteaza();
    $import = Redirectionari::importaPachet($db);
    [$nrBs, $diferiteBs] = $comparaHarta();
    $verifica('pachetul Bioscem (127 exacte, 6 prefixe): importat cum e, apoi aceeași hartă cheie cu cheie pe ' . $nrBs . ' de adrese, '
        . 'aceleași reguli în bază ca în fișier', $diferiteBs === [] && $randuriPachet() === $dinFisier()
        && count(array_filter($randuriPachet(), static fn ($r) => !$r[1])) === 128 && count(array_filter($randuriPachet(), static fn ($r) => $r[1])) === 6,
        json_encode($import) . ' ' . implode(', ', array_slice($diferiteBs, 0, 5)));
} finally {
    @unlink(SeoRedirects::fisierCopie());
    DateSite::reseteaza($dosarDemoE2);
    SeoRedirects::reseteaza();
    Redirectionari::reconstruieste($db);
}
$verifica('înapoi pe pachetul demo: rândurile Bioscem au plecat, cele demo sunt la loc', $randuriPachet() === $dinFisier());

// ---------------------------------------------------------------------------------------------------------------------
// 2. Verificările unei reguli noi.
// ---------------------------------------------------------------------------------------------------------------------
$eroare = static fn (string $din, string $spre, int $cod = 301, bool $prefix = false): string
    => (string) (Redirectionari::valideaza($db, $din, $spre, $cod, $prefix)['eroare'] ?? '');
$deschise = ['https://alt-site.example/x', '//alt-site.example/x', '/\\alt-site.example', 'javascript:alert(1)', 'http://127.0.0.2:' . $port . '/x',
    "/x\r\nLocation: https://alt-site.example", '/x y', 'ftp://alt-site.example/', 'https://user@127.0.0.1:' . $port . '/x'];
$acceptate = array_filter($deschise, static fn (string $t): bool => $eroare('/e2-deschisa', $t) === '');
$verifica('fără redirecționare deschisă: nicio țintă pe alt site (' . count($deschise) . ' forme: https, //, \\, javascript:, alt port, rând nou, '
    . 'spații, ftp, utilizator în adresă)', $acceptate === [], implode(' | ', $acceptate));
$pePropriaGazda = Redirectionari::valideaza($db, 'http://127.0.0.1:' . $port . '/E2-Veche/?utm=1', 'http://127.0.0.1:' . $port . '/magazin?sort=nou', 301);
$verifica('o adresă întreagă pe gazda site-ului devine cale (litere mici, fără „/” final, fără parametri la adresa veche; ținta își păstrează parametrii)',
    ($pePropriaGazda['din'] ?? '') === '/e2-veche' && ($pePropriaGazda['spre'] ?? '') === '/magazin?sort=nou', json_encode($pePropriaGazda));
$excluse = array_merge(SeoRedirects::CAI_EXCLUSE, SeoRedirects::caiMereuDeschise(), (array) DateSite::seo('cai_excluse', []),
    ['/admin/products', '/cos/adauga/1', '/checkout', '/contul-meu/comenzi', '/api/cart', '/webhook/stripe', '/plata/bt/intoarcere', '/COS', '//cos']);
$neRefuzate = [];
$tinteNeRefuzate = [];
foreach ($excluse as $cale) {
    $cale = rtrim((string) $cale, '/') . (str_ends_with((string) $cale, '/') ? '/x' : '');
    if ($eroare($cale, '/magazin') === '') {
        $neRefuzate[] = $cale;
    }
    if ($eroare('/e2-spre-exclusa', $cale) === '') {
        $tinteNeRefuzate[] = $cale;
    }
}
$verifica('nicio cale exclusă (' . count($excluse) . ': coș, plată, cont, admin, API, confirmările, fiecare adresă mereu deschisă, seo.json) ca adresă veche',
    $neRefuzate === [], implode(', ', $neRefuzate));
$verifica('… și nici ca țintă', $tinteNeRefuzate === [], implode(', ', $tinteNeRefuzate));
$verifica('fără regulă spre ea însăși, fără prima pagină ca adresă veche, doar 301 și 410', $eroare('/e2-sine', '/E2-SINE/') !== ''
    && $eroare('/', '/magazin') !== '' && $eroare('/e2-cod', '/magazin', 302) !== '' && $eroare('/e2-disparuta', '', 410) === ''
    && $eroare('/e2-fara-tinta', '') !== '' && $eroare('/e2-prefix/', '/e2-prefix/alta', 301, true) !== '');
try {
    $a = Redirectionari::adauga($db, '/e2-a', '/e2-b', 301, 'manual', 'E2 lanț');
    $lant = Redirectionari::adauga($db, '/e2-c', '/e2-a', 301, 'manual', 'E2 lanț');
    $bucla = Redirectionari::adauga($db, '/e2-b', '/e2-a', 301, 'manual', 'E2 buclă');
    $aplatizare = Redirectionari::adauga($db, '/e2-b', '/magazin', 301, 'manual', 'E2 aplatizare');
    $tintaA = (string) Redirectionari::dupaCheie($db, '/e2-a', false)['to_url'];
    $verifica('fără lanț (o țintă redirecționată se refuză, cu ținta finală în mesaj), fără buclă; o regulă nouă pe ținta altei reguli o aplatizează',
        $a['ok'] && !$lant['ok'] && str_contains($lant['mesaj'], '/e2-b') && !$bucla['ok'] && str_contains($bucla['mesaj'], 'buclă') && !str_contains($lant['mesaj'], 'buclă')
        && $aplatizare['ok'] && ($aplatizare['aplatizate'] ?? 0) === 1 && $tintaA === '/magazin' && array_filter(Redirectionari::lanturi($db), static fn (array $l): bool => str_contains($l['din'], 'e2')) === [],
        json_encode([$lant['mesaj'], $bucla['mesaj'], $aplatizare['mesaj'], $tintaA], JSON_UNESCAPED_UNICODE));
} finally {
    $curataTest();
    Redirectionari::reconstruieste($db);
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Din admin.
// ---------------------------------------------------------------------------------------------------------------------
$jarRedir = $jarNou('redir-e2');
$cerere($port, 'POST', '/admin/login', [], $jarRedir, ['email' => $adminEmail, 'password' => $parola]);
$pagina = $cerere($port, 'GET', '/admin/seo/redirectionari', [], $jarRedir);
preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $pagina['corp'], $m);
$jeton = $m[1] ?? '';
$post = static fn (string $cale, array $date) => $cerere($port, 'POST', $cale, [], $jarRedir, $date + ['_csrf' => $jeton]);
try {
    $verifica('pagina Redirecționări și 404 (administratorul general) cu formularul, importul, lista; fără logare, trimite la logare',
        $pagina['cod'] === 200 && $jeton !== '' && str_contains($pagina['corp'], 'action="/admin/seo/redirectionari/adauga"')
        && str_contains($pagina['corp'], 'action="/admin/seo/redirectionari/importa"') && str_contains($pagina['corp'], '/produse')
        && $cerere($port, 'GET', '/admin/seo/redirectionari')['cod'] === 302);
    $fara = $cerere($port, 'POST', '/admin/seo/redirectionari/adauga', [], $jarRedir, ['din' => '/e2-fara-jeton', 'spre' => '/magazin', 'cod' => '301']);
    $verifica('fără jetonul formularului: refuzat, nimic scris', Redirectionari::dupaCheie($db, '/e2-fara-jeton', false) === null && $fara['cod'] === 302);
    $r = $post('/admin/seo/redirectionari/adauga', ['din' => '/E2-Manuala/', 'spre' => '/categorie/ceaiuri', 'cod' => '301', 'nota' => 'E2 manuală']);
    $g = $cerereSimpla('/e2-manuala?utm_source=e2&gclid=x');
    $g2 = $cerereSimpla('/E2-MANUALA/');
    $rand = Redirectionari::dupaCheie($db, '/e2-manuala', false);
    $verifica('o regulă adăugată din admin: 301 într-un salt la țintă, parametrii păstrați, fără Set-Cookie, no-store; accesările numărate',
        $r['cod'] === 302 && $g['cod'] === 301 && $locatie($g) === '/categorie/ceaiuri?utm_source=e2&gclid=x' && $g['cookies'] === []
        && $g2['cod'] === 301 && ($g['antete']['cache-control'] ?? '') === 'no-store' && (int) ($rand['hits'] ?? 0) === 2
        && $cerereSimpla($locatie($g))['cod'] === 200 && (string) ($rand['created_by'] ?? '') === $adminEmail,
        $g['cod'] . ' ' . $locatie($g) . ' hits ' . (int) ($rand['hits'] ?? -1));
    $refuz = $post('/admin/seo/redirectionari/adauga', ['din' => '/e2-straina', 'spre' => 'https://alt-site.example/', 'cod' => '301']);
    $verifica('din admin, o țintă pe alt site e refuzată (formularul revine completat)', Redirectionari::dupaCheie($db, '/e2-straina', false) === null
        && str_contains($locatie($refuz), 'din=%2Fe2-straina'));
    $post('/admin/seo/redirectionari/' . (int) $rand['id'] . '/stare', ['pornita' => '0']);
    $oprita = $cerereSimpla('/e2-manuala')['cod'];
    $post('/admin/seo/redirectionari/' . (int) $rand['id'] . '/stare', ['pornita' => '1']);
    $pornita = $cerereSimpla('/e2-manuala')['cod'];
    $verifica('oprită: 404; pornită din nou: 301', $oprita === 404 && $pornita === 301, $oprita . ' / ' . $pornita);
    // O regulă din pachet ștearsă din admin rămâne oprită și după reluarea pachetului.
    $produse = Redirectionari::dupaCheie($db, '/produse', false);
    $post('/admin/seo/redirectionari/' . (int) $produse['id'] . '/sterge', []);
    $post('/admin/seo/redirectionari/pachet', []);
    $dupa = Redirectionari::dupaCheie($db, '/produse', false);
    $verifica('o regulă din pachet „ștearsă” se oprește și rămâne oprită după „Reia regulile din pachet” (404); pornită, 301 din nou',
        (int) ($dupa['is_active'] ?? 1) === 0 && $cerereSimpla('/produse')['cod'] === 404, json_encode($dupa));
    $post('/admin/seo/redirectionari/' . (int) $produse['id'] . '/stare', ['pornita' => '1']);
    $verifica('… pornită din nou: 301 la /magazin', $cerereSimpla('/produse')['cod'] === 301);
    // Importul CSV: adresele vechi ale blogului cu diacriticele tăiate (inventate), un rând refuzat, un 410, un antet.
    $slugPostare = (string) $db->query("SELECT slug FROM blog_posts WHERE deleted_at IS NULL AND is_published = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $csv = "adresa_veche;tinta;cod;nota\n/blog/e2-sntatea-copiilor;/blog/" . $slugPostare . ";301;E2 import\n"
        . "/blog/e2-ceai-pentru-imunitate;https://alt-site.example/;301;E2 import\n/e2-produs-retras;;410;E2 import\n"
        . "\"/blog/e2-%C8%99tiri\";\"/blog/" . $slugPostare . "\"\n";
    $imp = $post('/admin/seo/redirectionari/importa', ['csv' => $csv]);
    $paginaDupa = $cerere($port, 'GET', '/admin/seo/redirectionari', [], $jarRedir)['corp'];
    $verifica('importul CSV: 3 reguli adăugate (și una cu adresa codată %C8%99), una refuzată cu motivul, antetul sărit; fiecare răspunde',
        Redirectionari::dupaCheie($db, '/blog/e2-sntatea-copiilor', false) !== null && Redirectionari::dupaCheie($db, '/blog/e2-știri', false) !== null
        && Redirectionari::dupaCheie($db, '/blog/e2-ceai-pentru-imunitate', false) === null && str_contains($paginaDupa, 'refuzate')
        && $cerereSimpla('/blog/e2-sntatea-copiilor')['cod'] === 301 && $cerereSimpla('/e2-produs-retras')['cod'] === 410
        && $locatie($cerereSimpla('/blog/e2-%C8%99tiri')) === '/blog/' . $slugPostare, $imp['cod'] . ' ' . $locatie($imp));
    $export = $cerere($port, 'GET', '/admin/seo/redirectionari/export', [], $jarRedir);
    $verifica('exportul CSV: toate regulile, cu antetul', $export['cod'] === 200 && str_contains($export['corp'], 'adresa_veche,tinta,cod')
        && str_contains($export['corp'], '/blog/e2-sntatea-copiilor,/blog/' . $slugPostare . ',301'));

    // -----------------------------------------------------------------------------------------------------------------
    // 4. Jurnalul 404.
    // -----------------------------------------------------------------------------------------------------------------
    $cerere($port, 'GET', '/nu-exista-e2-unu?email=cineva%40exemplu.ro&token=secret', ['Referer: https://www.google.com/search?q=ceva+personal']);
    $cerere($port, 'GET', '/Nu-Exista-E2-Unu/', ['Referer: http://127.0.0.1:' . $port . '/alta-pagina']);
    $cerere($port, 'GET', '/nu-exista-e2-cineva@exemplu.ro');
    $cerere($port, 'GET', '/nu-exista-e2-scanare.php');
    $cerere($port, 'GET', '/cos/nu-exista-e2');
    $cerere($port, 'POST', '/nu-exista-e2-post');
    $cerere($port, 'HEAD', '/nu-exista-e2-head');
    $jurnal = $db->query("SELECT path, hits, referrer_host FROM seo_404_log WHERE path LIKE '/nu-exista-e2%' OR path LIKE '/cos/%' ORDER BY path")->fetchAll(PDO::FETCH_ASSOC);
    $text = (string) json_encode($db->query("SELECT * FROM seo_404_log WHERE path LIKE '%e2%'")->fetchAll(PDO::FETCH_ASSOC));
    $verifica('jurnalul 404: doar calea (litere mici, fără „/” final, fără parametri), de câte ori, gazda Google (fără căutare); nimic cu „@”, '
        . 'scanare, coș, POST, HEAD; nicio urmă din parametri sau din adresa de pe care a venit', $jurnal === [['path' => '/nu-exista-e2-unu', 'hits' => 2,
        'referrer_host' => 'www.google.com']] && !str_contains($text, 'secret') && !str_contains($text, 'exemplu.ro') && !str_contains($text, 'personal'),
        json_encode($jurnal));
    $jurnalPagina = $cerere($port, 'GET', '/admin/seo/404', [], $jarRedir)['corp'];
    $post('/admin/seo/redirectionari/adauga', ['din' => '/nu-exista-e2-unu', 'spre' => '/magazin', 'cod' => '301', 'sursa' => '404', 'inapoi' => '/admin/seo/404']);
    $jurnalDupa = $cerere($port, 'GET', '/admin/seo/404', [], $jarRedir)['corp'];
    $verifica('„Redirecționează” din jurnal: regula salvată (sursa „404”), adresa răspunde 301, iar jurnalul arată „Are regulă”',
        str_contains($jurnalPagina, '/admin/seo/redirectionari?din=%2Fnu-exista-e2-unu') && $cerereSimpla('/nu-exista-e2-unu')['cod'] === 301
        && (string) (Redirectionari::dupaCheie($db, '/nu-exista-e2-unu', false)['source'] ?? '') === '404' && str_contains($jurnalDupa, 'Are regulă'));
    // Păstrarea: 90 de zile și cel mult 5.000 de rânduri.
    $db->exec("INSERT INTO seo_404_log (path_hash, path, hits, first_seen_at, last_seen_at) VALUES (SHA1('/e2-vechi'), '/e2-vechi', 1,
        NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 91 DAY)");
    $inainte = (int) $db->query('SELECT COUNT(*) FROM seo_404_log')->fetchColumn();
    $valori = [];
    for ($i = 0; $i < Jurnal404::MAX_RANDURI + 3 - $inainte + 1; $i++) {
        $valori[] = "(SHA1('/e2-umplere-" . $i . "'), '/e2-umplere-" . $i . "', 1, NOW() - INTERVAL " . (10 + $i % 50) . " DAY, NOW() - INTERVAL " . (1 + $i % 50) . " DAY)";
    }
    foreach (array_chunk($valori, 1000) as $bucata) {
        $db->exec('INSERT INTO seo_404_log (path_hash, path, hits, first_seen_at, last_seen_at) VALUES ' . implode(', ', $bucata));
    }
    $sterse = Jurnal404::curata($db);
    $verifica('păstrarea: rândurile mai vechi de 90 de zile pleacă, iar jurnalul rămâne la cel mult 5.000 de adrese (cele văzute cel mai demult pleacă)',
        (int) $db->query("SELECT COUNT(*) FROM seo_404_log WHERE path = '/e2-vechi'")->fetchColumn() === 0
        && (int) $db->query('SELECT COUNT(*) FROM seo_404_log')->fetchColumn() === Jurnal404::MAX_RANDURI && $sterse >= 4
        && (int) $db->query("SELECT COUNT(*) FROM seo_404_log WHERE path = '/nu-exista-e2-unu'")->fetchColumn() === 1);
    $db->exec("DELETE FROM seo_404_log WHERE path LIKE '/e2-umplere-%'");

    // -----------------------------------------------------------------------------------------------------------------
    // 5. Automat: slug schimbat, ștergeri.
    // -----------------------------------------------------------------------------------------------------------------
    $db->exec("DELETE FROM products WHERE slug LIKE 'produs-e2-%'");
    $db->prepare("INSERT INTO products (name, slug, price, stock, is_active, description, short_description) VALUES ('Produs E2', 'produs-e2-vechi', 10, 5, 1, '', '')")
        ->execute();
    $idProdusE2 = (int) $db->lastInsertId();
    $redenumeste = static fn (string $slug) => $post('/admin/products/' . $idProdusE2 . '/update', ['name' => 'Produs E2', 'slug' => $slug, 'price' => '10',
        'stock' => '5', 'is_active' => '1', 'vat_percent' => '21', 'vat_included' => '1']);
    $redenumeste('produs-e2-nou');
    $vechi = $cerereSimpla('/produs/produs-e2-vechi');
    $randuriSlug = $db->query("SELECT from_path, to_url, source FROM seo_redirects WHERE from_path LIKE '/produs/produs-e2-%' ORDER BY from_path")->fetchAll(PDO::FETCH_NUM);
    $verifica('slug schimbat (Produse → editorul): un singur rând 301 „Automată”, adresa veche → cea nouă → 200',
        $randuriSlug === [['/produs/produs-e2-vechi', '/produs/produs-e2-nou', 'slug']] && $vechi['cod'] === 301
        && $locatie($vechi) === '/produs/produs-e2-nou' && $cerereSimpla('/produs/produs-e2-nou')['cod'] === 200, json_encode([$randuriSlug, $vechi['cod'], $locatie($vechi)]));
    $redenumeste('produs-e2-al-treilea');
    $foarteVechi = $cerereSimpla('/produs/produs-e2-vechi');
    $verifica('redenumit din nou: ambele adrese vechi trimit direct (un salt) la a treia', $foarteVechi['cod'] === 301
        && $locatie($foarteVechi) === '/produs/produs-e2-al-treilea' && $locatie($cerereSimpla('/produs/produs-e2-nou')) === '/produs/produs-e2-al-treilea'
        && array_filter(Redirectionari::lanturi($db), static fn (array $l): bool => str_contains($l['din'], 'e2')) === []);
    $redenumeste('produs-e2-vechi');
    $randuriInapoi = $db->query("SELECT from_path, to_url FROM seo_redirects WHERE from_path LIKE '/produs/produs-e2-%' ORDER BY from_path")->fetchAll(PDO::FETCH_NUM);
    $verifica('înapoi la primul slug: nicio regulă pe adresa care există din nou, celelalte două trimit la ea, fără buclă',
        $randuriInapoi === [['/produs/produs-e2-al-treilea', '/produs/produs-e2-vechi'], ['/produs/produs-e2-nou', '/produs/produs-e2-vechi']]
        && $cerereSimpla('/produs/produs-e2-vechi')['cod'] === 200, json_encode($randuriInapoi));
    // Produsul șters: propunerea (categoria lui sau lista produselor) apare o dată, pe pagina următoare.
    $post('/admin/products/' . $idProdusE2 . '/delete', []);
    $dupaStergere = $cerere($port, 'GET', '/admin/products', [], $jarRedir)['corp'];
    $aDoua = $cerere($port, 'GET', '/admin/products', [], $jarRedir)['corp'];
    $verifica('produsul șters: propunerea (301 spre lista produselor, 410 sau nimic) apare o singură dată', str_contains($dupaStergere, 'id="ax-propunere-redirect"')
        && str_contains($dupaStergere, 'value="/produs/produs-e2-vechi"') && str_contains($dupaStergere, 'value="/magazin"')
        && !str_contains($aDoua, 'ax-propunere-redirect'));
    // Categoria ștearsă: propunerea e lista produselor (categoriile n-au părinte); alegerea 410 se salvează.
    $db->exec("DELETE FROM product_categories WHERE slug = 'categorie-e2'");
    $post('/admin/categories', ['name' => 'Categorie E2', 'slug' => 'categorie-e2']);
    $idCategorieE2 = (int) $db->query("SELECT id FROM product_categories WHERE slug = 'categorie-e2'")->fetchColumn();
    $post('/admin/categories/' . $idCategorieE2 . '/delete', []);
    $paginaCategorii = $cerere($port, 'GET', '/admin/categories', [], $jarRedir)['corp'];
    $post('/admin/seo/redirectionari/adauga', ['din' => '/categorie/categorie-e2', 'cod' => '410', 'sursa' => 'stergere', 'inapoi' => '/admin/categories']);
    $randCategorie = Redirectionari::dupaCheie($db, '/categorie/categorie-e2', false);
    $verifica('categoria ștearsă: propune lista produselor (/magazin; categoriile n-au părinte); aleasă 410, se salvează și adresa răspunde 410',
        str_contains($paginaCategorii, 'value="/categorie/categorie-e2"') && str_contains($paginaCategorii, 'value="/magazin"')
        && (int) ($randCategorie['code'] ?? 0) === 410 && (string) ($randCategorie['source'] ?? '') === 'stergere'
        && $cerereSimpla('/categorie/categorie-e2')['cod'] === 410);
} finally {
    $db->exec("DELETE FROM products WHERE slug LIKE 'produs-e2-%'");
    $db->exec("DELETE FROM product_categories WHERE slug = 'categorie-e2'");
    $db->exec("DELETE FROM seo_redirects WHERE source IN ('import', 'slug', '404', 'stergere', 'manual') AND (from_path LIKE '%e2%' OR note LIKE 'E2%')");
    $db->exec("UPDATE seo_redirects SET is_active = 1 WHERE source = 'pachet' AND from_path = '/produse'");
    $curataTest();
    Redirectionari::reconstruieste($db);
    \App\Support\ResponseCache::purgePageCache();
}
$verifica('după test: doar regulile pachetului demo, toate pornite', $randuriPachet() === $dinFisier()
    && (int) $db->query("SELECT COUNT(*) FROM seo_redirects WHERE source <> 'pachet' OR is_active = 0")->fetchColumn() === 0);
