<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Codul care poate rula în browser dintr-un text scris în admin (revizuirea de securitate din 18 noiembrie 2026, N1b;
 * docs/SECURITATE.md, „Codul activ”). Doar administratorul general îl poate salva: șabloanele, articolele, produsele și
 * newsletterele se văd pe aceeași origine ca adminul, deci un script pus de un rol parțial ar rula cu sesiunea
 * administratorului general care deschide pagina.
 *
 * „Părțile” unui text sunt bucățile lui care pot rula:
 *  - `script:` un element `<script>` întreg (atributele și conținutul, până la `</script`);
 *  - `on:` un atribut al cărui nume începe cu „on” (onclick, onerror, onload…), cu valoarea lui decodată;
 *  - `url:` un atribut a cărui valoare, decodată (entitățile `&#106;`, `&#106` fără „;”, `&colon;`) și fără spații sau
 *    caractere de control, conține `javascript:` sau `vbscript:` (href, src, action, formaction, xlink:href, values,
 *    content…: oricare);
 *  - `srcdoc:` un atribut `srcdoc` (documentul din el are originea paginii);
 *  - `base:` un element `<base>` (ar muta scripturile paginii, cu adrese relative, pe alt site);
 *  - `js:` un câmp care e în întregime JavaScript (`js_content` al unui șablon), dacă nu e gol.
 *
 * Citirea e mai largă decât a browserului, nu mai îngustă: o etichetă se citește de la fiecare „<literă”, oriunde ar
 * fi (și în comentarii, în `<textarea>`, `<style>` sau `<noscript>`), atributele după regulile tokenizerului HTML
 * (ghilimele, „/”, atribute lipite, o etichetă neînchisă până la sfârșit), iar valorile atributelor se citesc încă o
 * dată, ca HTML (o etichetă ascunsă într-un atribut, de exemplu `<noscript><p title="</noscript><img onerror=…>">`).
 * Un text de proză nu are părți (`&lt;script&gt;` scris ca exemplu nu e cod).
 *
 * Fără dependențe (doar funcțiile de șiruri ale PHP-ului).
 */
final class CodActiv
{
    /** Cât de adânc se recitesc valorile atributelor (o etichetă într-un atribut al unei etichete dintr-un atribut…). */
    private const ADANCIME_MAXIMA = 3;
    /** Peste atâția octeți textul nu se mai împarte: dacă are ceva ce seamănă a cod, întreg e o singură parte. */
    private const OCTETI_MAXIMI = 8_000_000;
    /** Spațiile din HTML (CR intră și el: browserul îl face LF înainte de tokenizare). */
    private const SPATII = " \t\n\f\r";

    /**
     * Părțile care pot rula dintr-un text HTML (sau CSS: o bucată `</style><script>` e tot HTML).
     *
     * @return list<string>
     */
    public static function parti(string $text): array
    {
        $parti = [];
        self::citeste($text, 0, $parti);
        return $parti;
    }

    /**
     * Un câmp care e în întregime JavaScript: o singură parte (textul lui, cu rândurile normalizate), sau niciuna dacă
     * e gol.
     *
     * @return list<string>
     */
    public static function partiJs(string $js): array
    {
        $normal = self::normalizeazaText($js);
        return $normal === '' ? [] : ['js:' . $normal];
    }

    /**
     * Părțile tuturor valorilor unui formular (sau ale unui rând din bază), oricât de adânc ar fi tablourile. Un text
     * care e JSON (un tablou sau un obiect) se citește și desfăcut, valoare cu valoare (blocurile unui newsletter, câmpurile
     * unui formular opt-in); un câmp `<nume>_b64` se citește decodat. Câmpurile al căror nume e de JavaScript (`js`,
     * `js_content`, `…_js`, `js_…`) sunt în întregime cod.
     *
     * @param array<array-key, mixed> $valori
     * @return list<string>
     */
    public static function partiDin(array $valori): array
    {
        $parti = [];
        self::adunaDin($valori, $parti, 0);
        return $parti;
    }

    /** Numele unui câmp al cărui conținut e în întregime JavaScript. */
    public static function campJs(string $nume): bool
    {
        return preg_match('/(^|_)js(_|$)/i', $nume) === 1;
    }

    /**
     * Părțile din $noi care nu erau deja în $vechi (numărate: o parte salvată o dată nu acoperă două), în ordinea lor.
     *
     * @param list<string> $noi
     * @param list<string> $vechi
     * @return list<string>
     */
    public static function noi(array $noi, array $vechi): array
    {
        $ramase = array_count_values($vechi);
        $rezultat = [];
        foreach ($noi as $parte) {
            if (($ramase[$parte] ?? 0) > 0) {
                $ramase[$parte]--;
                continue;
            }
            $rezultat[] = $parte;
        }
        return $rezultat;
    }

    /** O parte scrisă pe înțelesul omului, scurtă (pentru mesajul refuzului și jurnal). */
    public static function descrie(string $parte): string
    {
        [$fel, $rest] = array_pad(explode(':', $parte, 2), 2, '');
        $text = match ($fel) {
            'script' => '<script…> ' . $rest,
            'js' => 'JavaScript: ' . $rest,
            'on', 'url', 'srcdoc' => $rest,
            'base' => '<base ' . $rest . '>',
            default => $rest,
        };
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > 90 ? mb_substr($text, 0, 87) . '…' : $text;
    }

    // ------------------------------------------------------------------ citirea

    /** @param list<string> $parti */
    private static function citeste(string $s, int $adancime, array &$parti): void
    {
        $n = strlen($s);
        if ($n === 0 || !str_contains($s, '<')) {
            return;
        }
        if ($n > self::OCTETI_MAXIMI) {
            if (preg_match('/<[a-z]/i', $s) === 1) {
                $parti[] = 'mare:' . sha1(self::normalizeazaText($s));
            }
            return;
        }
        $i = 0;
        while (($i = strpos($s, '<', $i)) !== false) {
            if ($i + 1 >= $n || !ctype_alpha($s[$i + 1])) {
                $i++;
                continue;
            }
            [$nume, $atribute, $sfarsit] = self::eticheta($s, $i + 1);
            foreach ($atribute as [$numeAtribut, $valoareBruta]) {
                $valoare = self::decodeaza($valoareBruta);
                if (str_starts_with($numeAtribut, 'on')) {
                    $parti[] = 'on:' . $numeAtribut . '=' . self::normalizeazaText($valoare);
                }
                if ($numeAtribut === 'srcdoc') {
                    $parti[] = 'srcdoc:' . self::normalizeazaText($valoare);
                }
                $compact = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $valoare));
                if (str_contains($compact, 'javascript:') || str_contains($compact, 'vbscript:')) {
                    $parti[] = 'url:' . $numeAtribut . '=' . self::normalizeazaText($valoare);
                }
                // O etichetă ascunsă într-un atribut (o valoare citită de browser ca HTML în alt context, srcdoc,
                // un text pus apoi în pagină de un script): se citește și ea.
                if ($adancime < self::ADANCIME_MAXIMA) {
                    if (str_contains($valoareBruta, '<')) {
                        self::citeste($valoareBruta, $adancime + 1, $parti);
                    }
                    if ($valoare !== $valoareBruta && str_contains($valoare, '<')) {
                        self::citeste($valoare, $adancime + 1, $parti);
                    }
                }
            }
            if ($nume === 'base') {
                $parti[] = 'base:' . self::serializeaza($atribute);
            }
            if ($nume === 'script') {
                // Conținutul scriptului e text până la primul „</script” (ca în browser); elementul întreg e o parte.
                $inchidere = stripos($s, '</script', $sfarsit);
                $continut = $inchidere === false ? substr($s, $sfarsit) : substr($s, $sfarsit, $inchidere - $sfarsit);
                $parti[] = 'script:' . self::serializeaza($atribute) . '>' . self::normalizeazaText($continut);
                $i = $inchidere === false ? $n : $inchidere + 1;
                continue;
            }
            $i = max($sfarsit, $i + 1);
        }
    }

    /**
     * O etichetă de început citită ca tokenizerul HTML, de la prima literă a numelui ei: numele (cu litere mici),
     * atributele [nume, valoare brută] și poziția de după „>” (sau sfârșitul textului: o etichetă neînchisă se ia
     * întreagă, fiindcă textul poate ajunge în pagină lipit de alt HTML).
     *
     * @return array{0: string, 1: list<array{0: string, 1: string}>, 2: int}
     */
    private static function eticheta(string $s, int $p): array
    {
        $n = strlen($s);
        $lungime = strcspn($s, self::SPATII . '/>', $p);
        $nume = strtolower(substr($s, $p, $lungime));
        $p += $lungime;
        $atribute = [];
        while ($p < $n) {
            $p += strspn($s, self::SPATII . '/', $p);
            if ($p >= $n) {
                break;
            }
            if ($s[$p] === '>') {
                return [$nume, $atribute, $p + 1];
            }
            // Numele atributului: un „=” chiar la început face parte din nume (ca în browser).
            $inceput = $p;
            $p++;
            $p += strcspn($s, self::SPATII . '/>=', $p);
            $numeAtribut = strtolower(substr($s, $inceput, $p - $inceput));
            $p += strspn($s, self::SPATII, $p);
            if ($p >= $n || $s[$p] !== '=') {
                $atribute[] = [$numeAtribut, ''];
                continue;
            }
            $p++;
            $p += strspn($s, self::SPATII, $p);
            if ($p >= $n) {
                $atribute[] = [$numeAtribut, ''];
                break;
            }
            $ghilimea = $s[$p];
            if ($ghilimea === '"' || $ghilimea === "'") {
                $capat = strpos($s, $ghilimea, $p + 1);
                $valoare = $capat === false ? substr($s, $p + 1) : substr($s, $p + 1, $capat - $p - 1);
                $p = $capat === false ? $n : $capat + 1;
            } elseif ($ghilimea === '>') {
                $valoare = '';
            } else {
                $lungimeValoare = strcspn($s, self::SPATII . '>', $p);
                $valoare = substr($s, $p, $lungimeValoare);
                $p += $lungimeValoare;
            }
            $atribute[] = [$numeAtribut, $valoare];
        }
        return [$nume, $atribute, $n];
    }

    /** Valoarea unui atribut cum o vede browserul: referințele numerice (și fără „;”) și cele cu nume decodate. */
    private static function decodeaza(string $valoare): string
    {
        if (!str_contains($valoare, '&')) {
            return $valoare;
        }
        $valoare = (string) preg_replace_callback('/&#(?:[xX]([0-9a-fA-F]+)|([0-9]+));?/', static function (array $m): string {
            $cod = ($m[1] ?? '') !== '' ? hexdec($m[1]) : (int) $m[2];
            if (!is_int($cod) || $cod <= 0 || $cod > 0x10FFFF || ($cod >= 0xD800 && $cod <= 0xDFFF)) {
                return "\u{FFFD}";
            }
            return mb_chr($cod, 'UTF-8') ?: "\u{FFFD}";
        }, $valoare);
        return html_entity_decode($valoare, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param list<array{0: string, 1: string}> $atribute */
    private static function serializeaza(array $atribute): string
    {
        $bucati = [];
        foreach ($atribute as [$nume, $valoare]) {
            $bucati[] = $nume . '=' . self::normalizeazaText(self::decodeaza($valoare));
        }
        sort($bucati);
        return implode(' ', $bucati);
    }

    /** Rândurile la fel oricum au fost trimise (un formular trimite CRLF), fără spațiile de la capete. */
    private static function normalizeazaText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * @param array<array-key, mixed> $valori
     * @param list<string> $parti
     */
    private static function adunaDin(array $valori, array &$parti, int $adancime): void
    {
        foreach ($valori as $cheie => $valoare) {
            $nume = (string) $cheie;
            // Și cheile unui obiect JSON sunt text (un nume de câmp scris de om).
            if (is_string($cheie) && str_contains($cheie, '<')) {
                array_push($parti, ...self::parti($cheie));
            }
            if (is_array($valoare)) {
                if ($adancime < 12) {
                    self::adunaDin($valoare, $parti, $adancime + 1);
                }
                continue;
            }
            if (!is_scalar($valoare)) {
                continue;
            }
            $text = (string) $valoare;
            if (str_ends_with(strtolower($nume), '_b64')) {
                $decodat = base64_decode($text, true);
                if (is_string($decodat)) {
                    $text = $decodat;
                    $nume = substr($nume, 0, -4);
                }
            }
            if (self::campJs($nume)) {
                array_push($parti, ...self::partiJs($text));
                continue;
            }
            // Un text JSON (tablou sau obiect) se citește valoare cu valoare, nu ca text brut: orice „<” din el e
            // într-o valoare, iar decodarea arată și ce e scris ocolit („<script”). Așa aceleași blocuri dau
            // aceleași părți oricum ar fi scrise (de browser la trimitere, de PHP la salvare).
            $inceput = ltrim($text);
            if ($adancime < 12 && $inceput !== '' && ($inceput[0] === '[' || $inceput[0] === '{')) {
                $json = json_decode($text, true);
                if (is_array($json)) {
                    self::adunaDin($json, $parti, $adancime + 1);
                    continue;
                }
            }
            array_push($parti, ...self::parti($text));
        }
    }
}
