<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Oferta campaniei (pluginul `campanii`, migrarea campanii/0058; docs/PLUGINURI.md, „Campanii pe site”, „Oferta campaniei”):
 * o reducere pe produsele alese ale campaniei active, socotită în coș (CheckoutCalculator::buildSummary), în bani întregi
 * (cenți), ca o reducere de preț pe liniile coșului: prețul liniei scade, deci subtotalul, TVA-ul, comanda, emailurile și
 * liniile trimise în ERP au prețul de după ofertă.
 *
 * Tipurile (`campanii.oferta_tip`):
 *  - `al_n` „Al N-lea la −Y%” (`oferta_n` = N ≥ 2, `oferta_procent` = Y): bucățile eligibile din coș se pun în ordinea
 *    prețului, de la cel mai scump (la preț egal, în ordinea liniilor din coș, apoi a bucăților), și se iau câte N la rând;
 *    în fiecare grupă întreagă, ultima bucată (cea mai ieftină a grupei) are Y% reducere. „1+1 −50%” = N 2, Y 50.
 *  - `n_la_m` „N la preț de M” (N > M ≥ 1): aceeași ordine și aceleași grupe de N; în fiecare grupă întreagă, ultimele N−M
 *    bucăți (cele mai ieftine) sunt gratuite. „3 la preț de 2” = N 3, M 2.
 *  - `procent` „−X%” (`oferta_procent` = X): fiecare linie eligibilă are X% reducere.
 *  - `pret_fix` „Preț de campanie”: fiecare produs are prețul lui de campanie (`campanii_produse.pret_campanie`); un preț
 *    lipsă sau nu mai mic decât prețul liniei (cel redus, dacă produsul are o reducere proprie) nu schimbă nimic.
 *
 * Rotunjirea: reducerea unei bucăți (al_n) sau a unei linii (procent) = preț în cenți × procent, rotunjit la cent (jumătatea
 * în sus); gratuitatea și prețul fix sunt exacte. Eligibile (OfertaCampanie::eligibila): liniile produselor campaniei; la
 * prețul de campanie, doar cele al căror preț e mai mare decât prețul de campanie (altfel linia nu e a ofertei: nicio reducere,
 * iar cuponul i se aplică, ca oricărui produs). Reducerile se adună: prețul unei bucăți e cel al liniei de dinainte de ofertă
 * (prețul redus, al perioadei în curs sau al lotului BBD ales, dacă are), de exemplu 100 lei redus la 90, cu −20%: 72 lei.
 */
final class OfertaCampanie
{
    public const TIPURI = [
        'al_n' => 'Al N-lea produs la −Y% (de exemplu 1+1 −50%)',
        'n_la_m' => 'N la preț de M (de exemplu 3 la preț de 2)',
        'procent' => 'Reducere de X% la produsele campaniei',
        'pret_fix' => 'Preț de campanie pe fiecare produs',
    ];

    /** Îndemnul de sub coș când mai lipsește o bucată pentru o grupă întreagă. */
    public const INDICIU = 'Mai adaugă un produs din ofertă ca să primești reducerea';

    /** @var array<string, mixed>|false|null */
    private static array|false|null $activa = false;

    /**
     * Oferta campaniei active, gata de calcul, sau null (nicio campanie, niciun tip, setări greșite, catalogul sau magazinul
     * nu e pe site). Citită o dată pe cerere.
     *
     * @return array{id: int, nume: string, tip: string, n: int, m: int, procent_bp: int, produse: array<int, ?int>, eticheta: string}|null
     */
    public static function activa(?PDO $db): ?array
    {
        if (self::$activa !== false) {
            return self::$activa;
        }
        self::$activa = null;
        if (!$db instanceof PDO || !Plugins::vizibil('catalog') || !Plugins::permiteInceput('magazin')) {
            return null;
        }
        $campanie = Campanii::activa($db);
        if ($campanie === null || !isset(self::TIPURI[(string) ($campanie['oferta_tip'] ?? '')])) {
            return null;
        }
        $produse = [];
        try {
            $stmt = $db->prepare('SELECT product_id, pret_campanie FROM campanii_produse WHERE campanie_id = :id');
            $stmt->execute(['id' => (int) $campanie['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rand) {
                $pret = $rand['pret_campanie'] ?? null;
                $produse[(int) $rand['product_id']] = $pret !== null && $pret !== '' && (float) $pret > 0
                    ? (int) round((float) $pret * 100) : null;
            }
        } catch (Throwable) {
            return null;
        }
        $oferta = self::dinCampanie($campanie, $produse);
        return self::$activa = $oferta;
    }

    /**
     * Oferta din rândul unei campanii (și produsele ei, id → prețul de campanie în cenți sau null), verificată; null dacă
     * setările nu fac o ofertă.
     *
     * @param array<string, mixed> $campanie
     * @param array<int, ?int> $produse
     * @return array{id: int, nume: string, tip: string, n: int, m: int, procent_bp: int, produse: array<int, ?int>, eticheta: string}|null
     */
    public static function dinCampanie(array $campanie, array $produse): ?array
    {
        $tip = (string) ($campanie['oferta_tip'] ?? '');
        $n = (int) ($campanie['oferta_n'] ?? 0);
        $m = (int) ($campanie['oferta_m'] ?? 0);
        $procentBp = (int) round((float) ($campanie['oferta_procent'] ?? 0) * 100);
        $buna = match ($tip) {
            'al_n' => $n >= 2 && $n <= 10 && $procentBp > 0 && $procentBp <= 10000,
            'n_la_m' => $n >= 2 && $n <= 10 && $m >= 1 && $m < $n,
            'procent' => $procentBp > 0 && $procentBp < 10000,
            'pret_fix' => true,
            default => false,
        };
        if (!$buna || $produse === []) {
            return null;
        }
        $oferta = ['id' => (int) ($campanie['id'] ?? 0), 'nume' => trim((string) ($campanie['nume'] ?? '')), 'tip' => $tip, 'n' => $n,
            'm' => $m, 'procent_bp' => $procentBp, 'produse' => $produse, 'eticheta' => ''];
        $oferta['eticheta'] = self::eticheta($oferta);
        return $oferta;
    }

    /** „1+1 −50%”, „Al 3-lea la −30%”, „3 la preț de 2”, „−20%”, „Preț de campanie”. */
    public static function eticheta(array $oferta): string
    {
        $procent = self::procentText((int) ($oferta['procent_bp'] ?? 0));
        $n = (int) ($oferta['n'] ?? 0);
        return match ((string) ($oferta['tip'] ?? '')) {
            'al_n' => $n === 2 ? '1+1 −' . $procent . '%' : 'Al ' . $n . '-lea la −' . $procent . '%',
            'n_la_m' => $n . ' la preț de ' . (int) ($oferta['m'] ?? 0),
            'procent' => '−' . $procent . '%',
            'pret_fix' => 'Preț de campanie',
            default => '',
        };
    }

    /**
     * O linie a coșului e a ofertei? Produsul e al campaniei, iar la prețul de campanie (`pret_fix`) doar cu un preț de campanie
     * mai mic decât prețul liniei (în cenți, cel de dinainte de ofertă): un preț lipsă, egal sau mai mare nu schimbă nimic, deci
     * linia rămâne în afara ofertei (și primește cuponul, ca oricare alta).
     *
     * @param array{tip: string, produse: array<int, ?int>} $oferta
     */
    public static function eligibila(array $oferta, int $idProdus, int $pretCenti): bool
    {
        if (!array_key_exists($idProdus, $oferta['produse'])) {
            return false;
        }
        if ($oferta['tip'] !== 'pret_fix') {
            return true;
        }
        $fix = $oferta['produse'][$idProdus];
        return $fix !== null && $fix > 0 && $fix < $pretCenti;
    }

    /**
     * Reducerile ofertei pe liniile eligibile ale coșului, în cenți.
     *
     * @param array{tip: string, n: int, m: int, procent_bp: int, produse: array<int, ?int>} $oferta
     * @param list<array{cheie: string, produs: int, pret: int, cantitate: int}> $linii prețul unei bucăți în cenți, în ordinea coșului
     * @return array{reduceri: array<string, int>, unitati: int, total: int, lipsesc: int}
     *   reduceri: cheia liniei → reducerea ei (doar > 0); unitati: bucățile eligibile; lipsesc: câte bucăți mai trebuie pentru
     *   prima grupă întreagă (al_n, n_la_m; 0 altfel sau când există deja una)
     */
    public static function calculeaza(array $oferta, array $linii): array
    {
        $reduceri = [];
        $unitati = 0;
        $bucati = [];
        foreach (array_values($linii) as $ordine => $linie) {
            $cantitate = max(0, (int) $linie['cantitate']);
            $pret = max(0, (int) $linie['pret']);
            $cheie = (string) $linie['cheie'];
            $unitati += $cantitate;
            switch ($oferta['tip']) {
                case 'procent':
                    $reduceri[$cheie] = ($reduceri[$cheie] ?? 0) + self::procentDin($pret * $cantitate, (int) $oferta['procent_bp']);
                    break;
                case 'pret_fix':
                    $fix = $oferta['produse'][(int) $linie['produs']] ?? null;
                    if ($fix !== null && $fix < $pret) {
                        $reduceri[$cheie] = ($reduceri[$cheie] ?? 0) + ($pret - $fix) * $cantitate;
                    }
                    break;
                default:
                    for ($i = 0; $i < $cantitate; $i++) {
                        $bucati[] = ['cheie' => $cheie, 'pret' => $pret, 'ordine' => $ordine, 'i' => $i];
                    }
            }
        }
        $lipsesc = 0;
        if ($oferta['tip'] === 'al_n' || $oferta['tip'] === 'n_la_m') {
            $n = (int) $oferta['n'];
            usort($bucati, static fn (array $a, array $b): int => [$b['pret'], $a['ordine'], $a['i']] <=> [$a['pret'], $b['ordine'], $b['i']]);
            $grupe = intdiv(count($bucati), $n);
            for ($g = 0; $g < $grupe; $g++) {
                $grupa = array_slice($bucati, $g * $n, $n);
                $reduse = $oferta['tip'] === 'al_n' ? 1 : $n - (int) $oferta['m'];
                foreach (array_slice($grupa, $n - $reduse) as $bucata) {
                    $valoare = $oferta['tip'] === 'al_n' ? self::procentDin($bucata['pret'], (int) $oferta['procent_bp']) : $bucata['pret'];
                    $reduceri[$bucata['cheie']] = ($reduceri[$bucata['cheie']] ?? 0) + $valoare;
                }
            }
            $lipsesc = $grupe === 0 && $unitati > 0 ? $n - $unitati : 0;
        }
        $reduceri = array_filter($reduceri, static fn (int $v): bool => $v > 0);
        return ['reduceri' => $reduceri, 'unitati' => $unitati, 'total' => array_sum($reduceri), 'lipsesc' => $lipsesc];
    }

    /** Îndemnul pentru bucățile care lipsesc dintr-o grupă (1 = textul cerut; mai multe = cu numărul lor). */
    public static function indiciu(int $lipsesc): string
    {
        return $lipsesc <= 0 ? '' : ($lipsesc === 1 ? self::INDICIU : 'Mai adaugă ' . $lipsesc . ' produse din ofertă ca să primești reducerea');
    }

    /** „Black Weekends: 1+1 −50%”. */
    public static function textSumar(array $oferta): string
    {
        $nume = trim((string) ($oferta['nume'] ?? ''));
        return ($nume !== '' ? $nume . ': ' : '') . (string) ($oferta['eticheta'] ?? '');
    }

    /** Doar pentru teste și după o salvare. */
    public static function reseteaza(): void
    {
        self::$activa = false;
    }

    /** Procentul (în sutimi de procent) dintr-o sumă în cenți, rotunjit la cent (jumătatea în sus). */
    public static function procentDin(int $centi, int $procentBp): int
    {
        return intdiv($centi * $procentBp + 5000, 10000);
    }

    private static function procentText(int $procentBp): string
    {
        return rtrim(rtrim(number_format($procentBp / 100, 2, ',', ''), '0'), ',');
    }
}
