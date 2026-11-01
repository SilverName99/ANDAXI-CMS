<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Sursa comenzilor (WP-40, SEO partea B, B9; PLAN-SEO D9): de unde a venit clientul care a comandat, salvat pe comandă
 * (`orders.sursa`, magazin/0051), și raportul săptămânal din admin (Rapoarte → Sursa comenzilor). Google Analytics numără
 * doar vizitatorii care acceptă cookie-urile; comenzile le avem pe toate.
 *
 * La intrarea pe site (o pagină publică cerută cu GET, `laIntrare`), felul vizitei se ține într-un cookie
 * (`ax_sursa`, 30 de zile; doar felul, niciun id, nicio adresă), suprascris de o vizită nouă venită de undeva (ultima
 * sursă care nu e „direct” câștigă, ca în Google Analytics):
 *  - `google_ads`: un link de reclamă Google (gclid, gbraid, wbraid) sau o campanie plătită cu utm_source=google;
 *  - `email`: utm_medium email/newsletter (newsletterul pune și cookie-ul nl_cid);
 *  - `campanie`: alt link cu utm_source (o rețea socială, un partener);
 *  - `google`: venit din căutarea Google (antetul Referer), fără reclamă;
 *  - `alt_site`: venit de pe alt site (altă căutare, un link);
 *  - fără nimic de mai sus: nimic scris (rămâne ce era; fără cookie, la comandă = `direct`).
 * La comandă (`pentruComanda`): cookie-ul bv_gclid (reclama Google, deja citit pentru `ad_source`) sau nl_cid (o campanie
 * de newsletter) au întâietate, apoi `ax_sursa`, altfel `direct`.
 *
 * Comutatorul: setarea `sursa_comenzii` (implicit pornită; înghețată la „0” pe site-ul înghețat până o vede pe site-ul de
 * verificare). Oprită: niciun cookie nou, comanda fără sursă.
 */
final class SursaComenzii
{
    public const COOKIE = 'ax_sursa';
    public const SURSE = [
        'google' => 'Google (căutare)',
        'google_ads' => 'Reclamă Google',
        'email' => 'Email / newsletter',
        'campanie' => 'Campanie (alt link cu utm)',
        'alt_site' => 'Alt site',
        'direct' => 'Direct',
    ];
    private const ZILE = 30;

    /** @param array<string, mixed> $setari */
    public static function pornita(array $setari): bool
    {
        return (string) ($setari['sursa_comenzii'] ?? '1') === '1';
    }

    /**
     * Felul vizitei de acum din adresă și Referer (null = nimic de spus: o pagină cerută din site sau direct).
     *
     * @param array<string, mixed> $get
     */
    public static function dinCerere(array $get, string $referer, string $gazdaSite): ?string
    {
        $param = static fn (string $cheie): string => is_string($get[$cheie] ?? null) ? strtolower(trim((string) $get[$cheie])) : '';
        foreach (['gclid', 'gbraid', 'wbraid'] as $cheie) {
            if ($param($cheie) !== '') {
                return 'google_ads';
            }
        }
        $mediu = $param('utm_medium');
        $sursaUtm = $param('utm_source');
        if (in_array($mediu, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'sem'], true) && str_contains($sursaUtm, 'google')) {
            return 'google_ads';
        }
        if (in_array($mediu, ['email', 'e-mail', 'newsletter'], true) || $sursaUtm === 'newsletter') {
            return 'email';
        }
        if ($sursaUtm !== '' || $mediu !== '') {
            return 'campanie';
        }
        $gazda = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($gazda === '') {
            return null;
        }
        $faraWww = static fn (string $g): string => str_starts_with($g, 'www.') ? substr($g, 4) : $g;
        if ($faraWww($gazda) === $faraWww(strtolower($gazdaSite))) {
            return null;
        }
        if (preg_match('/(^|\.)google\.[a-z.]{2,8}$/', $gazda) === 1) {
            return 'google';
        }
        return 'alt_site';
    }

    /**
     * La începutul unei cereri publice GET: scrie cookie-ul cu felul vizitei, când e unul (doar paginile, nu adminul,
     * API-ul, fișierele sau confirmările).
     *
     * @param array<string, mixed> $setari
     */
    public static function laIntrare(array $setari): void
    {
        if (PHP_SAPI === 'cli' || !self::pornita($setari) || headers_sent()
            || !in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
            return;
        }
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        if (SeoRedirects::eExclusa($cale) || preg_match('#\.(css|js|png|jpe?g|webp|gif|svg|ico|xml|txt|woff2?)$#i', $cale) === 1) {
            return;
        }
        $sursa = self::dinCerere($_GET, (string) ($_SERVER['HTTP_REFERER'] ?? ''), (string) parse_url(AppUrl::base(), PHP_URL_HOST));
        if ($sursa === null || ($_COOKIE[self::COOKIE] ?? '') === $sursa) {
            return;
        }
        $peHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie(self::COOKIE, $sursa, ['expires' => time() + 86400 * self::ZILE, 'path' => '/', 'samesite' => 'Lax', 'secure' => $peHttps,
            'httponly' => true]);
        $_COOKIE[self::COOKIE] = $sursa;
    }

    /**
     * Sursa unei comenzi plasate acum, din cookie-urile cererii.
     *
     * @param array<string, mixed> $cookie
     */
    public static function pentruComanda(array $cookie): string
    {
        if (trim((string) ($cookie['bv_gclid'] ?? '')) !== '') {
            return 'google_ads';
        }
        if ((int) ($cookie['nl_cid'] ?? 0) > 0) {
            return 'email';
        }
        $sursa = (string) ($cookie[self::COOKIE] ?? '');
        return isset(self::SURSE[$sursa]) ? $sursa : 'direct';
    }

    /** Scrie sursa pe comanda nou creată (fără coloană: nimic). */
    public static function salveaza(PDO $db, int $idComanda, array $setari): void
    {
        if ($idComanda <= 0 || !self::pornita($setari)) {
            return;
        }
        try {
            $db->prepare('UPDATE orders SET sursa = ? WHERE id = ?')->execute([self::pentruComanda($_COOKIE), $idComanda]);
        } catch (Throwable) {
            // Fără magazin/0051 încă: comanda e salvată, sursa vine după migrare.
        }
    }

    /**
     * Raportul: comenzile (neșterse) pe săptămâni (luni–duminică) și pe surse, din ultimele $saptamani săptămâni: numărul
     * și valoarea. Fără niciun câmp al clientului.
     *
     * @return array{saptamani: list<array{de_la: string, pana_la: string, surse: array<string, array{numar: int, valoare: float}>, total: int}>,
     *               totaluri: array<string, array{numar: int, valoare: float}>, total: int, coloana: bool}
     */
    public static function raport(PDO $db, int $saptamani = 12, ?int $acum = null): array
    {
        $acum ??= time();
        $saptamani = max(1, min(104, $saptamani));
        $luni = strtotime('monday this week', $acum) ?: $acum;
        $start = strtotime('-' . ($saptamani - 1) . ' weeks', $luni) ?: $luni;
        $chei = array_merge(array_keys(self::SURSE), ['necunoscuta']);
        $gol = array_fill_keys($chei, ['numar' => 0, 'valoare' => 0.0]);
        $rez = ['saptamani' => [], 'totaluri' => $gol, 'total' => 0, 'coloana' => true];
        for ($i = $saptamani - 1; $i >= 0; $i--) {
            $deLa = strtotime('+' . $i . ' weeks', $start) ?: $start;
            $rez['saptamani'][date('Y-m-d', $deLa)] = ['de_la' => date('Y-m-d', $deLa), 'pana_la' => date('Y-m-d', $deLa + 6 * 86400),
                'surse' => $gol, 'total' => 0];
        }
        try {
            $stmt = $db->prepare("SELECT DATE(created_at - INTERVAL WEEKDAY(created_at) DAY) AS luni, COALESCE(NULLIF(sursa, ''), 'necunoscuta') AS sursa,
                COUNT(*) AS numar, COALESCE(SUM(total), 0) AS valoare
                FROM orders WHERE deleted_at IS NULL AND created_at >= ? GROUP BY luni, sursa");
            $stmt->execute([date('Y-m-d 00:00:00', $start)]);
            $randuri = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $rez['coloana'] = false;
            $randuri = [];
        }
        foreach ($randuri as $r) {
            $sapt = (string) $r['luni'];
            $sursa = isset($gol[(string) $r['sursa']]) ? (string) $r['sursa'] : 'necunoscuta';
            if (!isset($rez['saptamani'][$sapt])) {
                continue;
            }
            $numar = (int) $r['numar'];
            $valoare = round((float) $r['valoare'], 2);
            $rez['saptamani'][$sapt]['surse'][$sursa]['numar'] += $numar;
            $rez['saptamani'][$sapt]['surse'][$sursa]['valoare'] += $valoare;
            $rez['saptamani'][$sapt]['total'] += $numar;
            $rez['totaluri'][$sursa]['numar'] += $numar;
            $rez['totaluri'][$sursa]['valoare'] += $valoare;
            $rez['total'] += $numar;
        }
        $rez['saptamani'] = array_values(array_reverse($rez['saptamani']));
        return $rez;
    }
}
