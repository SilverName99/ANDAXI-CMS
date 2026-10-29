<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Identitatea site-ului acolo unde codul avea scris numele unui client (WP-16, VT-C6, DB-05, DB-06): numele
 * site-ului, numele expeditorului emailurilor, adresa de contact și gazda. Fiecare vine din setări, apoi din datele
 * site-ului (pachetul), apoi din configurare; nucleul nu mai are niciun nume de client ca rezervă.
 *
 * Un site existent își păstrează valorile de dinainte prin pachetul lui (`site-uri/<site>/seo.json` → organizatie.nume) și prin
 * înghețarea setărilor (`setari-inghetate.json`: numele expeditorului, destinatarii formularului de contact).
 */
final class Brand
{
    /**
     * Numele site-ului: Setări magazin → SEO Google (`store_name`), altfel seo.json → organizatie.nume al site-ului,
     * altfel APP_NAME, altfel gazda din APP_URL (aceeași regulă ca titlurile paginilor, SeoMeta::numeMagazin).
     *
     * @param array<string, mixed> $setari
     */
    public static function numeSite(array $setari): string
    {
        return SeoMeta::numeMagazin($setari);
    }

    /**
     * Numele expeditorului emailurilor: Email-uri → Setări trimitere (`order_email_from_name`), altfel numele site-ului.
     *
     * @param array<string, mixed> $setari
     */
    public static function numeExpeditor(array $setari): string
    {
        $nume = trim((string) ($setari['order_email_from_name'] ?? ''));
        return $nume !== '' ? $nume : self::numeSite($setari);
    }

    /**
     * Adresa de contact a site-ului (unde ajung mesajele formularului de contact și alertele fără alt destinatar):
     * `contact_email`, altfel prima adresă validă din `contact_form_recipients`, altfel adresa primului administrator
     * general. Gol doar dacă nu există niciuna (atunci nu se trimite nimic, mesajul rămâne în admin).
     *
     * @param array<string, mixed> $setari
     */
    public static function emailContact(?PDO $db, array $setari): string
    {
        $adresa = trim((string) ($setari['contact_email'] ?? ''));
        if ($adresa !== '' && filter_var($adresa, FILTER_VALIDATE_EMAIL)) {
            return $adresa;
        }
        $lista = self::adrese((string) ($setari['contact_form_recipients'] ?? ''));
        if ($lista !== []) {
            return $lista[0];
        }
        return $db instanceof PDO ? self::emailAdministrator($db) : '';
    }

    /**
     * Adresele valide dintr-o listă scrisă în setări (separate prin virgulă, punct și virgulă sau spații), fără
     * dubluri (litere mari și mici la fel; rămâne forma scrisă ultima), în ordinea primei apariții. Aceeași regulă ca
     * destinatarii formularului de contact și ai alertelor BT.
     *
     * @return list<string>
     */
    public static function adrese(string $lista): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($lista)) ?: [] as $adresa) {
            $adresa = trim((string) $adresa);
            if ($adresa !== '' && filter_var($adresa, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($adresa)] = $adresa;
            }
        }
        return array_values($out);
    }

    /**
     * Gazda site-ului, fără „www.” (din APP_URL, altfel din cererea de acum): „exemplu.ro”. Gol în linia de comandă
     * fără APP_URL.
     */
    public static function gazda(): string
    {
        $gazda = strtolower((string) parse_url(AppUrl::base(), PHP_URL_HOST));
        return str_starts_with($gazda, 'www.') ? substr($gazda, 4) : $gazda;
    }

    /**
     * Textul linkului spre cont din sertarul de meniu: `header_account_label` (VT-C6), altfel „Contul meu”.
     *
     * @param array<string, mixed> $setari
     */
    public static function etichetaCont(array $setari): string
    {
        $eticheta = trim((string) ($setari['header_account_label'] ?? ''));
        return $eticheta !== '' ? $eticheta : 'Contul meu';
    }

    /** Cheia popup-ului pe un site fără alta în site.json (WP-16, DB-07). */
    public const CHEIE_POPUP_IMPLICITA = 'welcome_popup_inchis';

    /**
     * Cheia din localStorage sub care browserul ține minte că vizitatorul a închis fereastra de bun venit: site.json →
     * cheie_popup_inchis (al unui site existent: cu alt nume, fiecare vizitator care a închis-o ar
     * vedea-o din nou), altfel CHEIE_POPUP_IMPLICITA. O valoare care nu e un nume simplu se ignoră.
     */
    public static function cheiePopupInchis(): string
    {
        $cheie = DateSite::site('cheie_popup_inchis', self::CHEIE_POPUP_IMPLICITA);
        return is_string($cheie) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $cheie) === 1 ? $cheie : self::CHEIE_POPUP_IMPLICITA;
    }

    /** Adresa primului administrator general (cel mai vechi), sau gol. */
    private static function emailAdministrator(PDO $db): string
    {
        try {
            $stmt = $db->prepare('SELECT email FROM admins WHERE role = :rol ORDER BY id ASC LIMIT 1');
            $stmt->execute(['rol' => Auth::ROLE_GENERAL]);
            $adresa = trim((string) ($stmt->fetchColumn() ?: ''));
        } catch (Throwable) {
            return '';
        }
        return filter_var($adresa, FILTER_VALIDATE_EMAIL) ? $adresa : '';
    }
}
