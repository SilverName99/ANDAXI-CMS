<?php

declare(strict_types=1);

namespace App\Support\CadruCapturat;

/**
 * Jetoanele `{{ax:…}}` ale paginilor capturate (adoptarea unui site WordPress, pașii 5a și 6; DECIZIE §2.3 #5, I-C §6): locurile
 * din HTML-ul capturat pe care le completează randarea (fragmentele, scripturile runtime-ului, nonce-ul formularului, elementul
 * curent din meniu, numărul formularului din subsol, calea cererii, anul, valorile SEO, datele comportamentului).
 *
 * Forma: `{{ax:<nume>}}` sau `{{ax:<nume>|<escapare>}}`, cu numele din litere mici, cifre și `_ . : -` și escaparea locului
 * (`html` în text, `attr` într-o valoare de atribut, `json` într-un șir JSON). Reguli (fail-closed):
 *  - o captură care conține deja `{{ax:` (oricum scris) e NU la import (literal()): un text al paginii n-ar trebui luat drept jeton;
 *  - randarea înlocuiește jetoanele într-o singură trecere (rezolva()): o valoare pusă nu se mai citește o dată, iar valorile cu
 *    escapare au `{` scăpat, deci nu pot face un jeton nou;
 *  - un jeton necunoscut sau un `{{ax:` rămas după înlocuire e o eroare (EroareJeton): cadrul răspunde 500 și scrie în jurnal,
 *    niciodată o pagină pe jumătate.
 */
final class Jetoane
{
    /** Un jeton: numele (grupul 1) și escaparea (grupul 2, opțională). */
    public const RE = '/\{\{ax:([a-z0-9_.:-]{1,160})(?:\|(html|attr|json))?\}\}/';

    /** Felurile de escapare. */
    public const ESCAPARI = ['html', 'attr', 'json'];

    /** Poziția primului `{{ax:` (fără deosebire de litere mari) din text, sau null. */
    public static function literal(string $text): ?int
    {
        $p = stripos($text, '{{ax:');
        return $p === false ? null : $p;
    }

    /** Un jeton scris (`{{ax:nume}}` sau `{{ax:nume|escapare}}`), cu numele verificat. */
    public static function jeton(string $nume, ?string $escapare = null): string
    {
        $j = '{{ax:' . $nume . ($escapare !== null ? '|' . $escapare : '') . '}}';
        if (preg_match(self::RE, $j, $m) !== 1 || $m[0] !== $j) {
            throw new \InvalidArgumentException('nume de jeton nepermis: ' . $nume);
        }
        return $j;
    }

    /**
     * Jetoanele unui text, în ordine: `{start, end, nume, escapare}`.
     *
     * @return list<array{start: int, end: int, nume: string, escapare: ?string}>
     */
    public static function gaseste(string $text): array
    {
        if (!str_contains($text, '{{ax:')) {
            return [];
        }
        preg_match_all(self::RE, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $rez = [];
        foreach ($m as $x) {
            $rez[] = ['start' => $x[0][1], 'end' => $x[0][1] + strlen($x[0][0]), 'nume' => $x[1][0], 'escapare' => isset($x[2]) && $x[2][1] >= 0 ? $x[2][0] : null];
        }
        return $rez;
    }

    /**
     * Escaparea unei valori pentru locul ei. `html`/`attr`: ca esc_html/esc_attr (`& < > " '` în entități, `'` ca `&#039;`);
     * `json`: conținutul unui șir JSON ca wp_json_encode (bara oblică scăpată, Unicode ca `\uXXXX`), cu `<`, `>` și `&` în
     * `<` / `>` / `&`. În toate, `{` devine `&#123;` (sau `{`), deci o valoare nu poate face un jeton.
     */
    public static function scapa(string $valoare, ?string $escapare): string
    {
        return match ($escapare) {
            'html', 'attr' => str_replace('{', '&#123;', htmlspecialchars($valoare, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8')),
            'json' => str_replace('{', '\\u007B', substr((string) json_encode($valoare, JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1)),
            null => $valoare,
            default => throw new \InvalidArgumentException('escapare necunoscută: ' . $escapare),
        };
    }

    /**
     * Înlocuiește jetoanele într-o singură trecere. `$valori` dă, pentru numele unui jeton, valoarea lui (nescăpată: escaparea
     * o face locul, din jeton) sau null pentru un jeton necunoscut. Cele fără escapare primesc valoarea exact cum vine (fragmente,
     * scripturile runtime-ului, clasele meniului, JSON-ul datelor): doar codul nostru le dă.
     *
     * @param callable(string): ?string $valori
     * @throws EroareJeton jeton necunoscut sau `{{ax:` rămas
     */
    public static function rezolva(string $sablon, callable $valori): string
    {
        $necunoscute = [];
        $rez = (string) preg_replace_callback(self::RE, static function (array $m) use ($valori, &$necunoscute): string {
            $v = $valori($m[1]);
            if ($v === null) {
                $necunoscute[] = $m[0];
                return '';
            }
            return self::scapa($v, isset($m[2]) && $m[2] !== '' ? $m[2] : null);
        }, $sablon);
        if ($necunoscute !== []) {
            throw new EroareJeton('jetoane necunoscute: ' . implode(', ', array_slice(array_unique($necunoscute), 0, 5)));
        }
        if (($p = self::literal($rez)) !== null) {
            throw new EroareJeton('a rămas „{{ax:” după înlocuire, la octetul ' . $p);
        }
        return $rez;
    }
}
