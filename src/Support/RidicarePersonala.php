<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Ridicarea personală a comenzii de la sediul magazinului (portul 807058e, fe122fd; pluginul livrare_fan).
 *
 * Clientul bifează în checkout „Ridicare personală de la sediu”: comanda nu mai are transport (0 lei, fără prag, fără
 * taxă de km suplimentari, fără tarif FAN) și nu pleacă niciodată la curier — niciun AWB, pe niciun drum (buton,
 * emitere în masă, aprobare din ERP, reîncercări, emiterea automată, previzualizare). Rambursul („cod”) rămâne codul
 * intern al metodei, dar clientul îl vede ca „Plată la ridicare (numerar sau card)”: banii se iau la sediu, nu de curier.
 *
 * Opțiunea e o decizie de magazin: o bifă proprie în Setări livrare (pagina pluginului livrare_fan), oprită implicit,
 * plus adresa sediului (fără ea opțiunea nu apare: codul comun n-are o adresă implicită a vreunui client) și programul.
 * Cât e oprită (sau pluginul livrării nu primește lucruri noi), checkout-ul arată exact ca înainte, iar o cerere trimisă
 * de mână cu `ridicare_personala` e refuzată.
 *
 * Pe comandă alegerea se îngheață în `orders.pickup_in_person` (+ adresa din acel moment în `pickup_address`), ca la
 * FANbox: setările se pot schimba, comanda trebuie să rămână explicabilă. Coloanele le face migrarea livrare_fan/0052.
 * O comandă cu ridicare rămâne fără AWB oricare ar fi starea pluginului sau a opțiunii.
 */
final class RidicarePersonala
{
    /** Cheia din sesiune: clientul a bifat ridicarea pentru comanda curentă. */
    public const SESSION_KEY = 'checkout_ridicare_sediu';

    /** Valoarea din `orders.shipping_method` (informativă; adevărul e `pickup_in_person`). */
    public const SHIPPING_METHOD = 'ridicare_sediu';

    /** Șablonul de email „Comanda e gata de ridicare”. */
    public const EMAIL_GATA = 'pickup_ready';

    public const ETICHETA = 'Ridicare personală de la sediu';
    public const ETICHETA_FARA_AWB = 'Ridicare personală — fără AWB';
    public const ETICHETA_PLATA = 'Plată la ridicare (numerar sau card)';

    /** Mesajul cu care orice drum de emitere a AWB-ului refuză comanda. */
    public const REFUZ_AWB = 'Comanda e cu ridicare personală de la sediu: nu se emite AWB și nu pleacă la curier.';

    /** Bifa din Setări livrare e pusă (fără să se uite la adresă sau la plugin). */
    public static function bifata(array $settings): bool
    {
        return (string) ($settings['shipping_pickup_enabled'] ?? '0') === '1';
    }

    /**
     * Magazinul oferă acum ridicarea de la sediu? Bifa din Setări livrare, adresa completată și pluginul livrării
     * pornit (cât „se oprește”, nimic nou nu mai începe; comenzile cu ridicare deja plasate rămân cum sunt).
     */
    public static function activa(array $settings): bool
    {
        return self::bifata($settings) && self::adresa($settings) !== '' && Plugins::permiteInceput('livrare_fan');
    }

    /** Adresa sediului din Setări livrare; gol = nesetată (atunci opțiunea nu apare). */
    public static function adresa(array $settings): string
    {
        return trim((string) ($settings['shipping_pickup_address'] ?? ''));
    }

    /** Programul de ridicare, text liber; gol = nespecificat. */
    public static function program(array $settings): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) ($settings['shipping_pickup_program'] ?? '')));
    }

    /**
     * Județul și localitatea sediului, pentru ERP (după ele alege depozitul) și BT: ale expeditorului FAN din Setări
     * livrare → Curier FAN (scrise ca în nomenclatorul FAN). Codul vechi le avea scrise în cod; codul comun nu
     * are sediul vreunui client. Gol = nesetate (ERP-ul și BT primesc atunci localitatea de facturare).
     *
     * @return array{judet: string, localitate: string}
     */
    public static function localitateSediu(array $settings): array
    {
        return [
            'judet' => trim((string) ($settings['fan_sender_county'] ?? '')),
            'localitate' => trim((string) ($settings['fan_sender_locality'] ?? '')),
        ];
    }

    /** Reține bifa clientului în sesiune (checkout). */
    public static function alege(bool $da): void
    {
        if ($da) {
            $_SESSION[self::SESSION_KEY] = true;
            return;
        }
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Clientul a bifat ridicarea (indiferent dacă opțiunea mai e activă). */
    public static function bifataInSesiune(): bool
    {
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Comanda curentă din checkout e cu ridicare personală? Doar cât timp opțiunea e activă: oprită din setări, o bifă
     * rămasă în sesiune nu mai scade transportul.
     */
    public static function aleasa(array $settings): bool
    {
        return self::bifataInSesiune() && self::activa($settings);
    }

    /** Rândul comenzii e cu ridicare personală? */
    public static function esteComanda(array $order): bool
    {
        return (int) ($order['pickup_in_person'] ?? 0) === 1;
    }

    /**
     * Comanda cu id-ul dat e cu ridicare personală? Citește direct coloana, ca gărzile de AWB să nu depindă de ce a
     * selectat fiecare funcție. O coloană lipsă (bază fără migrarea livrare_fan/0052) înseamnă că nu există comenzi cu
     * ridicare.
     */
    public static function esteComandaId(PDO $db, int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        try {
            $stmt = $db->prepare('SELECT pickup_in_person FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $orderId]);
            return (int) ($stmt->fetchColumn() ?: 0) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Coloanele ridicării ale unei comenzi (`pickup_in_person`, `pickup_address`, `pickup_ready_notified_at`), de
     * adăugat la un rând citit cu interogarea de dinainte. Pe o bază fără migrarea livrare_fan/0052 (codul nou urcat,
     * migrările încă nerulate): [] — comanda nu e cu ridicare, iar emailurile, AWB-ul și restul merg ca înainte.
     *
     * @return array<string, mixed>
     */
    public static function campuriComanda(PDO $db, int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        try {
            $stmt = $db->prepare('SELECT pickup_in_person, pickup_address, pickup_ready_notified_at FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $orderId]);
            $rand = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($rand) ? $rand : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Adresa de pe comandă (înghețată la plasare), altfel cea din setări. */
    public static function adresaComenzii(array $order, array $settings): string
    {
        $adresa = trim((string) ($order['pickup_address'] ?? ''));
        return $adresa !== '' ? $adresa : self::adresa($settings);
    }

    /**
     * Caseta cu adresa și programul, pentru emailuri și pagina de confirmare. Stiluri inline și tabel: trebuie să arate
     * la fel în Gmail.
     */
    public static function htmlCaseta(string $adresa, string $program, string $titlu, string $text = ''): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $randuri = '<tr><td style="padding:0 0 6px;color:#0f172a;font-size:16px;font-weight:700;">' . $e($titlu) . '</td></tr>';
        if ($text !== '') {
            $randuri .= '<tr><td style="padding:0 0 8px;color:#334155;font-size:14px;line-height:1.5;">' . $e($text) . '</td></tr>';
        }
        if ($adresa !== '') {
            $randuri .= '<tr><td style="padding:0 0 4px;color:#334155;font-size:14px;line-height:1.5;">'
                . '<strong>Adresa:</strong> ' . $e($adresa) . '</td></tr>';
        }
        if ($program !== '') {
            $randuri .= '<tr><td style="padding:0;color:#334155;font-size:14px;line-height:1.5;">'
                . '<strong>Program:</strong><br>' . nl2br($e($program)) . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="border-collapse:separate;width:100%;margin:14px 0;background:#f2f9f8;'
            . 'border:1px solid #cbe0df;border-radius:12px;"><tr><td style="padding:14px 16px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="border-collapse:collapse;width:100%;">' . $randuri . '</table>'
            . '</td></tr></table>';
    }

    /** Textul confirmării comenzii (pagina de mulțumire și emailul „Comandă nouă”). */
    public const TEXT_CONFIRMARE = 'Comanda nu se livrează prin curier și nu are cost de transport. Te anunțăm pe email când e gata de ridicare.';
}
