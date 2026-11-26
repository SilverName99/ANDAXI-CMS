<?php

declare(strict_types=1);

namespace App\Support\Automatizari;

use App\Support\Brand;
use App\Support\NewsletterService;
use App\Support\OrderMailer;
use App\Support\Settings;
use PDO;
use RuntimeException;

/**
 * Emailurile automatizărilor: conținutul din builder (aceleași blocuri și același randat ca newsletterul), codurile
 * („{{prenume}}”, „{{cod_reducere}}” …), subsolul de dezabonare semnat al abonatului (obligatoriu, ca la campanii), antetele
 * de dezabonare „cu un clic” și urmărirea (pixelul de deschidere și linkurile de clic, semnate). Un abonat cu
 * `fara_urmarire` = 1 primește emailul fără pixel și fără linkuri rescrise; testul nu e urmărit niciodată.
 *
 * Totul pleacă prin OrderMailer::sendCustom (același furnizor, istoricul „Emailuri trimise”, tipul `newsletter_automatizare`).
 */
final class Trimitere
{
    public const TIP_ISTORIC = 'newsletter_automatizare';

    /** Valorile codurilor pentru o rulare (sau, la test, exemple). @return array<string, string> */
    public static function valoriCoduri(array $context, string $email, array $setari): array
    {
        $cod = is_array($context['cod_reducere'] ?? null) ? $context['cod_reducere'] : [];
        $prenume = trim((string) ($context['prenume'] ?? ''));
        $total = $context['total_comanda'] ?? null;
        return [
            '{{prenume}}' => $prenume !== '' ? $prenume : 'acolo',
            '{{email}}' => $email,
            '{{cod_reducere}}' => (string) ($cod['cod'] ?? ''),
            '{{cod_reducere_valoare}}' => (string) ($cod['valoare_text'] ?? ''),
            '{{cod_reducere_expira}}' => (string) ($cod['expira_text'] ?? ''),
            '{{numar_comanda}}' => (string) ($context['numar_comanda'] ?? ''),
            '{{total_comanda}}' => is_numeric($total) ? number_format((float) $total, 2, ',', '.') . ' lei' : '',
            '{{magazin}}' => Brand::numeSite($setari),
        ];
    }

    /** Codurile din HTML (valorile scăpate) și din subiect (text simplu). */
    public static function inlocuiesteCodurile(string $text, array $valori, bool $html): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        $inlocuiri = [];
        foreach ($valori as $cod => $valoare) {
            $inlocuiri[$cod] = $html ? htmlspecialchars($valoare, ENT_QUOTES, 'UTF-8') : $valoare;
        }
        return strtr($text, $inlocuiri);
    }

    /**
     * HTML-ul emailului pentru un abonat: blocurile randate ca la trimiterea unei campanii (produsele „recomandate” alese
     * pentru adresa lui), codurile, subsolul cu linkul lui de dezabonare.
     *
     * @return array{subiect: string, html: string, dezabonare: string}
     */
    public static function compune(array $email, array $abonat, array $context, array $setari, bool $test = false): array
    {
        $blocuri = json_decode((string) ($email['blocks_json'] ?? ''), true);
        $subiect = (string) ($email['subiect'] ?? '');
        $html = is_array($blocuri) && $blocuri !== []
            ? NewsletterService::renderHtmlFromBlocks($blocuri, $subiect, true, true)
            : (string) ($email['html_content'] ?? '');
        $html = NewsletterService::produseAbonat($html, (string) $abonat['email']);
        $valori = self::valoriCoduri($context, (string) $abonat['email'], $setari);
        $html = self::inlocuiesteCodurile($html, $valori, true);
        $subiect = self::inlocuiesteCodurile($subiect, $valori, false);
        $dezabonare = $test ? '#' : NewsletterService::unsubscribeUrlForSubscriber(['id' => $abonat['id'], 'email' => $abonat['email']]);
        $html = NewsletterService::withNewsletterFooter($html, $test ? null : $dezabonare, $test);
        return ['subiect' => $subiect !== '' ? $subiect : 'Email', 'html' => $html, 'dezabonare' => $dezabonare];
    }

    /** Pixelul și linkurile de clic, semnate cu id-ul trimiterii. */
    public static function cuUrmarire(string $html, int $trimitereId): string
    {
        $baza = rtrim(NewsletterService::appBaseUrl(), '/');
        if ($baza === '' || $trimitereId <= 0) {
            return $html;
        }
        $html = (string) (preg_replace_callback(
            '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/si',
            static function (array $m) use ($baza, $trimitereId): string {
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')
                    || str_contains($url, '/newsletter/unsubscribe/') || str_contains($url, '/automatizari/c/')) {
                    return $m[0];
                }
                $token = NewsletterService::semneaza('automatizare|c|' . $trimitereId . '|' . $url);
                $nou = $baza . '/automatizari/c/' . $trimitereId . '/' . $token . '?u=' . rawurlencode($url);
                return '<a ' . $m[1] . 'href="' . htmlspecialchars($nou, ENT_QUOTES, 'UTF-8') . '"' . $m[3] . '>';
            },
            $html
        ) ?? $html);
        $src = $baza . '/automatizari/d/' . $trimitereId . '/' . NewsletterService::semneaza('automatizare|d|' . $trimitereId);
        $pixel = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" style="display:block;width:1px;height:1px;border:none;opacity:0;" alt="">';
        $poz = strripos($html, '</body>');
        return $poz !== false ? substr($html, 0, $poz) . $pixel . substr($html, $poz) : $html . $pixel;
    }

    /** Trimite emailul unui pas către abonat (aruncă la eșec, ca OrderMailer). */
    public static function trimite(PDO $db, array $email, array $abonat, array $rulare, string $pas, int $trimitereId, array $setari): void
    {
        if (NewsletterService::appBaseUrl() === '') {
            // Fără adresa site-ului linkul de dezabonare ar fi mort: nu pleacă nimic (ca la campanii).
            throw new RuntimeException('Adresa site-ului (APP_URL) lipsește, deci linkul de dezabonare n-ar funcționa.');
        }
        $compus = self::compune($email, $abonat, (array) $rulare['context'], $setari);
        $html = !empty($abonat['fara_urmarire']) ? $compus['html'] : self::cuUrmarire($compus['html'], $trimitereId);
        OrderMailer::sendCustom($abonat['email'], $compus['subiect'], $html, $setari, $db, [
            'email_type' => self::TIP_ISTORIC,
            'source' => 'automatizare',
            'meta' => ['automatizare_id' => (int) $rulare['automatizare_id'], 'pas' => $pas, 'email_id' => (int) $email['id']],
            'headers' => NewsletterService::anteteListaDezabonare($compus['dezabonare']),
        ]);
    }

    /**
     * Emailul de test al unui pas (din editor): la o adresă dată, cu exemple în locul codurilor, subsolul de test, fără
     * urmărire și fără să atingă vreo rulare sau numărătoare.
     */
    public static function trimiteTest(PDO $db, array $email, string $adresa, array $setari): void
    {
        $adresa = strtolower(trim($adresa));
        if (!filter_var($adresa, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresa de test nu e validă.');
        }
        $context = ['prenume' => 'Ana', 'numar_comanda' => '100001', 'total_comanda' => 149.9,
            'cod_reducere' => ['cod' => 'EXEMPLU10', 'valoare_text' => '10%', 'expira_text' => date('d.m.Y', time() + 14 * 86400)]];
        $compus = self::compune($email, ['id' => 0, 'email' => $adresa], $context, $setari, true);
        OrderMailer::sendCustom($adresa, '[Test] ' . $compus['subiect'], $compus['html'], $setari, $db, [
            'email_type' => 'newsletter_automatizare_test',
            'source' => 'automatizare_test',
            'meta' => ['automatizare_id' => (int) ($email['automatizare_id'] ?? 0), 'email_id' => (int) ($email['id'] ?? 0)],
        ]);
    }

    /** „Anunță-mă pe email”: un email scurt către administrator (destinatarii pasului sau adresa de contact a site-ului). */
    public static function anuntaAdmin(PDO $db, array $automatizare, array $rulare, array $setariPas, array $setari): void
    {
        $destinatari = array_values(array_filter(array_map('trim', explode(',', (string) ($setariPas['destinatari'] ?? '')))));
        if ($destinatari === []) {
            $contact = Brand::emailContact($db, $setari);
            $destinatari = $contact !== '' ? [$contact] : [];
        }
        if ($destinatari === []) {
            throw new RuntimeException('Nu există o adresă a administratorului (Setări site → contact).');
        }
        $valori = self::valoriCoduri((array) $rulare['context'], (string) $rulare['email'], $setari);
        $subiect = trim((string) ($setariPas['subiect'] ?? ''));
        $subiect = self::inlocuiesteCodurile($subiect !== '' ? $subiect : 'Automatizarea „' . (string) $automatizare['nume'] . '”', $valori, false);
        $mesaj = trim((string) ($setariPas['mesaj'] ?? ''));
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $corp = '<p>' . $e('Automatizarea „' . (string) $automatizare['nume'] . '” a ajuns la pasul „Anunță-mă”.') . '</p>'
            . '<p>' . $e('Abonat: ' . (string) $rulare['email']) . '</p>'
            . ((string) ($valori['{{numar_comanda}}'] ?? '') !== '' ? '<p>' . $e('Comanda: ' . $valori['{{numar_comanda}}']) . '</p>' : '')
            . ($mesaj !== '' ? '<p>' . nl2br($e(self::inlocuiesteCodurile($mesaj, $valori, false))) . '</p>' : '');
        foreach ($destinatari as $adresa) {
            OrderMailer::sendCustom($adresa, $subiect, $corp, $setari, $db, [
                'email_type' => 'automatizare_anunt',
                'source' => 'automatizare',
                'meta' => ['automatizare_id' => (int) $automatizare['id']],
            ]);
        }
    }

    // ── Urmărirea (rutele mereu deschise /automatizari/d/… și /automatizari/c/…) ───────────────────────────────────────

    /** Pixelul: o deschidere numărată doar cu o semnătură bună și cu pluginul pe site. */
    public static function laDeschidere(PDO $db, int $trimitereId, string $token, bool $numara): void
    {
        if ($trimitereId <= 0 || !NewsletterService::semnaturaNoua('automatizare|d|' . $trimitereId, $token) || !$numara) {
            return;
        }
        $db->prepare(
            'UPDATE automatizari_trimiteri SET deschis_la = COALESCE(deschis_la, :acum), deschideri = deschideri + 1 WHERE id = :id'
            . self::DOAR_URMARITI
        )->execute(['acum' => Ceas::sql(), 'id' => $trimitereId]);
    }

    /**
     * Un abonat care a refuzat urmărirea după ce emailul a plecat (cu pixel și linkuri) nu mai e numărat de atunci: pixelul și
     * clicul lui nu scriu nimic (linkul merge mai departe).
     */
    private const DOAR_URMARITI = ' AND NOT EXISTS (SELECT 1 FROM newsletter_subscribers s
        WHERE s.id = automatizari_trimiteri.abonat_id AND s.fara_urmarire = 1)';

    /**
     * Clicul: adresa la care se merge (doar una semnată de noi pentru trimiterea asta) sau null. Un clic e și o deschidere
     * (clientul de email poate bloca pozele).
     */
    public static function laClic(PDO $db, int $trimitereId, string $token, string $url, bool $numara): ?string
    {
        if ($trimitereId <= 0 || $url === '' || !NewsletterService::semnaturaNoua('automatizare|c|' . $trimitereId . '|' . $url, $token)) {
            return null;
        }
        if ($numara) {
            $acum = Ceas::sql();
            try {
                $db->prepare(
                    'UPDATE automatizari_trimiteri SET clic_la = COALESCE(clic_la, :acum), clicuri = clicuri + 1,
                            deschis_la = COALESCE(deschis_la, :acum2) WHERE id = :id' . self::DOAR_URMARITI
                )->execute(['acum' => $acum, 'acum2' => $acum, 'id' => $trimitereId]);
            } catch (\Throwable) {
                // Numărătoarea nu oprește niciodată linkul semnat.
            }
        }
        return $url;
    }

    /** Setările site-ului (pentru cron și editor). */
    public static function setari(PDO $db): array
    {
        return Settings::all($db);
    }
}
