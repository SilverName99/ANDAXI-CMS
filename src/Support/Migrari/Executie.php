<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use Closure;

/**
 * Starea unei singure rulări a unei migrări: ce i-a dat runner-ul lui Schema (simulare sau
 * nu, ce are voie, bugetul, cursorul lui peBucati) și ce strânge Schema (operațiile
 * planului, avertismentele, câte operații au schimbat ceva).
 *
 * Fără `readonly` (PHP 8.1): codul rulează și pe PHP-ul minim, 8.0.
 */
final class Executie
{
    /** Operațiile văzute (în simulare: planul; la aplicare: și cele fără efect), fiecare {clasa, fel, obiect, detaliu}. */
    public array $operatii = [];

    /** @var list<string> */
    public array $avertismente = [];

    /** Câte operații au schimbat ceva în baza de date la această rulare. */
    public int $cuEfect = 0;

    /** Cursorul lui peBucati: cheia apelului → ultima valoare a cheii primare făcută, sau „gata”. */
    public array $cursor = [];

    /** Al câtelea apel peBucati din migrare (cheia implicită a cursorului). */
    public int $apeluriBucati = 0;

    /** @var list<string> lacătele FAN luate (se eliberează la finalul migrării) */
    public array $lacateFan = [];

    /**
     * În simulare: structura tabelelor atinse de migrările de dinainte din același plan
     * (tabel → structură, null = tabel care nu va exista), comună tuturor migrărilor
     * planului, ca a doua să vadă ce creează prima.
     */
    public ?\ArrayObject $model = null;

    /**
     * @param Closure(): void $inainte chemat înaintea fiecărei operații care schimbă ceva (bugetul de timp)
     * @param Closure(int, ?array): void $progres o operație cu efect (+ cursorul nou, în tranzacția bucății)
     * @param Closure(): void $dupa chemat după fiecare operație cu efect (oprirea de test)
     * @param Closure(string, string, string, float): void $jurnal fel, obiect, clasa, ms
     * @param list<float> $pauze secunde de așteptare între reîncercări (lacăt, deadlock)
     */
    public function __construct(
        public string $id,
        public string $tip,
        public bool $simulare,
        public bool $poateBloca,
        public string $dosarSite,
        public Closure $inainte,
        public Closure $progres,
        public Closure $dupa,
        public Closure $jurnal,
        public array $pauze = [2.0, 4.0, 8.0, 16.0, 30.0],
        public int $pauzaBucatiMs = 50,
    ) {
    }
}
