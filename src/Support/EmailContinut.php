<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Conținutul emailurilor trimise (16 nov 2026, cererea proprietarului Biovitality; migrarea nucleu/0067, docs/PLUGINURI.md,
 * „Emailurile trimise și conținutul lor”): Admin → Rapoarte → Emailuri trimise → „Vezi” arată emailul **așa cum a plecat**,
 * nu refăcut din datele de azi (un preț schimbat de atunci nu apare în emailul vechi).
 *
 *  - **Ce se păstrează:** HTML-ul exact dat furnizorului (SMTP, SendGrid, mail()), la fiecare rând nou din
 *    `email_send_history` scris de OrderMailer (comenzi, AWB, link de plată, cont, parolă, contact, cereri de ofertă,
 *    donații, membri, coș abandonat, confirmările newsletterului, testele), și pentru trimiterile eșuate. Comprimat
 *    (gzcompress, nivelul 6: un email de comandă de ~30 KB ajunge la ~5 KB), în `email_send_bodies`. Partea text/plain a
 *    SMTP-ului nu se păstrează separat: e `OrderMailer::htmlInText` din același HTML, deci se arată din el.
 *  - **Ce nu se păstrează:** trimiterile unei campanii de newsletter (`newsletter_campaign`): aceleași pentru mii de abonați;
 *    „Vezi” le arată din campanie (conținutul ei salvat, cu subsolul de dezabonare și linkul lui ca un loc gol, fără pixelul și
 *    linkurile de urmărire ale fiecărui abonat). Peste 4 MB de HTML (imagini lipite în email) nu se păstrează (`prea_mare`).
 *  - **Cât timp:** 90 de zile (ZILE_PASTRARE). Conținutul mai vechi se golește (rândul rămâne, cu `retentie`) la fiecare
 *    email nou și la fiecare deschidere a istoricului, câte cel mult 500 de rânduri o dată.
 *  - **Linkurile secrete:** la resetarea parolei, confirmarea adresei, a abonării și linkul de dezabonare, jetonul din adrese
 *    se înlocuiește înainte de păstrare (faraJetoane): cine vede istoricul nu poate folosi linkul în locul destinatarului.
 *  - **Datele personale:** conținutul se vede doar unde se vede istoricul (aceeași gardă de rol), nu se scrie în niciun
 *    jurnal; ștergerea unui cont (din admin sau de client) și a unui abonat la newsletter golesc conținutul emailurilor
 *    trimise acelei adrese (și, la cont, al emailurilor comenzilor ei, inclusiv copiile adminului).
 */
final class EmailContinut
{
    /** Câte zile se păstrează conținutul. */
    public const ZILE_PASTRARE = 90;

    /** Peste atâția octeți de HTML conținutul nu se păstrează. */
    public const MARIME_MAXIMA = 4 * 1024 * 1024;

    /** Tipurile care nu-și păstrează conținutul: se arată din altă parte (campania). */
    public const TIPURI_FARA_CONTINUT = ['newsletter_campaign'];

    /**
     * Tipurile cu un link secret (un jeton care face ceva în numele destinatarului: resetează parola, confirmă adresa sau
     * abonarea, dezabonează): se păstrează cu jetonul înlocuit (JETON_ASCUNS), ca cine vede istoricul să nu-l poată folosi.
     */
    public const TIPURI_CU_LINK_SECRET = ['password_reset', 'customer_email_confirmation', 'account_verification_confirm',
        'newsletter_confirmare', 'newsletter_link_dezabonare'];

    /** Ce stă în locul jetonului unui link secret. */
    public const JETON_ASCUNS = 'JETON-ASCUNS';

    /** Migrarea care a pornit păstrarea (data ei e „de când”). */
    public const MIGRAREA = 'nucleu/0067_continut_emailuri';

    /** Locul linkului de dezabonare (al fiecărui abonat) în previzualizarea unui newsletter. */
    public const LINK_DEZABONARE = '#link-dezabonare-abonat';

    /** Câte rânduri golește o trecere a păstrării limitate. */
    private const CURATARE_LOT = 500;

    /** Păstrează conținutul unui rând nou din istoric. Nu aruncă: o greșeală aici nu oprește trimiterea. */
    public static function pastreaza(PDO $db, int $historyId, string $emailType, string $html): void
    {
        if ($historyId <= 0 || $html === '' || in_array($emailType, self::TIPURI_FARA_CONTINUT, true)) {
            return;
        }
        if (in_array($emailType, self::TIPURI_CU_LINK_SECRET, true)) {
            $html = self::faraJetoane($html);
        }
        try {
            $marime = strlen($html);
            $comprimat = $marime <= self::MARIME_MAXIMA ? gzcompress($html, 6) : false;
            $stmt = $db->prepare(
                'INSERT INTO email_send_bodies (history_id, html_gz, html_bytes, cleared_reason, cleared_at)
                 VALUES (:id, :html, :marime, :motiv, :sters_la)'
            );
            $stmt->bindValue('id', $historyId, PDO::PARAM_INT);
            $stmt->bindValue('html', is_string($comprimat) ? $comprimat : null, is_string($comprimat) ? PDO::PARAM_LOB : PDO::PARAM_NULL);
            $stmt->bindValue('marime', $marime, PDO::PARAM_INT);
            $stmt->bindValue('motiv', is_string($comprimat) ? null : 'prea_mare');
            $stmt->bindValue('sters_la', is_string($comprimat) ? null : (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
            $stmt->execute();
        } catch (Throwable) {
            // Baza fără migrarea nucleu/0067 (un site încă neadoptat) sau o eroare de scriere: emailul a plecat oricum.
            return;
        }
        self::curataVechi($db);
    }

    /**
     * Păstrarea limitată: golește conținutul mai vechi de ZILE_PASTRARE zile (cel mult $lot rânduri). Întoarce câte a golit.
     */
    public static function curataVechi(PDO $db, ?int $zile = null, int $lot = self::CURATARE_LOT): int
    {
        $zile = max(1, $zile ?? self::ZILE_PASTRARE);
        try {
            $stmt = $db->prepare(
                'UPDATE email_send_bodies
                 SET html_gz = NULL, cleared_reason = "retentie", cleared_at = NOW()
                 WHERE cleared_at IS NULL AND created_at < NOW() - INTERVAL ' . $zile . ' DAY
                 LIMIT ' . max(1, $lot)
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Golește conținutul emailurilor trimise acestor adrese (ștergerea unui cont, a unui abonat). Cu $siComenzile, și al
     * emailurilor comenzilor plasate cu aceste adrese (copiile adminului, alți destinatari). Întoarce câte a golit.
     *
     * @param list<string> $adrese
     */
    public static function stergePentruAdrese(PDO $db, array $adrese, bool $siComenzile = false, string $motiv = 'gdpr'): int
    {
        $adrese = array_values(array_unique(array_filter(array_map(
            static fn ($a): string => strtolower(trim((string) $a)),
            $adrese
        ), static fn (string $a): bool => $a !== '')));
        if ($adrese === []) {
            return 0;
        }
        $semne = implode(',', array_fill(0, count($adrese), '?'));
        $golite = 0;
        $conditii = ['LOWER(h.recipient) IN (' . $semne . ')'];
        if ($siComenzile) {
            $conditii[] = 'h.order_id IN (SELECT o.id FROM orders o WHERE LOWER(o.billing_email) IN (' . $semne . '))';
        }
        foreach ($conditii as $conditie) {
            try {
                $stmt = $db->prepare(
                    'UPDATE email_send_bodies b
                     JOIN email_send_history h ON h.id = b.history_id
                     SET b.html_gz = NULL, b.cleared_reason = ?, b.cleared_at = NOW()
                     WHERE b.html_gz IS NOT NULL AND ' . $conditie
                );
                $stmt->execute(array_merge([substr($motiv, 0, 20)], $adrese));
                $golite += $stmt->rowCount();
            } catch (Throwable) {
                // Fără tabelul conținutului (bază neadoptată) sau fără comenzi (site fără magazin): nimic de golit acolo.
            }
        }
        return $golite;
    }

    /**
     * Ce arată „Vezi” pentru un rând din istoric.
     *
     * @return array{rand: ?array<string, mixed>, stare: string, html: ?string, mesaj: string, marime: int, campanie: ?string}
     *   stare: `pastrat` (html = exact ce a plecat), `campanie` (html = din campanie), `inainte`, `sters`, `prea_mare`,
     *   `campanie_lipsa`, `lipsa` (rândul nu există)
     */
    public static function previzualizare(PDO $db, int $historyId): array
    {
        $gol = ['rand' => null, 'stare' => 'lipsa', 'html' => null, 'mesaj' => 'Emailul nu există în istoric.', 'marime' => 0,
            'campanie' => null];
        if ($historyId <= 0) {
            return $gol;
        }
        try {
            $stmt = $db->prepare(
                'SELECT id, order_id, email_type, source, recipient, subject, status, error_message, provider, meta_json, sent_at, created_at
                 FROM email_send_history WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $historyId]);
            $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $rand = false;
        }
        if (!is_array($rand)) {
            return $gol;
        }
        $rezultat = ['rand' => $rand] + $gol;

        $tip = (string) ($rand['email_type'] ?? '');
        if (in_array($tip, self::TIPURI_FARA_CONTINUT, true)) {
            return self::dinCampanie($db, $rand, $rezultat);
        }

        $corp = null;
        try {
            $stmt = $db->prepare('SELECT html_gz, html_bytes, cleared_reason, cleared_at FROM email_send_bodies WHERE history_id = :id LIMIT 1');
            $stmt->execute(['id' => $historyId]);
            $corp = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            $corp = null;
        }
        if (is_array($corp)) {
            $rezultat['marime'] = (int) ($corp['html_bytes'] ?? 0);
            $comprimat = $corp['html_gz'] ?? null;
            if (is_resource($comprimat)) {
                $comprimat = stream_get_contents($comprimat);
            }
            if (is_string($comprimat) && $comprimat !== '') {
                $html = @gzuncompress($comprimat, self::MARIME_MAXIMA + 1);
                if (is_string($html)) {
                    $rezultat['stare'] = 'pastrat';
                    $rezultat['html'] = $html;
                    $rezultat['mesaj'] = in_array($tip, self::TIPURI_CU_LINK_SECRET, true)
                        ? 'Emailul cum a plecat, păstrat la trimitere, cu jetonul linkului secret înlocuit cu „' . self::JETON_ASCUNS
                            . '” (un link de resetare a parolei sau de confirmare nu se poate folosi din istoric).'
                        : 'Emailul exact cum a plecat, păstrat la trimitere.';
                    return $rezultat;
                }
            }
            $motiv = (string) ($corp['cleared_reason'] ?? '');
            $cand = self::dataRo((string) ($corp['cleared_at'] ?? ''));
            if ($motiv === 'prea_mare') {
                $rezultat['stare'] = 'prea_mare';
                $rezultat['mesaj'] = 'Conținutul nu a fost păstrat: emailul avea peste ' . (int) (self::MARIME_MAXIMA / 1048576) . ' MB.';
            } elseif ($motiv === 'gdpr') {
                $rezultat['stare'] = 'sters';
                $rezultat['mesaj'] = 'Conținutul a fost șters' . ($cand !== '' ? ' pe ' . $cand : '')
                    . ', odată cu datele destinatarului (contul sau abonarea lui au fost șterse).';
            } else {
                $rezultat['stare'] = 'sters';
                $rezultat['mesaj'] = 'Conținutul a fost șters automat' . ($cand !== '' ? ' pe ' . $cand : '')
                    . ': se păstrează ' . self::ZILE_PASTRARE . ' de zile de la trimitere.';
            }
            return $rezultat;
        }

        $din = self::pastratDin($db);
        $rezultat['stare'] = 'inainte';
        $rezultat['mesaj'] = 'Conținutul nu a fost păstrat (email trimis înainte de ' . ($din !== '' ? $din : 'păstrarea conținutului') . ').';
        return $rezultat;
    }

    /** Data (zz.ll.aaaa) de la care se păstrează conținutul: migrarea nucleu/0067, altfel primul conținut păstrat. */
    public static function pastratDin(PDO $db): string
    {
        foreach ([
            ['SELECT terminata_la FROM _platforma_migrari WHERE id = :id LIMIT 1', ['id' => self::MIGRAREA]],
            ['SELECT MIN(created_at) FROM email_send_bodies', []],
        ] as [$sql, $parametri]) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($parametri);
                $data = self::dataRo((string) ($stmt->fetchColumn() ?: ''));
                if ($data !== '') {
                    return $data;
                }
            } catch (Throwable) {
                continue;
            }
        }
        return '';
    }

    /** Un newsletter trimis: conținutul campaniei lui, ca la trimitere, dar fără ce e al fiecărui abonat. */
    private static function dinCampanie(PDO $db, array $rand, array $rezultat): array
    {
        $meta = json_decode((string) ($rand['meta_json'] ?? ''), true);
        $idCampanie = is_array($meta) ? (int) ($meta['campaign_id'] ?? 0) : 0;
        $campanie = null;
        if ($idCampanie > 0) {
            try {
                $stmt = $db->prepare('SELECT id, name, subject, html_content, blocks_json FROM newsletter_campaigns WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $idCampanie]);
                $campanie = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable) {
                $campanie = null;
            }
        }
        if (!is_array($campanie)) {
            $rezultat['stare'] = 'campanie_lipsa';
            $rezultat['mesaj'] = 'Newsletterul a plecat dintr-o campanie care nu mai există, deci conținutul lui nu se mai poate arăta.';
            return $rezultat;
        }

        // La fel ca NewsletterService::sendCampaignNow: blocurile constructorului, dacă există, altfel HTML-ul salvat.
        $html = (string) ($campanie['html_content'] ?? '');
        $blocuri = trim((string) ($campanie['blocks_json'] ?? ''));
        if ($blocuri !== '' && $blocuri !== '[]') {
            $parsate = json_decode($blocuri, true);
            if (is_array($parsate) && $parsate !== []) {
                $html = NewsletterService::renderHtmlFromBlocks($parsate, (string) ($campanie['subject'] ?? ''));
            }
        }
        // Linkul de dezabonare e al fiecărui abonat: aici un loc gol (LINK_DEZABONARE), iar pixelul și linkurile de urmărire nu se
        // pun. Subsolul e cel al trimiterii reale (butonul „Dezabonează-mă”), nu cel al unui email de test.
        $html = NewsletterService::withNewsletterFooter($html !== '' ? $html : '<p>Newsletter</p>', self::LINK_DEZABONARE);

        $rezultat['stare'] = 'campanie';
        $rezultat['html'] = $html;
        $rezultat['marime'] = strlen($html);
        $rezultat['campanie'] = (string) ($campanie['name'] ?? '');
        $rezultat['mesaj'] = 'Newsletter: conținutul campaniei „' . (string) ($campanie['name'] ?? '') . '”, același pentru toți abonații '
            . '(nu se păstrează câte o copie pentru fiecare). Linkul de dezabonare al abonatului apare ca „' . self::LINK_DEZABONARE . '”, fără urmărirea '
            . 'deschiderilor și a clicurilor; dacă campania a fost schimbată după trimitere, se vede forma de acum.';
        return $rezultat;
    }

    /**
     * Jetoanele din adresele unui email cu link secret: în fiecare adresă http(s) (în href și în text) și în fiecare href (și
     * unul relativ), orice șir de cel puțin 20 de litere, cifre, „-” și „_” (cu bucățile lui legate prin „.”, ca jetonul
     * semnat al newsletterului) devine JETON_ASCUNS.
     */
    public static function faraJetoane(string $html): string
    {
        return (string) preg_replace_callback(
            '#https?://[^\s"\'<>]+|(?<=href=")[^"]+|(?<=href=\')[^\']+#i',
            static fn (array $m): string => (string) preg_replace('/[A-Za-z0-9_-]{20,}(?:\.[A-Za-z0-9_-]{8,})*/', self::JETON_ASCUNS, $m[0]),
            $html
        );
    }

    private static function dataRo(string $data): string
    {
        $data = trim($data);
        if ($data === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $data, $m)) {
            return '';
        }
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
}
