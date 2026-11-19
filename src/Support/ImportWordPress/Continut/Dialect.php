<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Continut;

/**
 * Dialectul unei capturi (adoptarea unui site WordPress pe drumul capturat, pasul 5a; DECIZIE §2.3 #5–#9): ce face din HTML-ul
 * capturat documentul pe care îl servește site-ul nou, T(captura), doar prin editări pe intervale de octeți (Html5\Editare),
 * fiecare dintr-o listă închisă, declarată (§6.7). Pe lângă Convertor (planul importului), un dialect dă:
 *  - transforma(): T(captura), lista editărilor (felul, intervalul din captură, textul nou), datele comportamentului (blocul
 *    `ax-date`, împărțit pe proprietar), scripturile aruncate (sha256 după normalizare) și pozițiile sloturilor runtime-ului;
 *    un script, un handler sau o construcție necunoscută înseamnă NU (`probleme`), nu o scoatere în tăcere.
 * Tăierea în cele cinci bucăți, fragmentele și jetoanele (`{{ax:…}}`) le adaugă scrierea capturată, peste același rezultat.
 */
interface Dialect
{
    /** Numele dialectului (`divi4`), cum îl scrie pachetul în `import-wordpress.json` → `dialect`. */
    public function dialect(): string;

    /**
     * @param string $html captura brută (octeții exacți ai răspunsului de referință)
     * @param string $urlSite adresa site-ului capturat (originea adreselor proprii)
     * @param array<string, mixed> $reguli regulile pachetului (`scripturi_aruncate`, `captura.nonce`)
     */
    public function transforma(string $html, string $urlSite, array $reguli): RezultatDialect;
}
