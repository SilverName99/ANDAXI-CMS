<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\Captura;

/**
 * Un răspuns HTTP al capturii (Cereri::get): adresa, UA-ul, statusul, antetele în ordinea lor (și blocul brut), corpul
 * decodat (după `Content-Encoding`, ca în browser) cu octeții lui exacți, codarea de pe fir și câte încercări a cerut.
 */
final class Raspuns
{
    /**
     * @param list<array{0: string, 1: string}> $antete numele cu litere mici și valoarea, în ordinea primită
     */
    public function __construct(
        public readonly string $url,
        public readonly string $ua,
        public readonly int $status,
        public readonly array $antete,
        public readonly string $anteteBrute,
        public readonly string $corp,
        public readonly string $codare = '',
        public readonly float $durata = 0.0,
        public readonly int $incercari = 1,
    ) {
    }

    /** Prima valoare a unui antet (numele fără deosebire de litere mari), sau null. */
    public function antet(string $nume): ?string
    {
        $nume = strtolower($nume);
        foreach ($this->antete as [$n, $v]) {
            if ($n === $nume) {
                return $v;
            }
        }
        return null;
    }

    /** Tipul conținutului, fără parametri, cu litere mici (`text/html`). */
    public function tip(): string
    {
        return strtolower(trim(explode(';', (string) $this->antet('content-type'))[0]));
    }

    public function sha256(): string
    {
        return hash('sha256', $this->corp);
    }

    /**
     * Blocul de antete de păstrat lângă corp (`.antete`): fără cele care descriu octeții de pe fir sau legătura
     * (Cereri::ANTETE_FIR: Content-Encoding, Content-Length, Transfer-Encoding, Connection, Keep-Alive), fiindcă corpul păstrat e
     * cel decodat; pasul 9a le recalculează. `scoase`: rândurile scoase, ca să rămână știute (în index.json).
     *
     * @return array{bloc: string, scoase: list<string>}
     */
    public function anteteDePastrat(): array
    {
        $bloc = '';
        $scoase = [];
        foreach (preg_split('/(?<=\n)/', $this->anteteBrute) ?: [] as $linie) {
            if ($linie === '') {
                continue;
            }
            $nume = strtolower(trim((string) strstr($linie, ':', true)));
            if ($nume !== '' && preg_match('#^HTTP/#i', $linie) !== 1 && in_array($nume, Cereri::ANTETE_FIR, true)) {
                $scoase[] = rtrim($linie, "\r\n");
                continue;
            }
            $bloc .= $linie;
        }
        return ['bloc' => $bloc, 'scoase' => $scoase];
    }
}
