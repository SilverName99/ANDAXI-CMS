<?php

declare(strict_types=1);

/**
 * Rularea unui set de verificări agregate (tools/verificare/agregate.php, WP-10) și
 * extragerea claselor CSS din conținutul bazei (setul V-CSS). Garanțiile citirii: capul lui
 * lib/live.php.
 */

namespace Unelte\Verificare;

use ErrorException;
use PDOException;
use Throwable;

require_once __DIR__ . '/live.php';

final class Agregate
{
    public const BUGET_IMPLICIT = 300;
    public const BUGET_MAXIM = 1800;

    /** @param list<string> $argv */
    public static function ruleaza(array $argv, string $radacina): int
    {
        set_error_handler(static function (int $nivel, string $mesaj, string $fisier, int $linie): bool {
            if ((error_reporting() & $nivel) === 0) {
                return false; // o funcție chemată cu @ (un fișier care poate lipsi)
            }
            // Mesajul nu se păstrează (poate conține o valoare); doar locul, din codul uneltei.
            throw new ErrorException('', 0, $nivel, $fisier, $linie);
        });
        try {
            Rulare::faraSesiune();
            $argumente = Rulare::argumente($argv, ['set', 'dosar', 'fara-fisier', 'buget']);
            $set = is_string($argumente['set'] ?? null) ? $argumente['set'] : '';
            $fisierSet = dirname(__DIR__) . '/seturi/' . $set . '.php';
            if (preg_match('/^[a-z]{2,30}$/', $set) !== 1 || $set === 'comun' || !is_file($fisierSet)) {
                throw new Refuz('lipsește --set=<' . implode('|', array_keys(Locul::SITE_URI)) . '> (vezi capul fișierului)');
            }
            $buget = self::BUGET_IMPLICIT;
            if (isset($argumente['buget'])) {
                if (!is_string($argumente['buget']) || preg_match('/^\d{1,4}$/', $argumente['buget']) !== 1 || (int) $argumente['buget'] > self::BUGET_MAXIM) {
                    throw new Refuz('--buget=<secunde>, între 0 și ' . self::BUGET_MAXIM);
                }
                $buget = (int) $argumente['buget'];
            }
            $faraFisier = isset($argumente['fara-fisier']);
            [$acasa] = Locul::verificare($radacina);
            /** @var array{site: string, verificari: list<array{cheie: string, titlu: string, ruleaza: callable(Context): void}>} $definitie */
            $definitie = require $fisierSet;
            $dosarDat = isset($argumente['dosar']) && is_string($argumente['dosar']) ? $argumente['dosar'] : null;
            $site = SiteLive::gaseste($definitie['site'], $dosarDat, $acasa, $radacina);
            $citire = CitireLive::deschide($site);
        } catch (Refuz $e) {
            restore_error_handler();
            fwrite(STDERR, 'REFUZAT: ' . $e->getMessage() . "\n");
            return 2;
        } catch (Throwable $e) {
            restore_error_handler();
            fwrite(STDERR, 'REFUZAT: ' . self::descrieEroarea($e) . "\n");
            return 2;
        }

        $raport = new Raport();
        $context = new Context($citire, $raport, $site);
        $start = microtime(true);
        $pierduta = false;
        $grupCurent = null;
        // Rezultatul: pe ecran și în storage/verificare/sonde/. Și după o eroare fatală (lib/intrare.php),
        // cu verificările de până acolo și grupul oprit ca EROARE.
        $termina = static function (?string $oprit) use (&$grupCurent, $raport, $citire, $site, $set, $start, $faraFisier): int {
            if ($oprit !== null) {
                $raport->eroare($grupCurent ?? 'verificare', 'rularea s-a oprit: ' . $oprit);
            }
            $durata = (int) round(microtime(true) - $start);
            $n = $raport->numarate();
            $moment = date('Y-m-d H:i');
            $text = 'ANDAXI-CMS — verificări agregate pe un site live (WP-10)' . "\n"
                . 'Setul: ' . $set . ' — ' . $site->titlu . ', domeniul ' . $site->gazda . ', dosarul ' . $site->dosarRelativ . "\n"
                . 'Rulat: ' . $moment . ' (' . date_default_timezone_get() . '), PHP ' . PHP_VERSION . ', baza ' . ($citire->familie === 'mariadb' ? 'MariaDB' : 'MySQL')
                . ' ' . $citire->versiune . ', sesiune doar de citire' . "\n"
                . 'Doar numere, DA/NU și nume de structură: niciun rând, nicio valoare din bază, nicio parolă.' . "\n"
                . $raport->text() . "\n"
                . 'REZUMAT: ' . array_sum($n) . ' rânduri (OK ' . $n['OK'] . ', INFO ' . $n['INFO'] . ', ATENȚIE ' . $n['ATENȚIE'] . ', LIPSĂ ' . $n['LIPSĂ']
                . ', EROARE ' . $n['EROARE'] . ', SĂRIT ' . $n['SĂRIT'] . '), ' . $citire->interogari . ' interogări, ' . $durata . ' s.' . "\n";
            $bun = $n['EROARE'] === 0 && $n['SĂRIT'] === 0;
            $final = $oprit !== null ? 'OPRIT: ' . $oprit . '; verificările de până acolo sunt mai sus, celelalte n-au rulat.'
                : ($bun ? 'GATA: toate verificările setului au rulat.'
                : 'PARȚIAL: ' . ($n['EROARE'] + $n['SĂRIT']) . ' verificări au căzut sau au fost sărite (EROARE / SĂRIT mai sus); restul e bun.');
            echo $text;
            if ($faraFisier) {
                echo $final . ' Nimic scris (--fara-fisier).' . "\n";
            } else {
                try {
                    $cale = Rulare::salveaza('agregate-' . $set . '-' . date('Ymd-His') . '.txt', $text . $final . "\n");
                    echo $final . ' Rezultatul e și în ' . $cale . ': trimite-ni-l (are doar numere).' . "\n";
                } catch (Refuz $e) {
                    echo $final . "\n";
                    fwrite(STDERR, 'ATENȚIE: ' . $e->getMessage() . "; copiază textul de mai sus.\n");
                    return 1;
                }
            }
            return $bun && $oprit === null ? 0 : 1;
        };
        Rulare::laOprire(static fn (string $motiv): int => $termina($motiv));
        foreach ($definitie['verificari'] as $grup) {
            $raport->titlu($grup['titlu']);
            $cheie = $grup['cheie'];
            $grupCurent = $cheie;
            if ($pierduta) {
                $raport->sarit($cheie, 'legătura cu baza s-a pierdut');
                continue;
            }
            if (microtime(true) - $start >= $buget) {
                $raport->sarit($cheie, 'bugetul de timp s-a terminat (--buget=' . $buget . ' s)');
                continue;
            }
            try {
                $citire->inTranzactie(static fn () => ($grup['ruleaza'])($context));
            } catch (ValoareRefuzata) {
                $raport->eroare($cheie, 'o valoare nu se potrivește tiparului ei, deci nu se tipărește');
            } catch (InstructiuneRefuzata $e) {
                $raport->eroare($cheie, 'instrucțiune refuzată: ' . $e->getMessage());
            } catch (PDOException $e) {
                $pierduta = CitireLive::eLegaturaPierduta($e);
                $raport->eroare($cheie, CitireLive::eTimpDepasit($e)
                    ? 'timpul depășit (' . CitireLive::TIMP_INSTRUCTIUNE . ' s pe instrucțiune)' : CitireLive::cod($e));
            } catch (Throwable $e) {
                $raport->eroare($cheie, self::descrieEroarea($e));
            }
        }
        $grupCurent = null;
        restore_error_handler();
        Rulare::laOprire(null);
        return $termina(null);
    }

    /** O eroare PHP, fără mesaj (poate conține o valoare): clasa și locul, din codul uneltei. */
    public static function descrieEroarea(Throwable $e): string
    {
        $fisier = $e->getFile();
        $unelte = dirname(__DIR__, 2) . '/';
        $loc = str_starts_with($fisier, $unelte) ? substr($fisier, strlen($unelte)) . ':' . $e->getLine() : basename($fisier);
        $clasa = substr(strrchr('\\' . get_class($e), '\\') ?: '', 1);
        return 'eroare PHP (' . (preg_match('/^[A-Za-z0-9_]{1,60}$/', $clasa) === 1 ? $clasa : '?') . ' în '
            . (preg_match('/^[A-Za-z0-9._\/:-]{1,200}$/', $loc) === 1 ? $loc : '?') . ')';
    }
}

/**
 * Clasele CSS folosite de conținutul din bază (V-CSS, G-M3): din atributele `class` ale
 * HTML-ului (și din blocurile `<style>` din el) și din selectorii CSS-ului. Numele unei
 * clase nu e o dată personală; tot trece prin tiparul `clasa` al raportului.
 */
final class ClaseCss
{
    /** @return list<string> */
    public static function dinHtml(string $html): array
    {
        $clase = [];
        if (preg_match_all('/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $html, $potriviri, PREG_SET_ORDER) > 0) {
            foreach ($potriviri as $p) {
                $valoare = ($p[1] ?? '') . ($p[2] ?? '') . ($p[3] ?? '');
                if (str_contains($valoare, '{{') || str_contains($valoare, '<?') || str_contains($valoare, '${')) {
                    continue;
                }
                foreach (preg_split('/\s+/', trim($valoare)) ?: [] as $clasa) {
                    if (Raport::potrivit('clasa', $clasa)) {
                        $clase[$clasa] = true;
                    }
                }
            }
        }
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $stiluri) > 0) {
            foreach ($stiluri[1] as $css) {
                foreach (self::dinCss($css) as $clasa) {
                    $clase[$clasa] = true;
                }
            }
        }
        return array_keys($clase);
    }

    /**
     * Clasele pe care JavaScript-ul din bază le pune pe elemente sau le caută: textele din
     * argumentele lui classList.add/remove/toggle/replace/contains, addClass/removeClass/
     * toggleClass/hasClass (jQuery) și getElementsByClassName; ce primește className (=, +=,
     * comparat sau ca proprietate) și setAttribute('class', …); atributele class din HTML-ul
     * scris în texte; selectorii din querySelector(All), closest, matches, $()/jQuery() și din
     * metodele jQuery care primesc un selector (find, is, filter, on…). Mai multe clase decât
     * trebuie nu strică (o clasă în plus doar oprește ștergerea unei reguli), una lipsă da.
     *
     * @return list<string>
     */
    public static function dinJs(string $js): array
    {
        $clase = [];
        $literale = static function (string $text): array {
            return preg_match_all('/(["\'`])((?:\\\\.|(?!\1).)*?)\1/s', $text, $m) > 0 ? $m[2] : [];
        };
        $cuvinte = static function (string $text) use (&$clase): void {
            foreach (preg_split('/[\s,]+/', $text) ?: [] as $clasa) {
                if (Raport::potrivit('clasa', $clasa)) {
                    $clase[$clasa] = true;
                }
            }
        };
        $selector = static function (string $text) use (&$clase): void {
            if (preg_match_all('/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $text, $m) > 0) {
                foreach ($m[1] as $clasa) {
                    if (Raport::potrivit('clasa', $clasa)) {
                        $clase[$clasa] = true;
                    }
                }
            }
        };
        if (preg_match_all('/\b(?:classList\s*\.\s*(?:add|remove|toggle|replace|contains)|(?:add|remove|toggle|has)Class|getElementsByClassName)\s*\(([^)]*)\)/', $js, $p) > 0) {
            foreach ($p[1] as $argumente) {
                foreach ($literale($argumente) as $text) {
                    $cuvinte($text);
                }
            }
        }
        if (preg_match_all('/\bclassName\s*(?:\+?={1,3}|!==?|:)([^;\n]*)/', $js, $p) > 0) {
            foreach ($p[1] as $rest) {
                foreach ($literale($rest) as $text) {
                    $cuvinte($text);
                }
            }
        }
        if (preg_match_all('/\bsetAttribute\s*\(\s*(["\'])class\1\s*,([^)]*)\)/', $js, $p) > 0) {
            foreach ($p[2] as $rest) {
                foreach ($literale($rest) as $text) {
                    $cuvinte($text);
                }
            }
        }
        // HTML scris în texte: class="…", și cu ghilimelele scăpate (class=\"…\").
        if (preg_match_all('/\bclass\s*=\s*\\\\?(["\'])(.*?)\\\\?\1/s', $js, $p) > 0) {
            foreach ($p[2] as $valoare) {
                $cuvinte($valoare);
            }
        }
        if (preg_match_all('/(?:\b(?:querySelector(?:All)?|closest|matches|jQuery)|(?<![\w$])\$|\.(?:find|is|filter|not|children|parents?|parentsUntil|siblings|next(?:All)?|prev(?:All)?|has|on|off|one|delegate|add))\s*\(([^)]*)\)/', $js, $p) > 0) {
            foreach ($p[1] as $argumente) {
                foreach ($literale($argumente) as $text) {
                    $selector($text);
                }
            }
        }
        return array_keys($clase);
    }

    /** @return list<string> */
    public static function dinCss(string $css): array
    {
        $css = (string) preg_replace('/\/\*.*?\*\//s', ' ', $css);
        $css = (string) preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', '""', $css);
        $clase = [];
        // Textul dinaintea fiecărei acolade deschise (după ultima „{”, „}” sau „;”) e un
        // selector sau începutul unei reguli @; declarațiile nu intră (fără „url(x.png)”).
        $bucati = preg_split('/([{};])/', $css, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($bucati as $i => $bucata) {
            if (($bucati[$i + 1] ?? '') !== '{' || in_array($bucata, ['{', '}', ';'], true)) {
                continue;
            }
            $selector = trim($bucata);
            if ($selector === '' || str_starts_with($selector, '@')) {
                continue;
            }
            if (preg_match_all('/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $selector, $m) > 0) {
                foreach ($m[1] as $clasa) {
                    if (Raport::potrivit('clasa', $clasa)) {
                        $clase[$clasa] = true;
                    }
                }
            }
        }
        return array_keys($clase);
    }
}
