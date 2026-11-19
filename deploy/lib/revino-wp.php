<?php

declare(strict_types=1);

/**
 * Revenirea unui site adoptat din WordPress (deploy/adopta-wp.sh) la WordPress: deploy/revino.sh o alege singur când site-ul
 * are site/deploy/adopta-wp.stare (docs/DEPLOY.md, „Adoptarea unui site WordPress”, revenirea). Mai simplă decât cea a unui
 * site vechi al platformei: baza WordPress n-a fost scrisă niciodată (importul doar a citit-o), iar pozele au fost copiate,
 * deci nu se pune nimic la loc din copii. Pașii, cu --aplica:
 *
 *   1. adoptarea oprită înainte de comutare: se șterge doar public_html.nou (WordPress n-a fost atins);
 *   2. după comutare: MENTENANTA strictă (site-ul nou nu mai primește nimic), public_html (site-ul nou) →
 *      public_html.esuat-<data>, public_html.vechi (WordPress) → public_html, fiecare redenumire ținută minte;
 *   3. WordPress comparat cu amprenta lui de la comutare (fiecare fișier; la poze mărimea și data);
 *   4. MENTENANTA ștearsă, prima pagină WordPress verificată.
 *
 * Baza nouă rămâne cum e (cu ce s-a scris în ea după deschidere: arătat în plan); o adoptare nouă o folosește din nou.
 */

namespace Platforma\Deploy;

require_once __DIR__ . '/wordpress.php';

function revinoWp(Optiuni $o, Site $s, bool $aplica, string $url): int
{
    $stare = new Stare($s->lucru . '/adopta-wp.stare');
    Iesire::titlu('Revenirea site-ului ' . $s->domeniu . ' la WordPress' . ($aplica ? '' : ' — PLAN'));
    $act = new Stare($s->lucru . '/actualizare.stare');
    if (is_file($act->fisier) && !$act->ia('gata', false) && !$act->ia('intoarsa', false)) {
        $spre = (string) $act->ia('versiune_noua', '?');
        throw new Oprire('o actualizare spre ' . $spre . ' a rămas neterminată (' . $act->fisier . '); o termini întâi, apoi revii', 2, [
            'aceeași comandă de actualizare: deploy/actualizeaza.sh --site=' . $s->domeniu . ' --versiune=' . $spre . ' --aplica',
        ]);
    }
    $rev = (array) $stare->ia('revino', []);
    if (!empty($rev['gata'])) {
        Iesire::ok('site-ul e deja pe WordPress (revenirea s-a făcut ' . $rev['gata'] . ')');
        return 0;
    }
    Mentenanta::avertizeazaVeche($s);
    if ($aplica) {
        Iesire::jurnal($s->lucru . '/jurnale/revino-wp-' . date('Ymd-His') . '.log');
        Redenumiri::termina($stare, $o->are('rezolvat-de-mana'), true);
    }
    $facute = (array) $stare->ia('redenumiri_facute', []);
    $comutat = in_array('comutare', $facute, true) || isset($stare->date['redenumiri']['comutare']) || (bool) $stare->ia('comutat', false);

    if (!$comutat) {
        if (!WordPress::este($s->docroot)) {
            throw new Oprire('starea spune că site-ul nu a fost comutat, dar în ' . $s->docroot . ' nu e WordPress; nu schimb nimic (ne trimiți ls -la ' . $s->dosar . ')', 1);
        }
        Iesire::info('adoptarea s-a oprit înainte de comutare: WordPress e încă în ' . $s->docroot . ', neatins');
        if (!$aplica) {
            Iesire::scrie('PLAN: aș șterge doar ' . $s->nou . ' (pregătirea) și aș încheia adoptarea începută; baza nouă și site/ rămân. Cu --aplica o face.');
            return 0;
        }
        if (is_dir($s->nou) && (is_file($s->nou . '/.andaxi-nou') || !empty($rev['sterge_nou']))) {
            $rev['sterge_nou'] = $rev['sterge_nou'] ?? date('c');
            $stare->pune('revino', $rev);
            @unlink($s->nou . '/.andaxi-nou');
            testOpreste('revino-sterge-nou');
            Fisiere::sterge($s->nou, [$s->dosar]);
            Iesire::ok('șters ' . $s->nou);
        }
        $rev['gata'] = date('c');
        $stare->pune('revino', $rev);
        Mentenanta::scoate($s);
        Iesire::titlu('GATA');
        Iesire::scrie('Adoptarea începută s-a încheiat; site-ul ' . $s->domeniu . ' a rămas pe WordPress.');
        return 0;
    }

    // Ce s-ar „pierde”: nimic din WordPress; din site-ul nou, ce s-a scris după import (rămâne în baza nouă, doar nu se mai vede).
    $dupa = json_decode((string) @file_get_contents((string) $stare->ia('copii') . '/baza-noua-dupa-import.json'), true);
    if (is_array($dupa)) {
        try {
            $schimbate = (new Baza(Env::citeste($s->site . '/.env')))->tabeleSchimbate((array) ($dupa['amprenta'] ?? []));
            Iesire::info($schimbate === [] ? 'baza nouă e cum a lăsat-o importul (nimic scris de atunci)'
                : 'în baza nouă s-a scris după import (' . implode(', ', array_slice($schimbate, 0, 8)) . '): rămâne acolo, dar WordPress nu le vede '
                    . '(de exemplu mesajele din formularul de contact; au plecat oricum pe email)');
        } catch (\Throwable) {
            Iesire::info('baza nouă nu răspunde (nu contează pentru revenire)');
        }
    }
    $esuat = (string) ($rev['esuat'] ?? ($s->dosar . '/public_html.esuat-' . date('Ymd-His')));
    if (!$aplica) {
        Iesire::info('aș pune MENTENANTA, apoi ' . $s->docroot . ' → ' . $esuat . ' și ' . $s->vechi . ' → ' . $s->docroot . ' (WordPress, cum era)');
        Iesire::scrie('PLAN: nimic schimbat. Revenirea (un minut): aceeași comandă cu --aplica.');
        return 0;
    }
    if (!in_array('revino-cod', $facute, true)) {
        if ($s->cuStub()) {
            Mentenanta::pune($s, (string) ($stare->ia('jeton') ?: bin2hex(random_bytes(16))), true);
        }
        $rev['esuat'] = $esuat;
        $rev['inceputa'] = $rev['inceputa'] ?? date('c');
        $stare->pune('revino', $rev);
        Redenumiri::fa($stare, 'revino-cod', [[$s->docroot, $esuat], [$s->vechi, $s->docroot]]);
        Iesire::ok('WordPress e din nou în ' . $s->docroot . ' (site-ul nou în ' . $esuat . ')');
    }
    // WordPress, comparat cu amprenta lui de la comutare.
    $amprenta = (string) $stare->ia('copii') . '/wordpress.amprenta';
    if (is_file($amprenta)) {
        [$schimbate, $poze] = Fisiere::diferente((string) file_get_contents($amprenta),
            Fisiere::amprenta($s->docroot, ['wp-content/uploads', 'wp-content/cache', 'wp-content/et-cache']), 'wp-content/uploads');
        if ($schimbate === [] && $poze === []) {
            Iesire::ok('WordPress e octet cu octet cum era la comutare');
        } else {
            Iesire::atentie('WordPress diferă de amprenta de la comutare: ' . implode(', ', array_slice(array_merge($schimbate, $poze), 0, 8))
                . (count($schimbate) + count($poze) > 8 ? ', …' : '') . ' (ne trimiți rândul acesta)');
        }
    }
    Mentenanta::scoate($s);
    $r = Http::cere($url . '/', ['Cache-Control' => 'no-cache']);
    if ($r['cod'] === 200 && WordPress::pagina($r['corp'])) {
        Iesire::ok('WordPress răspunde la ' . $url . '/ (200)');
    } else {
        Iesire::atentie('prima pagină răspunde HTTP ' . $r['cod'] . (WordPress::pagina($r['corp']) ? '' : ' și nu pare WordPress') . ': verifici în browser');
    }
    $rev['gata'] = date('c');
    $stare->pune('revino', $rev);
    $stare->pune('comutat', false);
    Iesire::titlu('GATA');
    Iesire::scrie('Site-ul ' . $s->domeniu . ' e din nou pe WordPress. Site-ul nou a rămas în ' . $esuat . ', baza lui neatinsă; o adoptare nouă pornește de la început.');
    Iesire::scrie('Dacă ai schimbat cronurile în hPanel, pune-l la loc pe cel al WordPress (wp-cron.php).');
    return 0;
}
