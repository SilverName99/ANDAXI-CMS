<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use stdClass;
use Throwable;

/**
 * Licența site-ului: lista semnată de CRM cu starea fiecărui plugin.
 *
 * Fișierul `licenta.json` (în `storage/licenta/`, sau în `LICENTA_DOSAR`) arată așa:
 *
 *     {"site":"bioscem","emisa_la":"2026-10-18T09:15:02Z","versiune":17,
 *      "pluginuri":{"catalog":"pornit","magazin":"se_opreste"},
 *      "semnatura":"<Ed25519, base64>"}
 *
 * Semnătura e Ed25519 (sodium), separată de conținut, peste
 * `"andaxi-licenta-v1\n"` + JSON-ul canonic al tuturor câmpurilor în afară de
 * „semnatura” (chei sortate, fără spații, fără escape la „/” și la diacritice).
 * Prefixul împiedică folosirea unei semnături a CRM-ului făcute în alt scop.
 * Câmp opțional, tot semnat: „redirectionari” = {"<plugin>": "/cale"}, unde
 * duc adresele publice ale unui plugin oprit (implicit: pagina 404).
 *
 * Reguli:
 *  - semnătura greșită sau lipsă, alt site, JSON stricat → licența se ignoră
 *    și rămâne ultima bună (`licenta-buna.json`);
 *  - „versiune” mai mică decât a ultimei bune → se ignoră (nu se poate da
 *    site-ul înapoi la o listă veche); egală sau mai mare → devine ultima bună;
 *  - nicio licență nu se verifică acum → `Plugins` merge cu ultima licență
 *    aplicată (`stare-aplicata.json`); nicio licență, niciodată → doar nucleul.
 *
 * Granița pentru Etapa 2: aici stau și memoria (ultima bună) și intrarea
 * licențelor noi (`instaleaza`). Preluarea de la CRM (cererea HTTP la 15 minute,
 * cu nonce și check-in) se adaugă tot aici, ca `reimprospateazaDeLaCrm()`, care
 * descarcă licența și o dă lui `instaleaza()`; restul codului nu se schimbă.
 * Până atunci licența o scriu scriptul „site nou” sau `tools/dev/licenta.php`.
 */
final class Licenta
{
    public const PREFIX_SEMNATURA = "andaxi-licenta-v1\n";
    public const FISIER = 'licenta.json';
    public const FISIER_BUN = 'licenta-buna.json';
    public const STARI = ['pornit', 'se_opreste', 'oprit'];

    /** @var array{dosar:string, chei:list<string>, site:string, eroare_chei:string}|null */
    private static ?array $config = null;
    private static ?array $rezultat = null;

    /**
     * Doar pentru teste și unelte: altă configurare decât cea din `.env`.
     * Chei: dosar, cheie_publica (base64, una sau mai multe separate prin virgulă), site.
     */
    public static function configureaza(array $config): void
    {
        self::$config = self::construiesteConfig(
            (string) ($config['dosar'] ?? self::dosarImplicit()),
            (string) ($config['cheie_publica'] ?? ''),
            (string) ($config['site'] ?? '')
        );
        self::$rezultat = null;
    }

    /**
     * Uită licența citită (data viitoare se citește din nou). Cu `true` uită și
     * configurarea dată de `configureaza()`, ca să revină cea din `.env`.
     */
    public static function reseteaza(bool $siConfigurarea = false): void
    {
        if ($siConfigurarea) {
            self::$config = null;
        }
        self::$rezultat = null;
    }

    public static function dosar(): string
    {
        return self::config()['dosar'];
    }

    public static function site(): string
    {
        return self::config()['site'];
    }

    /**
     * Licența folosită acum.
     *
     * @return array{licenta: ?array, sursa: ?string, probleme: list<string>}
     *   licenta = conținutul verificat (fără semnătură), sursa = fișierul din care vine
     */
    public static function incarca(): array
    {
        if (self::$rezultat !== null) {
            return self::$rezultat;
        }

        $dosar = self::dosar();
        $probleme = [];
        $curenta = self::citeste($dosar . '/' . self::FISIER);
        $buna = self::citeste($dosar . '/' . self::FISIER_BUN);

        $vCurenta = $curenta !== null ? self::verifica($curenta) : null;
        $vBuna = null;
        if ($buna !== null) {
            $vBuna = ($buna === $curenta && $vCurenta !== null) ? $vCurenta : self::verifica($buna);
        }

        if ($curenta === null) {
            $probleme[] = self::FISIER . ' lipsește din ' . $dosar . '.';
        } elseif (!$vCurenta['ok']) {
            $probleme[] = self::FISIER . ' respinsă: ' . $vCurenta['eroare'];
        }
        if ($vBuna !== null && !$vBuna['ok'] && $buna !== $curenta) {
            $probleme[] = self::FISIER_BUN . ' respinsă: ' . $vBuna['eroare'];
        }

        $aleasa = null;
        $sursa = null;
        if ($vCurenta !== null && $vCurenta['ok']) {
            $versiuneCurenta = (int) $vCurenta['licenta']['versiune'];
            $versiuneBuna = ($vBuna !== null && $vBuna['ok']) ? (int) $vBuna['licenta']['versiune'] : 0;
            if ($versiuneBuna > $versiuneCurenta) {
                $probleme[] = self::FISIER . ' are versiunea ' . $versiuneCurenta . ', mai veche decât ultima licență bună ('
                    . $versiuneBuna . '); rămâne ultima bună.';
                $aleasa = $vBuna['licenta'];
                $sursa = self::FISIER_BUN;
            } else {
                $aleasa = $vCurenta['licenta'];
                $sursa = self::FISIER;
                if ($buna !== $curenta && !self::scrieAtomic($dosar . '/' . self::FISIER_BUN, (string) $curenta)) {
                    $probleme[] = 'Nu pot scrie ' . self::FISIER_BUN . ' în ' . $dosar . ' (drepturi de scriere?).';
                }
            }
        } elseif ($vBuna !== null && $vBuna['ok']) {
            $aleasa = $vBuna['licenta'];
            $sursa = self::FISIER_BUN;
        }

        self::$rezultat = ['licenta' => $aleasa, 'sursa' => $sursa, 'probleme' => $probleme];
        return self::$rezultat;
    }

    /**
     * Verifică o licență (textul fișierului): JSON, semnătură, site, câmpuri.
     *
     * @return array{ok: bool, licenta: ?array, eroare: string}
     */
    public static function verifica(string $json): array
    {
        $esec = static fn (string $eroare): array => ['ok' => false, 'licenta' => null, 'eroare' => $eroare];

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return $esec('extensia PHP sodium lipsește; semnătura nu poate fi verificată.');
        }
        $config = self::config();
        if ($config['chei'] === []) {
            return $esec($config['eroare_chei'] !== '' ? $config['eroare_chei'] : 'LICENTA_CHEIE_PUBLICA nu este setată.');
        }

        try {
            $date = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $esec('nu e JSON valid.');
        }
        if (!$date instanceof stdClass) {
            return $esec('nu e un obiect JSON.');
        }

        $semnaturaText = $date->semnatura ?? null;
        if (!is_string($semnaturaText) || $semnaturaText === '') {
            return $esec('lipsește semnătura.');
        }
        $semnatura = self::decodeazaBase64($semnaturaText);
        if ($semnatura === null || strlen($semnatura) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return $esec('semnătura nu are forma unei semnături Ed25519.');
        }

        $continut = clone $date;
        unset($continut->semnatura);
        $mesaj = self::mesajDeSemnat($continut);
        $valida = false;
        foreach ($config['chei'] as $cheie) {
            try {
                if (sodium_crypto_sign_verify_detached($semnatura, $mesaj, $cheie)) {
                    $valida = true;
                    break;
                }
            } catch (Throwable) {
                // cheie de altă formă: încercăm următoarea
            }
        }
        if (!$valida) {
            return $esec('semnătura nu se potrivește (licența a fost modificată sau nu e semnată de CRM).');
        }

        // De aici conținutul vine sigur de la CRM; verificăm doar că e întreg și pentru noi.
        $licenta = json_decode((string) json_encode($continut), true);
        if (!is_array($licenta)) {
            return $esec('conținut ilizibil.');
        }
        $site = $licenta['site'] ?? null;
        if (!is_string($site) || $site === '') {
            return $esec('lipsește „site”.');
        }
        if ($config['site'] === '') {
            return $esec('LICENTA_SITE nu este setat; nu știu pentru ce site trebuie să fie licența.');
        }
        if (!hash_equals($config['site'], $site)) {
            return $esec('e pentru site-ul „' . $site . '”, nu pentru „' . $config['site'] . '”.');
        }
        $versiune = $licenta['versiune'] ?? null;
        if (!is_int($versiune) || $versiune < 1) {
            return $esec('„versiune” trebuie să fie un număr întreg pozitiv.');
        }
        $emisaLa = $licenta['emisa_la'] ?? null;
        if (!is_string($emisaLa) || !self::esteDataIso($emisaLa)) {
            return $esec('„emisa_la” nu e o dată ISO-8601.');
        }
        if (!($date->pluginuri ?? null) instanceof stdClass) {
            return $esec('„pluginuri” trebuie să fie un obiect {cheie: stare}.');
        }
        $pluginuri = [];
        foreach ((array) $date->pluginuri as $cheie => $stare) {
            $pluginuri[(string) $cheie] = is_string($stare) ? $stare : '';
        }
        $licenta['pluginuri'] = $pluginuri;

        $redirectionari = [];
        if (isset($date->redirectionari)) {
            if (!$date->redirectionari instanceof stdClass) {
                return $esec('„redirectionari” trebuie să fie un obiect {cheie: cale}.');
            }
            foreach ((array) $date->redirectionari as $cheie => $cale) {
                // Doar căi din același site: „/ceva”, niciodată „//alt-site” sau „https://…”.
                if (is_string($cale) && preg_match('#^/(?!/)[^\s\\\\]*$#', $cale) === 1) {
                    $redirectionari[(string) $cheie] = $cale;
                }
            }
        }
        $licenta['redirectionari'] = $redirectionari;

        return ['ok' => true, 'licenta' => $licenta, 'eroare' => ''];
    }

    /**
     * Pune o licență nouă pe site: o verifică, apoi o scrie ca `licenta.json`.
     * Folosită de scriptul „site nou”, de unealta de dezvoltare și, în Etapa 2,
     * de preluarea de la CRM. O licență respinsă nu se scrie (rămâne ce era).
     *
     * @return array{ok: bool, eroare: string, versiune: int}
     */
    public static function instaleaza(string $json): array
    {
        $verificare = self::verifica($json);
        if (!$verificare['ok']) {
            return ['ok' => false, 'eroare' => $verificare['eroare'], 'versiune' => 0];
        }
        $versiune = (int) $verificare['licenta']['versiune'];
        $buna = self::citeste(self::dosar() . '/' . self::FISIER_BUN);
        if ($buna !== null) {
            $vBuna = self::verifica($buna);
            if ($vBuna['ok'] && (int) $vBuna['licenta']['versiune'] > $versiune) {
                return ['ok' => false, 'versiune' => $versiune, 'eroare' => 'versiunea ' . $versiune
                    . ' e mai veche decât licența bună de pe site (' . (int) $vBuna['licenta']['versiune'] . ').'];
            }
        }
        if (!is_dir(self::dosar())) {
            @mkdir(self::dosar(), 0770, true);
        }
        if (!self::scrieAtomic(self::dosar() . '/' . self::FISIER, $json)) {
            return ['ok' => false, 'eroare' => 'nu pot scrie în ' . self::dosar() . '.', 'versiune' => $versiune];
        }
        self::$rezultat = null;
        self::incarca();
        return ['ok' => true, 'eroare' => '', 'versiune' => $versiune];
    }

    /**
     * Semnează o licență cu cheia privată (64 de octeți, ca din sodium). Pe
     * site nu se folosește niciodată (site-ul are doar cheia publică); există
     * pentru unealta de dezvoltare, teste și ca referință pentru CRM.
     *
     * @param array<string, mixed> $licenta fără „semnatura”
     * @return string textul fișierului licenta.json
     */
    public static function semneaza(array $licenta, string $cheiePrivata): string
    {
        unset($licenta['semnatura']);
        $obiect = self::caObiect($licenta);
        $semnatura = sodium_crypto_sign_detached(self::mesajDeSemnat($obiect), $cheiePrivata);
        $obiect->semnatura = base64_encode($semnatura);
        return json_encode($obiect, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** Exact octeții peste care se face semnătura. */
    public static function mesajDeSemnat(mixed $continut): string
    {
        return self::PREFIX_SEMNATURA . self::jsonCanonic($continut);
    }

    /**
     * JSON canonic: obiectele cu cheile sortate (după octeți), listele în
     * ordine, fără spații, fără escape la „/”, la diacritice și la U+2028/U+2029
     * (exact ca `JSON.stringify` cu cheile sortate). Numerele: doar întregi.
     * Obiectele trebuie să vină ca stdClass (json_decode fără `true`), ca `{}`
     * să rămână `{}`.
     */
    public static function jsonCanonic(mixed $valoare): string
    {
        if ($valoare instanceof stdClass) {
            $campuri = get_object_vars($valoare);
            ksort($campuri, SORT_STRING);
            $bucati = [];
            foreach ($campuri as $cheie => $v) {
                $bucati[] = self::jsonScalar((string) $cheie) . ':' . self::jsonCanonic($v);
            }
            return '{' . implode(',', $bucati) . '}';
        }
        if (is_array($valoare)) {
            if (!self::esteLista($valoare)) {
                return self::jsonCanonic(self::caObiect($valoare));
            }
            return '[' . implode(',', array_map(static fn ($v): string => self::jsonCanonic($v), $valoare)) . ']';
        }
        return self::jsonScalar($valoare);
    }

    /** Cheie publică/privată în text (base64) → octeți; null dacă nu se poate citi. */
    public static function decodeazaBase64(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        // Acceptăm și varianta base64url (fără padding), cum o dă Node pentru JWK.
        $text = strtr($text, '-_', '+/');
        $rest = strlen($text) % 4;
        if ($rest !== 0) {
            $text .= str_repeat('=', 4 - $rest);
        }
        $octeti = base64_decode($text, true);
        return $octeti === false ? null : $octeti;
    }

    /** Scrie un fișier prin fișier temporar + rename, ca nimeni să nu citească o jumătate. */
    public static function scrieAtomic(string $cale, string $continut): bool
    {
        $temporar = $cale . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporar, $continut, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporar, 0660);
        if (!@rename($temporar, $cale)) {
            @unlink($temporar);
            return false;
        }
        return true;
    }

    private static function config(): array
    {
        if (self::$config === null) {
            $app = require __DIR__ . '/../../config/app.php';
            $licenta = (array) ($app['licenta'] ?? []);
            self::$config = self::construiesteConfig(
                (string) ($licenta['dosar'] ?? ''),
                (string) ($licenta['cheie_publica'] ?? ''),
                (string) ($licenta['site'] ?? '')
            );
        }
        return self::$config;
    }

    private static function construiesteConfig(string $dosar, string $cheiText, string $site): array
    {
        $dosar = rtrim(trim($dosar), '/');
        if ($dosar === '') {
            $dosar = self::dosarImplicit();
        }
        $chei = [];
        $eroare = '';
        foreach (explode(',', $cheiText) as $bucata) {
            if (trim($bucata) === '') {
                continue;
            }
            $cheie = self::decodeazaBase64($bucata);
            if ($cheie === null || strlen($cheie) !== 32) {
                $eroare = 'LICENTA_CHEIE_PUBLICA conține o cheie care nu e o cheie publică Ed25519 (32 de octeți, base64).';
                continue;
            }
            $chei[] = $cheie;
        }
        return ['dosar' => $dosar, 'chei' => $chei, 'site' => trim($site), 'eroare_chei' => $eroare];
    }

    private static function dosarImplicit(): string
    {
        return dirname(__DIR__, 2) . '/storage/licenta';
    }

    private static function citeste(string $cale): ?string
    {
        if (!is_file($cale)) {
            return null;
        }
        $continut = @file_get_contents($cale);
        return $continut === false ? null : $continut;
    }

    private static function esteDataIso(string $text): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $text) !== 1) {
            return false;
        }
        try {
            new DateTimeImmutable($text);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function jsonScalar(mixed $valoare): string
    {
        // Ca `JSON.stringify`: nici „/”, nici diacriticele, nici U+2028/U+2029 nu se scriu ca \u….
        return (string) json_encode($valoare, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
            | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Tabloul are cheile 0, 1, 2… în ordine? (Ca `array_is_list`, care există
     * abia din PHP 8.1; scris când PHP-ul minim era 8.0, acum 8.3.)
     */
    private static function esteLista(array $date): bool
    {
        $asteptat = 0;
        foreach ($date as $cheie => $_) {
            if ($cheie !== $asteptat++) {
                return false;
            }
        }
        return true;
    }

    /** Tablou asociativ → stdClass, recursiv (listele rămân liste). */
    private static function caObiect(array $date): stdClass
    {
        $obiect = new stdClass();
        foreach ($date as $cheie => $valoare) {
            if (is_array($valoare)) {
                $valoare = (self::esteLista($valoare) && $valoare !== []) ? array_map(
                    static fn ($v) => is_array($v) && !self::esteLista($v) ? self::caObiect($v) : $v,
                    $valoare
                ) : self::caObiect($valoare);
            }
            $obiect->{$cheie} = $valoare;
        }
        return $obiect;
    }
}
