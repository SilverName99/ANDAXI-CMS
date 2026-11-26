<?php

declare(strict_types=1);

namespace App\Support\MailPoetCompat;

/**
 * Parametrul `data` al adreselor MailPoet (pista E; docs/PLUGINURI.md, „Newsletter”, adresele vechi MailPoet): JSON codat base64
 * standard, fără `=` la sfârșit, necodat pentru URL (cum îl pune MailPoet în linkurile din emailurile deja trimise).
 *
 * Decodarea e chiar a lui MailPoet, pe aceleași funcții PHP: un `data` care e listă (`data[]=…`) dă `[]`; altfel
 * `json_decode(base64_decode($data), true)`, iar ce nu e un tablou dă `[]`. Așa ies la fel și detaliile: `+` din query devine spațiu,
 * `base64_decode` sare peste caracterele din afara alfabetului, umplutura e opțională. O singură extensie, declarată (VC-MP-07): dacă
 * prima decodare nu dă un tablou și `data` are spații, încă o încercare cu fiecare spațiu pus înapoi `+` (un `+` apare când `>`, `~`
 * sau `?` cad la marginea a trei octeți ai JSON-ului; MailPoet arăta atunci „nu avem o înregistrare despre tine”). Extensia nu
 * schimbă niciodată un rezultat pe care MailPoet îl decodează.
 */
final class Date
{
    /** @return array<mixed> */
    public static function decodeaza(mixed $data): array
    {
        if ($data === null || is_array($data)) {
            return [];
        }
        if (!is_string($data)) {
            $data = (string) (is_scalar($data) ? $data : '');
        }
        $r = json_decode(base64_decode($data), true);
        if (is_array($r)) {
            return $r;
        }
        if (str_contains($data, ' ')) {
            $r = json_decode(base64_decode(str_replace(' ', '+', $data)), true);
            if (is_array($r)) {
                return $r;
            }
        }
        return [];
    }

    /** Codarea lui MailPoet: `rtrim(base64_encode(json_encode($date)), '=')`, cu flagurile implicite ale lui json_encode. */
    public static function codifica(array $date): string
    {
        $json = json_encode($date);
        return $json === false ? '' : rtrim(base64_encode($json), '=');
    }
}
