<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Garda cererilor care schimbă ceva în admin (revizuirea de securitate din 18 noiembrie 2026, N2; docs/SECURITATE.md,
 * „Cererile venite din alte site-uri”). Până acum doar o parte din formularele adminului cereau jetonul AdminCsrf (banii,
 * plățile, pluginurile noi); restul se bazau doar pe cookie-ul sesiunii `SameSite=Lax`, care pleacă totuși la o cerere de
 * pe un subdomeniu al site-ului (același „site”, altă origine) și în browserele vechi.
 *
 * Pentru orice cerere sub /admin (și /admin însuși) cu altă metodă decât GET, HEAD sau OPTIONS (POST, PUT, PATCH, DELETE…),
 * oricare ar fi ruta, înaintea routerului:
 *  1. dacă browserul trimite `Sec-Fetch-Site`, cererea trece doar cu `same-origin` (o pagină a site-ului însuși) sau `none`
 *     (o adresă scrisă de om); `same-site` (un subdomeniu), `cross-site` (alt site) și orice altă valoare se refuză;
 *  2. altfel, dacă trimite `Origin`, el trebuie să fie originea site-ului: gazda cererii (http sau https) sau cea din
 *     APP_URL; `Origin: null` (un cadru izolat, o redirecționare) se refuză;
 *  3. fără niciunul dintre antete (un browser foarte vechi, un script din linia de comandă), ca înainte: cookie-ul
 *     `SameSite=Lax` și, unde există, jetonul AdminCsrf.
 * Refuzul: 403, un text scurt (JSON pentru cererile scripturilor) și un rând în storage/logs/securitate.log (metoda,
 * calea fără parametri, antetele de mai sus, adminul din sesiune), fără date din formular.
 *
 * În afara adminului nu se schimbă nimic: plățile (BT iPay, Stripe, EuPlătesc, întoarcerea clientului de la bancă),
 * ERP-ul, aplicația de desktop, formularele publice, dezabonarea „cu un clic” din clientul de email și scriptul de
 * statistici primesc cereri de pe alte site-uri, iar niciuna nu e sub /admin (lista: docs/SECURITATE.md).
 */
final class GardaOrigine
{
    /** Peste atâția octeți jurnalul se mută în securitate.log.1 (cel vechi se pierde): cel mult ~2 MB pe disc. */
    private const JURNAL_MAXIM = 1_000_000;

    public const MESAJ = 'Cererea a fost refuzată: a venit de pe altă pagină decât adminul acestui site. Dacă ai apăsat chiar '
        . 'tu butonul, deschide din nou pagina din admin și încearcă încă o dată.';

    /**
     * Pentru public/index.php: cererea de acum poate trece? Dacă nu, a răspuns deja (403) și a notat refuzul.
     */
    public static function verifica(): bool
    {
        $metoda = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $decizie = self::decide($metoda, $cale, $_SERVER, (string) Env::get('APP_URL', ''));
        if ($decizie === null) {
            return true;
        }
        self::noteaza($metoda, $cale, $decizie);
        self::raspunde();
        return false;
    }

    /**
     * Regula, fără efecte (testele o folosesc direct): null = cererea trece; altfel motivul refuzului.
     *
     * @param array<string, mixed> $server
     */
    public static function decide(string $metoda, string $cale, array $server, string $appUrl): ?string
    {
        if (in_array(strtoupper(trim($metoda)), ['GET', 'HEAD', 'OPTIONS'], true) || !self::caleAdmin($cale)) {
            return null;
        }
        $secFetchSite = strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($secFetchSite !== '') {
            return in_array($secFetchSite, ['same-origin', 'none'], true) ? null : 'sec-fetch-site';
        }
        $origine = trim((string) ($server['HTTP_ORIGIN'] ?? ''));
        if ($origine !== '') {
            return in_array(self::normalizeazaOrigine($origine), self::originiProprii($server, $appUrl), true) ? null : 'origin';
        }
        return null;
    }

    /** Calea e a adminului? (/admin, /admin/…; și scrisă cu litere mari sau codată, ca să nu scape nimic.) */
    public static function caleAdmin(string $cale): bool
    {
        $cale = strtolower(rawurldecode($cale));
        return $cale === '/admin' || str_starts_with($cale, '/admin/');
    }

    /**
     * Originile site-ului: gazda cererii (pe http și pe https: schema văzută de PHP poate fi alta decât a browserului, în
     * spatele unui proxy) și originea din APP_URL.
     *
     * @param array<string, mixed> $server
     * @return list<string>
     */
    private static function originiProprii(array $server, string $appUrl): array
    {
        $originii = [];
        $gazda = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
        if ($gazda !== '' && preg_match('/^[a-z0-9.\-\[\]:]+$/', $gazda) === 1) {
            $originii[] = self::normalizeazaOrigine('http://' . $gazda);
            $originii[] = self::normalizeazaOrigine('https://' . $gazda);
        }
        if (trim($appUrl) !== '') {
            $originii[] = self::normalizeazaOrigine($appUrl);
        }
        return array_values(array_filter(array_unique($originii), static fn (string $o): bool => $o !== ''));
    }

    /** „https://Exemplu.ro:443/x” → „https://exemplu.ro”; o valoare care nu e o origine http(s) → „”. */
    private static function normalizeazaOrigine(string $origine): string
    {
        $parti = parse_url(trim($origine));
        if (!is_array($parti)) {
            return '';
        }
        $schema = strtolower((string) ($parti['scheme'] ?? ''));
        $gazda = strtolower(rtrim((string) ($parti['host'] ?? ''), '.'));
        if (!in_array($schema, ['http', 'https'], true) || $gazda === '') {
            return '';
        }
        $port = isset($parti['port']) ? (int) $parti['port'] : null;
        if ($port === ($schema === 'https' ? 443 : 80)) {
            $port = null;
        }
        return $schema . '://' . $gazda . ($port !== null ? ':' . $port : '');
    }

    private static function noteaza(string $metoda, string $cale, string $motiv): void
    {
        $curat = static fn (string $text, int $max): string => mb_substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text), 0, $max);
        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $rand = '[' . date('Y-m-d H:i:s') . '] cerere refuzată (' . $motiv . '): ' . $curat(strtoupper($metoda), 10) . ' '
            . $curat($cale, 200)
            . ' sec-fetch-site=' . $curat((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '-'), 40)
            . ' origin=' . $curat((string) ($_SERVER['HTTP_ORIGIN'] ?? '-'), 120)
            . ' admin=' . ($admin > 0 ? (string) $admin : '-')
            . ' ip=' . $curat(IpClient::adresa(), 45) . "\n";
        try {
            $dosar = Paths::stocareSite('logs');
            if (!is_dir($dosar) || !is_writable($dosar)) {
                return;
            }
            $fisier = $dosar . '/securitate.log';
            if (is_file($fisier) && (int) @filesize($fisier) > self::JURNAL_MAXIM) {
                @rename($fisier, $fisier . '.1');
            }
            @file_put_contents($fisier, $rand, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Jurnalul nu are voie să schimbe răspunsul.
        }
    }

    private static function raspunde(): void
    {
        http_response_code(403);
        header('Cache-Control: no-store');
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $tip = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($accept, 'application/json') || str_contains($tip, 'json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => self::MESAJ, 'error' => self::MESAJ], JSON_UNESCAPED_UNICODE);
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo self::MESAJ, "\n";
    }
}
