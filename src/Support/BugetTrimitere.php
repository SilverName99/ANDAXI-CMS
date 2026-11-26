<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bugetul de trimiteri al unei treceri a cronului newsletterului (scripts/newsletter-campaigns.php): câte emailuri de
 * newsletter pot pleca, cel mult, într-o rulare, și până când. Campaniile trimit întâi (ca până acum, cu limita lor pe
 * campanie); ce au trimis se scade, iar automatizările (App\Support\Automatizari\Motor) primesc doar ce a rămas. Așa un
 * flux nu poate trece peste limita pe care furnizorul de email o are deja pentru campanii.
 */
final class BugetTrimitere
{
    private int $folosite = 0;

    /** @param int $total câte trimiteri, cel mult; @param int|null $termen ora (time()) după care nu mai pleacă nimic */
    public function __construct(private int $total, private ?int $termen = null)
    {
        $this->total = max(0, $total);
    }

    public function consuma(int $cate = 1): void
    {
        $this->folosite += max(0, $cate);
    }

    public function ramase(): int
    {
        return max(0, $this->total - $this->folosite);
    }

    public function folosite(): int
    {
        return $this->folosite;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function timpExpirat(): bool
    {
        return $this->termen !== null && time() >= $this->termen;
    }

    /** Nu mai poate pleca nimic în trecerea asta (bugetul folosit sau timpul terminat). */
    public function epuizat(): bool
    {
        return $this->ramase() <= 0 || $this->timpExpirat();
    }
}
