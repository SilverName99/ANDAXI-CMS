<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Filtrul anti-spam al recenziilor de produs (WP-21: C10, din Biovitality 8ba8ddd și 0c682b3).
 *
 * Recenziile suspecte nu se pierd: intră în bază marcate ca spam (`product_reviews.is_spam`, `spam_reason`, migrarea
 * recenzii/0030), ca să poată fi verificate și, la nevoie, recuperate din Admin → Recenzii → Spam. Ce se schimbă e că
 * nu mai ajung în lista „În așteptare”, unde se amestecau cu recenziile reale.
 *
 * Semnalele: câmpul-capcană (`review_website`, ascuns vizitatorilor, completat de roboți), formularul trimis la mai
 * puțin de 3 secunde de la deschidere (`review_opened_at`), peste 4 recenzii pe oră de la aceeași adresă (după regula
 * `IpClient`: adresa reală a conexiunii, `X-Forwarded-For` doar de la un proxy de încredere, IPv6 pe rețeaua /64;
 * codul Biovitality credea primul `X-Forwarded-For`, pe care îl scrie oricine), același text în ultimele 30 de zile,
 * tipare de atac automat (SQLi, XSS, JNDI), prea multe linkuri, BBCode, domenii de email de test sau temporare, nume
 * generate automat și texte fără cuvinte, cu „=”.
 *
 * Fără coloanele noi (migrarea n-a rulat încă): recenzia se salvează ca înainte, fără marcaj (nicio schemă din mers).
 */
final class ReviewGuard
{
    /** Câte recenzii acceptăm de la aceeași adresă într-o oră. */
    private const LIMITA_PE_IP_PE_ORA = 4;

    /** Sub atâtea secunde de la deschiderea formularului, e robot. */
    private const SECUNDE_MINIME = 3;

    /**
     * Tipare de scanare automată. Sunt bucăți de payload: nu apar în text scris de om, deci nu riscăm recenzii reale
     * marcate greșit.
     */
    private const TIPARE_ATAC = [
        '/\bpg_sleep\s*\(/i',
        '/\bdbms_pipe\.receive_message\b/i',
        '/\bwaitfor\s+delay\b/i',
        '/\bbenchmark\s*\(\s*\d+/i',
        '/\bsleep\s*\(\s*\d+\s*\)/i',
        '/\bunion\s+(all\s+)?select\b/i',
        '/\binformation_schema\b/i',
        '/\bxp_cmdshell\b/i',
        '/\bselect\b.{0,40}\bfrom\b.{0,40}\bwhere\b/i',
        '/\bor\s+\d+\s*=\s*\(\s*select\b/i',
        '/\bchr\s*\(\s*\d+\s*\)\s*\|\|/i',
        '/<\s*script\b/i',
        '/\bon(error|load)\s*=/i',
        '/\$\{\s*jndi\s*:/i',
        '/\.\.\/\.\.\//',
        // Injecțiile „boolean-based”, cele mai dese în valurile de spam (-1" OR 2+312-312-1=0+0+0+1 --): fără `union`,
        // `sleep` sau `select`, deci treceau de tiparele clasice de mai sus.
        '/\b(or|and)\s+[\d\s()+-]{1,40}=[\d\s()+-]{1,40}/i',
        '/[\'"]\s*=\s*[\'"]/',
        '/^\s*-\s*\d+\s*[\'"]?\s*(or|and)\b/i',
        '/[\'"]\s*(--|#)\s*$/m',
        '/;\s*(select|insert|update|delete|drop|alter)\b/i',
        '/\bor\s+[\'"][^\'"]{2,}[\'"]\s*=/i',
    ];

    /** Domenii de test sau de email temporar: nimeni real nu lasă recenzii de pe ele. */
    private const DOMENII_SUSPECTE = [
        'example.com', 'example.org', 'example.net', 'test.com', 'mailinator.com',
        'yopmail.com', 'guerrillamail.com', 'sharklasers.com', 'tempmail.com',
        'temp-mail.org', 'trashmail.com', '10minutemail.com', 'dispostable.com',
    ];

    /** Adresa vizitatorului, după regula comună (App\Support\IpClient), tăiată la lungimea coloanei. */
    public static function ip(): string
    {
        return mb_substr(IpClient::adresa(), 0, 64);
    }

    /**
     * Motivul pentru care recenzia arată a spam, sau null dacă e curată. $date: name, text, email, ip, honeypot,
     * opened_at. Fără $db se sar verificările care au nevoie de istoric (limita pe adresă și textul duplicat).
     *
     * @param array<string, mixed> $date
     */
    public static function motiv(array $date, ?PDO $db = null): ?string
    {
        if (trim((string) ($date['honeypot'] ?? '')) !== '') {
            return 'câmp-capcană completat';
        }

        $deschisLa = (int) ($date['opened_at'] ?? 0);
        if ($deschisLa > 0 && (time() - $deschisLa) < self::SECUNDE_MINIME) {
            return 'formular trimis prea repede';
        }

        $text = (string) ($date['text'] ?? '');
        $motivContinut = self::motivDinContinut((string) ($date['name'] ?? ''), $text, (string) ($date['email'] ?? ''));
        if ($motivContinut !== null) {
            return $motivContinut;
        }

        if (!$db instanceof PDO) {
            return null;
        }

        $ip = trim((string) ($date['ip'] ?? ''));
        if ($ip !== '') {
            try {
                [$conditie, $parametri] = IpClient::conditieLimita('ip_address', $ip);
                $stmt = $db->prepare('SELECT COUNT(*) FROM product_reviews WHERE ' . $conditie
                    . ' AND created_at >= (NOW() - INTERVAL 1 HOUR)');
                $stmt->execute($parametri);
                if ((int) $stmt->fetchColumn() >= self::LIMITA_PE_IP_PE_ORA) {
                    return 'prea multe recenzii de la aceeași adresă IP';
                }
            } catch (Throwable) {
                // Fără coloana ip_address (migrarea n-a rulat): fără limită.
            }
        }

        $textCurat = trim($text);
        if ($textCurat !== '') {
            try {
                $stmt = $db->prepare('SELECT COUNT(*) FROM product_reviews WHERE review_text = :text
                    AND created_at >= (NOW() - INTERVAL 30 DAY)');
                $stmt->execute(['text' => $textCurat]);
                if ((int) $stmt->fetchColumn() > 0) {
                    return 'text identic cu o recenzie trimisă recent';
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /** Doar regulile care se pot aplica și unei recenzii deja salvate (butonul „Detectează spamul”). */
    public static function motivDinContinut(string $nume, string $text, string $email = ''): ?string
    {
        $totul = $nume . ' ' . $text;
        foreach (self::TIPARE_ATAC as $tipar) {
            if (preg_match($tipar, $totul) === 1) {
                return 'tipar de atac automat';
            }
        }

        if (preg_match_all('#https?://|www\.#i', $text) >= 2) {
            return 'prea multe linkuri';
        }
        if (preg_match('/\[url[=\]]|\[link[=\]]/i', $text) === 1) {
            return 'cod BBCode de spam';
        }

        $emailCurat = mb_strtolower(trim($email));
        if ($emailCurat !== '' && str_contains($emailCurat, '@')) {
            $domeniu = substr((string) strrchr($emailCurat, '@'), 1);
            if (in_array($domeniu, self::DOMENII_SUSPECTE, true)) {
                return 'adresă de email de test sau temporară';
            }
        }

        if (self::numePareGenerat($nume)) {
            return 'nume generat automat';
        }

        // Text alcătuit mai mult din semne decât din cuvinte, cu semnul egal în el: forma unui payload, nu a unei păreri.
        $lungime = mb_strlen(trim($text));
        if ($lungime >= 15 && str_contains($text, '=')) {
            $litere = preg_match_all('/\p{L}/u', $text);
            if ($litere !== false && $litere / max(1, $lungime) < 0.5) {
                return 'text fără cuvinte, doar semne';
            }
        }

        return null;
    }

    /**
     * Nume care n-au fost tastate de un om („xsjyBldb”, „fnfOzvSR fnfOzvSR”): fără vocale, sau puține vocale și
     * majuscule împrăștiate prin cuvânt. Numele românești reale au vocale din belșug, deci pragul nu le atinge.
     */
    private static function numePareGenerat(string $nume): bool
    {
        foreach (preg_split('/\s+/', trim($nume)) ?: [] as $bucata) {
            $curat = preg_replace('/[^a-zA-Z]/', '', $bucata) ?? '';
            $lungime = strlen($curat);
            if ($lungime < 6) {
                continue;
            }
            $vocale = preg_match_all('/[aeiouy]/i', $curat);
            if ($vocale === 0) {
                return true;
            }
            $majusculeInterioare = preg_match_all('/(?<!^)[A-Z]/', $curat);
            if ($vocale / $lungime < 0.3 && $majusculeInterioare >= 2) {
                return true;
            }
        }
        return false;
    }

    /**
     * Marchează ca spam recenziile deja primite care se potrivesc regulilor de conținut (implicit doar cele
     * neaprobate). Întoarce câte au fost marcate; fără coloanele noi, 0.
     */
    public static function marcheazaExistente(PDO $db, bool $doarNeaprobate = true): int
    {
        try {
            $randuri = $db->query('SELECT id, user_name, user_email, review_text FROM product_reviews WHERE is_spam = 0'
                . ($doarNeaprobate ? ' AND is_approved = 0' : ''))->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $marcheaza = $db->prepare('UPDATE product_reviews SET is_spam = 1, spam_reason = :motiv WHERE id = :id');
        } catch (Throwable) {
            return 0;
        }
        $marcate = 0;
        foreach ($randuri as $rand) {
            $motiv = self::motivDinContinut((string) ($rand['user_name'] ?? ''), (string) ($rand['review_text'] ?? ''),
                (string) ($rand['user_email'] ?? ''));
            if ($motiv === null) {
                continue;
            }
            $marcheaza->execute(['motiv' => $motiv, 'id' => (int) ($rand['id'] ?? 0)]);
            $marcate++;
        }
        return $marcate;
    }

    /**
     * Golește adresele recenziilor mai vechi decât `recenzii_ip_zile` (OD-15: implicit 90 de zile; 0 = niciodată). Rulează
     * la o recenzie nouă și la deschiderea listei din admin, cel mult o dată pe cerere; limita anti-spam are nevoie doar de
     * ultima oră. Fără coloană (recenzii/0030 nerulată): nimic.
     *
     * @param array<string, mixed> $setari
     */
    public static function curataAdreseVechi(PDO $db, array $setari): void
    {
        static $facut = false;
        $zile = (int) ($setari['recenzii_ip_zile'] ?? 90);
        if ($facut || $zile <= 0) {
            return;
        }
        $facut = true;
        try {
            $db->prepare('UPDATE product_reviews SET ip_address = NULL WHERE ip_address IS NOT NULL AND created_at < (NOW() - INTERVAL '
                . $zile . ' DAY)')->execute();
        } catch (Throwable) {
            // Fără coloana ip_address: nimic de golit.
        }
    }

    /**
     * Bulina din meniul adminului (`insigna` din catalog, pluginul recenzii; din Biovitality 84e29a9): câte recenzii
     * așteaptă aprobare, fără spam. 0 (nicio bulină) cu setarea recenzii_insigna_meniu oprită sau fără bază.
     */
    public static function insignaMeniu(): int
    {
        static $numar = null;
        if ($numar !== null) {
            return $numar;
        }
        $numar = 0;
        try {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            $db = Database::connection((array) ($config['db'] ?? []));
            if (!$db instanceof PDO || (string) (Settings::all($db)['recenzii_insigna_meniu'] ?? '1') !== '1') {
                return $numar;
            }
            try {
                $numar = (int) $db->query('SELECT COUNT(*) FROM product_reviews WHERE is_approved = 0 AND is_spam = 0')->fetchColumn();
            } catch (Throwable) {
                // Fără coloana is_spam (recenzii/0030 încă nerulată): toate neaprobatele.
                $numar = (int) $db->query('SELECT COUNT(*) FROM product_reviews WHERE is_approved = 0')->fetchColumn();
            }
        } catch (Throwable) {
            $numar = 0;
        }
        return $numar;
    }

    /**
     * Câmpurile ascunse ale oricărui formular de recenzie: capcana (în afara ecranului, fără tab, fără completare
     * automată) și momentul deschiderii formularului.
     */
    public static function campuriFormular(): string
    {
        return '<input type="text" name="review_website" value="" tabindex="-1" autocomplete="off"'
            . ' aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">'
            . '<input type="hidden" name="review_opened_at" value="' . time() . '">';
    }
}
