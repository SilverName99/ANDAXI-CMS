<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Layout comun pentru emailurile conturilor și ale pluginurilor (confirmarea adresei, decizia de verificare,
 * confirmarea cotizației …). Produce un document HTML complet, în culorile site-ului, pe care OrderMailer îl trimite ca
 * atare. Portat din vertij `src/Support/BrandedEmail.php` (WP-17, VT-C7), cu culorile și numele din setări în locul celor
 * scrise în cod: `email_brand_color` (începutul degradeului, linkurile), `email_brand_color_2` (sfârșitul degradeului,
 * butonul, numerele pașilor), `email_brand_name`, `email_logo_url`, `email_site_url`. Emailurile comenzilor (OrderMailer,
 * șabloanele din Email-uri) nu-l folosesc: ele rămân cum sunt pe fiecare site.
 *
 * Emailurile sunt citite în clienți care ignoră CSS-ul extern, așa că totul e inline și construit pe tabele.
 */
final class BrandedEmail
{
    /** Culorile implicite, neutre (un site existent le are pe ale lui în setări, prin înghețare). */
    public const CULOARE_IMPLICITA = '#0f766e';
    public const CULOARE_2_IMPLICITA = '#334155';
    private const INK = '#1f2937';
    private const MUTED = '#6b7280';

    /**
     * Numele din antetul și subsolul emailului: `email_brand_name`, altfel numele site-ului (`store_name`), altfel numele
     * expeditorului (`order_email_from_name`), altfel gazda din APP_URL. Niciodată numele unui client scris în cod.
     *
     * @param array<string, mixed> $settings
     */
    public static function brandName(array $settings): string
    {
        foreach (['email_brand_name', 'store_name', 'order_email_from_name'] as $cheie) {
            $name = trim((string) ($settings[$cheie] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return Brand::gazda();
    }

    /**
     * O culoare din setări (`#rrggbb` sau `#rgb`), altfel cea implicită: o valoare greșită nu ajunge în stilul emailului.
     *
     * @param array<string, mixed> $settings
     */
    public static function culoare(array $settings, string $cheie, string $implicita): string
    {
        $valoare = trim((string) ($settings[$cheie] ?? ''));
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $valoare) === 1 ? $valoare : $implicita;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array{
     *     title: string,
     *     greeting?: string,
     *     intro?: string,
     *     steps?: array<int, array{title: string, text: string}>,
     *     button?: array{label: string, url: string},
     *     fallback_url?: string,
     *     facts?: array<int, array{label: string, value: string}>,
     *     note?: string,
     *     recipient?: string
     * } $parts
     */
    public static function render(array $settings, array $parts): string
    {
        $brand = self::brandName($settings);
        $gradientStart = self::culoare($settings, 'email_brand_color', self::CULOARE_IMPLICITA);
        $gradientEnd = self::culoare($settings, 'email_brand_color_2', self::CULOARE_2_IMPLICITA);
        $logoUrl = trim((string) ($settings['email_logo_url'] ?? ''));
        $siteUrl = rtrim(trim((string) ($settings['email_site_url'] ?? '')), '/');

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $title = $e((string) ($parts['title'] ?? ''));
        $greeting = trim((string) ($parts['greeting'] ?? ''));
        $intro = trim((string) ($parts['intro'] ?? ''));
        $note = trim((string) ($parts['note'] ?? ''));
        $recipient = trim((string) ($parts['recipient'] ?? ''));
        $fallbackUrl = trim((string) ($parts['fallback_url'] ?? ''));

        // --- antet cu logo sau, în lipsa lui, cu numele organizației
        if ($logoUrl !== '') {
            // Logo-ul are text închis la culoare, deci pe gradient devine ilizibil.
            // Îl așezăm pe o „plăcuță” albă: clienții fără border-radius (Outlook)
            // văd un dreptunghi alb, care rămâne perfect lizibil.
            $logoHtml = '<table role="presentation" cellpadding="0" cellspacing="0" align="center"'
                . ' style="margin:0 auto;border-collapse:separate;"><tr>'
                . '<td align="center" style="padding:16px 24px;background:#ffffff;border-radius:16px;">'
                . '<img src="' . $e(self::absoluteUrl($logoUrl, $siteUrl)) . '" alt="' . $e($brand) . '"'
                . ' width="200" style="display:block;margin:0 auto;max-width:200px;height:auto;border:0;">'
                . '</td></tr></table>';
        } else {
            $logoHtml = '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:23px;line-height:1.25;'
                . 'color:#ffffff;font-weight:700;">' . $e($brand) . '</div>';
        }

        $bodyHtml = '';

        if ($greeting !== '') {
            $bodyHtml .= '<p style="margin:0 0 14px;font-size:19px;line-height:1.4;color:' . self::INK
                . ';font-weight:700;">' . $e($greeting) . '</p>';
        }
        if ($intro !== '') {
            $bodyHtml .= '<p style="margin:0 0 22px;font-size:15.5px;line-height:1.65;color:' . self::MUTED
                . ';">' . $e($intro) . '</p>';
        }

        // --- pașii următori, numerotați
        $steps = is_array($parts['steps'] ?? null) ? $parts['steps'] : [];
        if ($steps !== []) {
            $bodyHtml .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="border-collapse:collapse;margin:0 0 24px;">';
            $index = 1;
            foreach ($steps as $step) {
                $stepTitle = trim((string) ($step['title'] ?? ''));
                $stepText = trim((string) ($step['text'] ?? ''));
                if ($stepTitle === '' && $stepText === '') {
                    continue;
                }
                $bodyHtml .= '<tr>'
                    . '<td width="38" valign="top" style="padding:0 12px 16px 0;">'
                    . '<div style="width:28px;height:28px;border-radius:999px;background:' . $gradientEnd
                    . ';color:#ffffff;font-size:14px;font-weight:700;text-align:center;line-height:28px;">'
                    . $index . '</div>'
                    . '</td>'
                    . '<td valign="top" style="padding:0 0 16px;">'
                    . '<div style="font-size:15px;line-height:1.4;color:' . self::INK . ';font-weight:700;">'
                    . $e($stepTitle) . '</div>'
                    . ($stepText !== ''
                        ? '<div style="margin-top:3px;font-size:14px;line-height:1.6;color:' . self::MUTED . ';">'
                            . $e($stepText) . '</div>'
                        : '')
                    . '</td>'
                    . '</tr>';
                $index++;
            }
            $bodyHtml .= '</table>';
        }

        // --- tabel de detalii (categorie, sumă, valabilitate etc.)
        $facts = is_array($parts['facts'] ?? null) ? $parts['facts'] : [];
        if ($facts !== []) {
            $bodyHtml .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
                . ' style="border-collapse:separate;background:#f8fafc;border-radius:12px;margin:0 0 24px;">';
            foreach ($facts as $fact) {
                $factLabel = trim((string) ($fact['label'] ?? ''));
                $factValue = trim((string) ($fact['value'] ?? ''));
                if ($factLabel === '' && $factValue === '') {
                    continue;
                }
                $bodyHtml .= '<tr>'
                    . '<td style="padding:11px 18px;font-size:14px;line-height:1.4;color:' . self::MUTED . ';">'
                    . $e($factLabel) . '</td>'
                    . '<td style="padding:11px 18px;font-size:14.5px;line-height:1.4;color:' . self::INK
                    . ';font-weight:700;text-align:right;">' . $e($factValue) . '</td>'
                    . '</tr>';
            }
            $bodyHtml .= '</table>';
        }

        // --- butonul principal
        $button = is_array($parts['button'] ?? null) ? $parts['button'] : null;
        if ($button !== null) {
            $buttonLabel = trim((string) ($button['label'] ?? ''));
            $buttonUrl = trim((string) ($button['url'] ?? ''));
            if ($buttonLabel !== '' && $buttonUrl !== '') {
                $bodyHtml .= '<table role="presentation" cellpadding="0" cellspacing="0" align="center"'
                    . ' style="margin:0 auto 20px;"><tr><td align="center"'
                    . ' style="border-radius:12px;background:' . $gradientEnd . ';">'
                    . '<a href="' . $e($buttonUrl) . '"'
                    . ' style="display:inline-block;padding:14px 32px;border-radius:12px;background:'
                    . $gradientEnd . ';color:#ffffff;font-size:16px;font-weight:700;line-height:1.2;'
                    . 'text-decoration:none;">'
                    . '<span style="color:#ffffff;text-decoration:none;">' . $e($buttonLabel) . '</span>'
                    . '</a>'
                    . '</td></tr></table>';

                if ($fallbackUrl === '') {
                    $fallbackUrl = $buttonUrl;
                }
            }
        }

        if ($fallbackUrl !== '') {
            $bodyHtml .= '<p style="margin:0 0 6px;font-size:13px;line-height:1.5;color:' . self::MUTED
                . ';text-align:center;">Dacă butonul nu funcționează, copiază acest link în browser:</p>'
                . '<p style="margin:0 0 20px;font-size:12.5px;line-height:1.6;text-align:center;word-break:break-all;">'
                . '<a href="' . $e($fallbackUrl) . '" style="color:' . $gradientStart . ';">'
                . '<span style="color:' . $gradientStart . ';">' . $e($fallbackUrl) . '</span>'
                . '</a></p>';
        }

        if ($note !== '') {
            $bodyHtml .= '<p style="margin:0;padding:14px 16px;border-radius:10px;background:#f8fafc;'
                . 'font-size:13.5px;line-height:1.6;color:' . self::MUTED . ';">' . $e($note) . '</p>';
        }

        $year = date('Y');
        $footerContact = $siteUrl !== ''
            ? '<p style="margin:0 0 6px;font-size:13px;line-height:1.5;color:#9aa0ac;">'
                . '<a href="' . $e($siteUrl) . '" style="color:#9aa0ac;">'
                . '<span style="color:#9aa0ac;">' . $e($siteUrl) . '</span></a></p>'
            : '';
        $footerRecipient = $recipient !== ''
            ? '<p style="margin:0;font-size:12.5px;line-height:1.5;color:#9aa0ac;">Acest email a fost trimis la '
                . $e($recipient) . '.</p>'
            : '';

        $brandEscaped = $e($brand);

        return <<<HTML
<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:28px 12px;">
<tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,.08);">
    <tr>
      <td align="center" style="padding:30px 28px 26px;background:{$gradientEnd};background-image:linear-gradient(135deg,{$gradientStart},{$gradientEnd});">
        {$logoHtml}
        <div style="margin-top:14px;font-size:20px;line-height:1.3;color:#ffffff;font-weight:700;">{$title}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:30px 30px 26px;font-family:Arial,Helvetica,sans-serif;">
        {$bodyHtml}
      </td>
    </tr>
    <tr>
      <td align="center" style="padding:20px 28px 26px;background:#f9fafb;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0 0 6px;font-size:13px;line-height:1.5;color:#7c8390;"><span style="color:#7c8390;">&copy; {$year} {$brandEscaped}</span></p>
        {$footerContact}
        {$footerRecipient}
      </td>
    </tr>
  </table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    /** Clienții de email nu rezolvă căile relative, deci logo-ul are nevoie de URL absolut. */
    private static function absoluteUrl(string $url, string $siteUrl): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if ($siteUrl === '') {
            return $url;
        }

        return $siteUrl . '/' . ltrim($url, '/');
    }
}
