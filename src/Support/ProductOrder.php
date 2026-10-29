<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ordinea produselor în listele de pe site (WP-19: C14b, din Biovitality 2b8a618; GR-08, din grafoanaytis 1ff9f88).
 *
 * O singură coloană, `products.sort_position` (NULL sau ≤ 0 = fără poziție), cu două folosiri:
 *
 *  - `aseaza()`: locul fix în listele care păstrează ordinea implicită a bazei (cel mai nou primul: prima pagină,
 *    produsele similare, {{products_by_template:…}}). 1 = primul din listă, 2 = al doilea și așa mai departe; cele
 *    fără poziție nu se amestecă între ele, doar se dau la o parte, păstrându-și ordinea. Exemplu: lista implicită e
 *    [D, C, B, A], iar A primește poziția 2 → [D, A, C, B]. Fără nicio poziție, lista rămâne exact cum era;
 *  - `dupaCatalog()`: sortarea „Ordinea din catalog” a catalogului (catalog_sortare_implicita = catalog): întâi cele
 *    cu poziție, crescător, apoi cele fără, alfabetic; la aceeași poziție, alfabetic.
 */
final class ProductOrder
{
    /** Cheia după care se citește poziția din rândul de produs. */
    private const CAMP = 'sort_position';

    /**
     * @param list<array<string, mixed>> $produse în ordinea implicită
     * @return list<array<string, mixed>>
     */
    public static function aseaza(array $produse): array
    {
        $produse = array_values($produse);
        $total = count($produse);
        if ($total === 0) {
            return [];
        }

        $asezate = [];
        $libere = [];
        foreach ($produse as $index => $produs) {
            $pozitie = self::pozitie($produs);
            if ($pozitie === null) {
                $libere[] = $produs;
                continue;
            }
            // Indexul implicit rupe egalitatea: la aceeași poziție cerută,
            // câștigă produsul care venea oricum mai devreme.
            $asezate[] = ['pozitie' => $pozitie, 'index' => $index, 'produs' => $produs];
        }

        if ($asezate === []) {
            return $produse;
        }

        usort($asezate, static fn (array $a, array $b): int
            => [$a['pozitie'], $a['index']] <=> [$b['pozitie'], $b['index']]);

        $rezultat = array_fill(0, $total, null);
        foreach ($asezate as $intrare) {
            $tinta = min($intrare['pozitie'] - 1, $total - 1);
            // Locul cerut poate fi deja luat de un produs cu aceeași poziție;
            // atunci îl punem pe primul liber de după.
            while ($tinta < $total && $rezultat[$tinta] !== null) {
                $tinta++;
            }
            if ($tinta >= $total) {
                // Nu mai e loc după; căutăm înapoi primul gol rămas.
                $tinta = array_search(null, $rezultat, true);
                if ($tinta === false) {
                    break;
                }
            }
            $rezultat[$tinta] = $intrare['produs'];
        }

        foreach ($rezultat as $i => $valoare) {
            if ($valoare === null) {
                $rezultat[$i] = array_shift($libere);
            }
        }

        // Dacă au rămas produse nefolosite (nu se poate în mod normal, dar nu
        // vrem să pierdem niciunul), le punem la coadă.
        return array_values(array_merge(array_filter($rezultat, static fn ($p): bool => $p !== null), $libere));
    }

    /**
     * Sortarea „Ordinea din catalog”: poziția crescător (cele fără poziție la urmă), apoi numele, apoi id-ul (cel mai
     * nou primul), ca ordinea să fie aceeași la fiecare cerere.
     *
     * @param list<array<string, mixed>> $produse
     * @return list<array<string, mixed>>
     */
    public static function dupaCatalog(array $produse): array
    {
        usort($produse, static function (array $stanga, array $dreapta): int {
            $ps = self::pozitie($stanga);
            $pd = self::pozitie($dreapta);
            if ($ps !== $pd) {
                if ($ps === null) {
                    return 1;
                }
                if ($pd === null) {
                    return -1;
                }
                return $ps <=> $pd;
            }
            $cmp = strcasecmp(trim((string) ($stanga['name'] ?? '')), trim((string) ($dreapta['name'] ?? '')));
            return $cmp !== 0 ? $cmp : ((int) ($dreapta['id'] ?? 0) <=> (int) ($stanga['id'] ?? 0));
        });
        return array_values($produse);
    }

    /** Poziția cerută pentru un produs, sau null dacă îl lăsăm unde e. */
    public static function pozitie(array $produs): ?int
    {
        $brut = $produs[self::CAMP] ?? null;
        if ($brut === null || $brut === '' || !is_numeric((string) $brut)) {
            return null;
        }
        $pozitie = (int) $brut;

        return $pozitie > 0 ? $pozitie : null;
    }

    /** Valoarea de salvat dintr-un câmp de formular: un număr ≥ 1, altfel NULL (fără poziție). */
    public static function dinFormular(mixed $valoare): ?int
    {
        $pozitie = max(0, (int) (is_scalar($valoare) ? $valoare : 0));
        return $pozitie > 0 ? $pozitie : null;
    }
}
