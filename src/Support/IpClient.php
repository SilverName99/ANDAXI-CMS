<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Adresa IP a vizitatorului, una singură pentru tot codul (limitele de încercări,
 * poarta site-ului de verificare).
 *
 * Regula: `REMOTE_ADDR`, adresa care a deschis legătura cu serverul. Antetul
 * `X-Forwarded-For` îl poate scrie oricine, deci nu e crezut decât când cererea vine de
 * la un proxy de încredere: `REMOTE_ADDR` e într-una din rețelele din
 * `IP_PROXY_DE_INCREDERE` (.env; adrese sau rețele CIDR, separate prin virgulă sau
 * spațiu; implicit nimic). Atunci se ia, de la dreapta la stânga, prima adresă din
 * antet care nu e și ea de încredere: proxy-urile își adaugă adresa la capătul din
 * dreapta, deci ce a inventat vizitatorul stă mereu în stânga și nu se ajunge la ea.
 *
 * Ce rețea are proxy-ul găzduirii (CDN-ul Hostinger) arată `tools/verificare/stare.php`
 * (clasa lui `REMOTE_ADDR` și dacă sosește `X-Forwarded-For`). Limitele de încercări
 * ale site-ului trec pe această clasă în WP-12b; până atunci o folosește doar poarta.
 */
final class IpClient
{
    /** Rețelele cu nume, pentru `clasa()` (stare.php le arată proprietarului). */
    private const CLASE = [
        ['127.0.0.0/8', 'loopback'],
        ['::1/128', 'loopback'],
        ['10.0.0.0/8', 'privată'],
        ['172.16.0.0/12', 'privată'],
        ['192.168.0.0/16', 'privată'],
        ['fc00::/7', 'privată'],
        ['100.64.0.0/10', 'CGNAT'],
        ['169.254.0.0/16', 'link-local'],
        ['fe80::/10', 'link-local'],
    ];

    /** Adresa vizitatorului cererii curente. */
    public static function adresa(): string
    {
        return self::dinCerere($_SERVER, (string) Env::get('IP_PROXY_DE_INCREDERE', ''));
    }

    /**
     * Regula de mai sus pe o cerere dată (`$server` ca `$_SERVER`), pentru teste și pentru
     * `adresa()`. O adresă invalidă în `REMOTE_ADDR` se întoarce așa cum e (fără spații).
     */
    public static function dinCerere(array $server, string $incredere): string
    {
        $bruta = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        $remote = self::normalizeaza($bruta);
        if ($remote === null) {
            return $bruta;
        }
        $retele = self::retele($incredere);
        if ($retele === [] || !self::inRetele($remote, $retele)) {
            return $remote;
        }
        $antet = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($antet === '') {
            return $remote;
        }
        $ultima = $remote;
        foreach (array_reverse(explode(',', $antet)) as $hop) {
            $ip = self::normalizeaza(self::faraPort(trim($hop)));
            if ($ip === null) {
                // Lanț stricat: rămânem la ultima adresă scrisă de un proxy de încredere.
                break;
            }
            if (!self::inRetele($ip, $retele)) {
                return $ip;
            }
            $ultima = $ip;
        }
        return $ultima;
    }

    /**
     * Ce fel de adresă e: „loopback (127.0.0.0/8)”, „privată (10.0.0.0/8)”, „CGNAT (…)”,
     * „link-local (…)”, „publică” sau „invalidă”. Doar clasa, nu adresa (stare.php).
     */
    public static function clasa(string $ip): string
    {
        $ip = self::normalizeaza($ip);
        if ($ip === null) {
            return 'invalidă';
        }
        foreach (self::CLASE as [$retea, $nume]) {
            if (self::inRetea($ip, $retea)) {
                return $nume . ' (' . $retea . ')';
            }
        }
        return 'publică';
    }

    /** Adresa e în rețeaua dată („10.0.0.0/8”, „2001:db8::/32” sau o singură adresă)? */
    public static function inRetea(string $ip, string $retea): bool
    {
        $ip = self::normalizeaza($ip);
        $parsata = self::parseazaRetea($retea);
        if ($ip === null || $parsata === null) {
            return false;
        }
        [$baza, $biti] = $parsata;
        $octeti = (string) inet_pton($ip);
        if (strlen($octeti) !== strlen($baza)) {
            return false;
        }
        $intregi = intdiv($biti, 8);
        if ($intregi > 0 && substr($octeti, 0, $intregi) !== substr($baza, 0, $intregi)) {
            return false;
        }
        $rest = $biti % 8;
        if ($rest === 0) {
            return true;
        }
        $masca = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($octeti[$intregi]) & $masca) === (ord($baza[$intregi]) & $masca);
    }

    /**
     * Rețelele valide dintr-o listă (virgulă sau spațiu); ce nu se înțelege se ignoră.
     *
     * @return list<string>
     */
    public static function retele(string $lista): array
    {
        $rezultat = [];
        foreach (preg_split('/[\s,]+/', trim($lista)) ?: [] as $element) {
            if ($element !== '' && self::parseazaRetea($element) !== null) {
                $rezultat[] = $element;
            }
        }
        return $rezultat;
    }

    /** @param list<string> $retele */
    private static function inRetele(string $ip, array $retele): bool
    {
        foreach ($retele as $retea) {
            if (self::inRetea($ip, $retea)) {
                return true;
            }
        }
        return false;
    }

    /** Forma canonică (IPv4 din „::ffff:1.2.3.4”), sau null dacă nu e o adresă. */
    private static function normalizeaza(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $octeti = inet_pton($ip);
        if ($octeti === false) {
            return null;
        }
        if (strlen($octeti) === 16 && substr($octeti, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return (string) inet_ntop(substr($octeti, 12));
        }
        return (string) inet_ntop($octeti);
    }

    /** „1.2.3.4:5678” → „1.2.3.4”; „[2001:db8::1]:443” → „2001:db8::1”. */
    private static function faraPort(string $hop): string
    {
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $hop, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $hop, $m) === 1) {
            return $m[1];
        }
        return $hop;
    }

    /** @return array{0: string, 1: int}|null octeții rețelei și lungimea prefixului */
    private static function parseazaRetea(string $retea): ?array
    {
        $retea = trim($retea);
        $biti = null;
        if (str_contains($retea, '/')) {
            [$retea, $prefix] = explode('/', $retea, 2);
            if (preg_match('/^\d{1,3}$/', $prefix) !== 1) {
                return null;
            }
            $biti = (int) $prefix;
        }
        $ip = self::normalizeaza($retea);
        if ($ip === null) {
            return null;
        }
        $octeti = (string) inet_pton($ip);
        $maxim = strlen($octeti) * 8;
        $biti ??= $maxim;
        if ($biti < 0 || $biti > $maxim) {
            return null;
        }
        return [$octeti, $biti];
    }
}
