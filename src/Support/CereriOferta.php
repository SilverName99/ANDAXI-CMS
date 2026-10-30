<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Cererile de ofertă venite din sertarul de pe pagina de produs (pluginul `cereri_oferta`; WP-26: GR-15, din
 * grafoanaytis `src/Support/CereriOferta.php`).
 *
 * Un site care nu vinde primește cereri: în loc de „Adaugă în coș”, produsul are butonul `{{product_quote_button}}`, care
 * deschide un sertar cu formularul. Cererea e un client potențial, nu un mesaj: se păstrează în bază, cu stare (`noua`,
 * `in_lucru`, `trimisa`, `inchisa`), și se vede în admin la Cereri de ofertă. Numele produsului se păstrează pe cerere
 * (un produs șters sau redenumit nu lasă cererea fără obiect); adresa IP nu se păstrează pe cerere.
 *
 * Față de grafoanaytis: tabelele vin din migrarea `cereri_oferta/0037` (nu se mai fac din cerere: fără DDL pe cerere);
 * textele sertarului sunt setări (`cereri_oferta_*`, implicit neutre; OD-26); trimiterile au o limită, 5 la 10 minute de la
 * același vizitator, numărate în `quote_submit_logs` doar cu un hash al adresei (HMAC, nu adresa).
 *
 * Stările pluginului: pornit = totul; se oprește = butonul nu se mai arată, o cerere nouă e refuzată (403), lista din admin
 * rămâne; oprit = garda pluginurilor închide adresele (404 pe API, 403 în admin), rândurile rămân în bază.
 */
final class CereriOferta
{
    public const STARI = ['noua', 'in_lucru', 'trimisa', 'inchisa'];

    public const ETICHETE_STARI = [
        'noua' => 'Nouă',
        'in_lucru' => 'În lucru',
        'trimisa' => 'Ofertă trimisă',
        'inchisa' => 'Închisă',
    ];

    /** Câte cereri primește site-ul de la același vizitator într-un interval de LIMITA_MINUTE minute. */
    public const LIMITA_CERERI = 5;
    public const LIMITA_MINUTE = 10;

    /** Textele implicite ale sertarului (aceleași ca în Settings; o setare goală le folosește tot pe ele). */
    public const TEXTE_IMPLICITE = [
        'cereri_oferta_buton' => 'Cere ofertă',
        'cereri_oferta_titlu' => 'Cerere de ofertă pentru:',
        'cereri_oferta_intro' => 'Completați formularul și vă răspundem cu o ofertă.',
        'cereri_oferta_eticheta_mesaj' => 'Mesaj',
        'cereri_oferta_multumire' => 'Am primit cererea. Vă răspundem cât mai curând.',
    ];

    private static ?bool $areSchema = null;

    /** Baza are tabelele pluginului (migrarea cereri_oferta/0037)? O dată pe cerere. */
    public static function areSchema(PDO $db): bool
    {
        if (self::$areSchema === null) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('quote_requests', 'quote_submit_logs')");
                self::$areSchema = $stmt !== false && (int) $stmt->fetchColumn() === 2;
            } catch (Throwable) {
                self::$areSchema = false;
            }
        }
        return self::$areSchema;
    }

    /** @param array<string, mixed> $date */
    public static function adauga(PDO $db, array $date): ?int
    {
        if (!self::areSchema($db)) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO quote_requests
                    (product_id, product_name, product_slug, name, company, email, phone,
                     message, consent_at, source_url)
                 VALUES
                    (:product_id, :product_name, :product_slug, :name, :company, :email, :phone,
                     :message, :consent_at, :source_url)'
            );
            $stmt->execute([
                'product_id' => $date['product_id'] ?? null,
                'product_name' => $date['product_name'] ?? null,
                'product_slug' => $date['product_slug'] ?? null,
                'name' => $date['name'],
                'company' => $date['company'] ?? null,
                'email' => $date['email'],
                'phone' => $date['phone'] ?? null,
                'message' => $date['message'] ?? null,
                'consent_at' => $date['consent_at'] ?? null,
                'source_url' => $date['source_url'] ?? null,
            ]);
            return (int) $db->lastInsertId();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function lista(PDO $db, string $stare = '', int $limita = 100): array
    {
        if (!self::areSchema($db)) {
            return [];
        }
        $conditii = ['deleted_at IS NULL'];
        $parametri = [];
        if ($stare !== '' && in_array($stare, self::STARI, true)) {
            $conditii[] = 'status = :status';
            $parametri['status'] = $stare;
        }
        try {
            $stmt = $db->prepare(
                'SELECT * FROM quote_requests
                 WHERE ' . implode(' AND ', $conditii) . '
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . max(1, min(500, $limita))
            );
            $stmt->execute($parametri);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, int> */
    public static function numarPeStari(PDO $db): array
    {
        $rezultat = array_fill_keys(self::STARI, 0);
        if (!self::areSchema($db)) {
            return $rezultat;
        }
        try {
            $stmt = $db->query('SELECT status, COUNT(*) AS n FROM quote_requests WHERE deleted_at IS NULL GROUP BY status');
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $rand) {
                $stare = (string) ($rand['status'] ?? '');
                if (isset($rezultat[$stare])) {
                    $rezultat[$stare] = (int) $rand['n'];
                }
            }
        } catch (Throwable) {
            // Zerourile de mai sus sunt un răspuns acceptabil.
        }
        return $rezultat;
    }

    public static function schimbaStarea(PDO $db, int $id, string $stare, string $nota = ''): bool
    {
        if ($id <= 0 || !in_array($stare, self::STARI, true) || !self::areSchema($db)) {
            return false;
        }
        try {
            $stmt = $db->prepare('UPDATE quote_requests SET status = :status, admin_note = :nota WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['status' => $stare, 'nota' => $nota !== '' ? mb_substr($nota, 0, 2000) : null, 'id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Ștergere „moale” (deleted_at): rândul rămâne în bază, nu se mai vede în listă. */
    public static function sterge(PDO $db, int $id): bool
    {
        if ($id <= 0 || !self::areSchema($db)) {
            return false;
        }
        try {
            $stmt = $db->prepare('UPDATE quote_requests SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Secretul aleator al site-ului pentru hash-ul adreselor (ascuns din Settings::all; revizuirea WP-26). */
    public const CHEIE_SECRET_IP = 'cereri_oferta_secret_ip';

    /**
     * Hash-ul cu care se numără trimiterile unui vizitator: HMAC-SHA256 al cheii de limită a adresei (IPv6 pe rețeaua /64,
     * `IpClient::cheieLimita`), cu secretul aleator al site-ului (`Settings::secretGenerat`, făcut la prima folosire),
     * ca din hash să nu se poată afla adresa încercând toate adresele IPv4.
     */
    public static function hashIp(PDO $db, string $ip): string
    {
        return hash_hmac('sha256', IpClient::cheieLimita($ip), Settings::secretGenerat($db, self::CHEIE_SECRET_IP));
    }

    /** A trimis vizitatorul deja LIMITA_CERERI cereri în ultimele LIMITA_MINUTE minute? */
    public static function limitat(PDO $db, string $ip): bool
    {
        if (!self::areSchema($db)) {
            return false;
        }
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM quote_submit_logs WHERE ip_hash = :h
                AND created_at >= NOW() - INTERVAL ' . self::LIMITA_MINUTE . ' MINUTE');
            $stmt->execute(['h' => self::hashIp($db, $ip)]);
            return (int) $stmt->fetchColumn() >= self::LIMITA_CERERI;
        } catch (Throwable) {
            return false;
        }
    }

    /** Notează o trimitere (pentru limită); rândurile mai vechi de o zi se șterg din mers. */
    public static function noteazaTrimiterea(PDO $db, string $ip): void
    {
        if (!self::areSchema($db)) {
            return;
        }
        try {
            $db->prepare('INSERT INTO quote_submit_logs (ip_hash, created_at) VALUES (:h, NOW())')->execute(['h' => self::hashIp($db, $ip)]);
            if (random_int(1, 20) === 1) {
                $db->exec('DELETE FROM quote_submit_logs WHERE created_at < NOW() - INTERVAL 1 DAY');
            }
        } catch (Throwable) {
            // Fără notare, limita doar nu numără această trimitere.
        }
    }

    /**
     * Textele sertarului, din setări (implicit neutre). `confidentialitate` = adresa paginii de confidențialitate
     * (`cereri_oferta_confidentialitate_url`, altfel cea a bannerului de cookie), goală = fără link.
     *
     * @param array<string, mixed> $setari
     * @return array{buton: string, titlu: string, intro: string, eticheta_mesaj: string, multumire: string, confidentialitate: string}
     */
    public static function texte(array $setari): array
    {
        $text = static function (string $cheie) use ($setari): string {
            $valoare = trim((string) ($setari[$cheie] ?? ''));
            return $valoare !== '' ? $valoare : self::TEXTE_IMPLICITE[$cheie];
        };
        $confidentialitate = trim((string) ($setari['cereri_oferta_confidentialitate_url'] ?? ''));
        if ($confidentialitate === '') {
            $confidentialitate = trim((string) ($setari['cookie_banner_policy_url'] ?? ''));
        }
        if ($confidentialitate !== '' && preg_match('#^(/(?!/)|https?://)#i', $confidentialitate) !== 1) {
            $confidentialitate = '';
        }
        return [
            'buton' => $text('cereri_oferta_buton'),
            'titlu' => $text('cereri_oferta_titlu'),
            'intro' => $text('cereri_oferta_intro'),
            'eticheta_mesaj' => $text('cereri_oferta_eticheta_mesaj'),
            'multumire' => $text('cereri_oferta_multumire'),
            'confidentialitate' => $confidentialitate,
        ];
    }

    /**
     * `{{product_quote_button}}`: butonul și sertarul lui (un offcanvas Bootstrap 5, cu marcajul și clasele din grafoanaytis,
     * de care depind tema și paginile lui), plus scriptul sertarului. Doar cu pluginul pornit (se oprește = nimic). Cu o
     * temă fără Bootstrap (`tema.json` fără `"bootstrap": true`) vin și JavaScript-ul Bootstrap din nucleu și câteva reguli
     * CSS ale sertarului (altfel panoul n-ar fi ascuns și n-ar aluneca).
     *
     * @param array<string, mixed> $setari
     */
    public static function butonHtml(array $setari, string $slug, string $nume, string $imagine): string
    {
        if (!Plugins::permiteInceput('cereri_oferta')) {
            return '';
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $texte = self::texte($setari);
        $id = 'sertar-oferta';
        $miniatura = $imagine !== ''
            ? '<img src="' . $e($imagine) . '" alt="" width="56" height="56" class="sertar-oferta__poza">'
            : '';
        $link = $texte['confidentialitate'] !== ''
            ? "\n" . '          <a href="' . $e($texte['confidentialitate']) . '" target="_blank" rel="noopener">Politica de confidențialitate</a>'
            : '';
        $sageata = '<svg class="sageata" width="18" height="14" viewBox="0 0 18 14" aria-hidden="true" focusable="false">'
            . '<path d="M10.5 1 16.5 7l-6 6M0 7h16" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
        $buton = $e($texte['buton']);
        $numeSigur = $e($nume);
        $slugSigur = $e($slug);
        $intro = $e($texte['intro']);
        $titlu = $e($texte['titlu']);
        $etichetaMesaj = $e($texte['eticheta_mesaj']);

        $html = <<<HTML
<button class="btn btn-primary fw-semibold px-4 sertar-oferta__declansator" type="button"
        data-bs-toggle="offcanvas" data-bs-target="#{$id}" aria-controls="{$id}">
  {$buton}
  {$sageata}
</button>

<div class="offcanvas offcanvas-end sertar-oferta" tabindex="-1" id="{$id}" aria-labelledby="{$id}-titlu">
  <div class="offcanvas-header align-items-start">
    <div>
      <p class="text-secondary small mb-1">{$titlu}</p>
      <h2 class="h5 mb-0" id="{$id}-titlu">{$miniatura}<span>{$numeSigur}</span></h2>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
  </div>
  <div class="offcanvas-body">
    <p>{$intro}</p>

    <form class="sertar-oferta__formular" novalidate data-produs="{$slugSigur}">
      <div class="mb-3">
        <label class="form-label" for="oferta-nume">Nume și prenume <span aria-hidden="true">*</span></label>
        <input class="form-control" id="oferta-nume" name="name" type="text" autocomplete="name" maxlength="160" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="oferta-firma">Companie</label>
        <input class="form-control" id="oferta-firma" name="company" type="text" autocomplete="organization" maxlength="160">
      </div>
      <div class="mb-3">
        <label class="form-label" for="oferta-email">E-mail <span aria-hidden="true">*</span></label>
        <input class="form-control" id="oferta-email" name="email" type="email" autocomplete="email" maxlength="190" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="oferta-telefon">Telefon</label>
        <input class="form-control" id="oferta-telefon" name="phone" type="tel" autocomplete="tel" maxlength="60">
      </div>
      <div class="mb-3">
        <label class="form-label" for="oferta-mesaj">{$etichetaMesaj}</label>
        <textarea class="form-control" id="oferta-mesaj" name="message" rows="4" maxlength="4000"></textarea>
      </div>

      <!-- Capcană pentru roboți: ascunsă de ochi, nu și de completarea automată. -->
      <div class="sertar-oferta__capcana" aria-hidden="true">
        <label for="oferta-website">Nu completați acest câmp</label>
        <input id="oferta-website" name="website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <div class="form-check mb-3">
        <input class="form-check-input" id="oferta-acord" name="consent" type="checkbox" required>
        <label class="form-check-label small" for="oferta-acord">
          Sunt de acord ca datele de mai sus să fie folosite pentru a-mi răspunde la această cerere.{$link}
        </label>
      </div>

      <p class="sertar-oferta__raspuns" role="status" aria-live="polite"></p>

      <button class="btn btn-primary fw-semibold w-100" type="submit">
        Trimite solicitarea
        {$sageata}
      </button>
    </form>
  </div>
</div>
HTML;

        // Capcana pentru roboți și locul mesajului: ascunse chiar dacă tema nu le are în CSS.
        $html .= "\n<style>.sertar-oferta__capcana{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}"
            . '.sertar-oferta__raspuns:empty{display:none}</style>';
        if (!Tema::bootstrap()) {
            // Fără CSS-ul Bootstrap al temei: doar ce face din panou un sertar (ascuns, alunecă din dreapta, fundalul).
            $html .= "\n<style>.sertar-oferta.offcanvas{position:fixed;top:0;right:0;bottom:0;z-index:1045;display:flex;flex-direction:column;"
                . 'width:400px;max-width:100%;background:#fff;color:#111;visibility:hidden;transform:translateX(100%);'
                . 'transition:transform .3s ease-in-out;outline:0;box-shadow:-8px 0 24px rgba(0,0,0,.15)}'
                . '.sertar-oferta.offcanvas.showing,.sertar-oferta.offcanvas.show:not(.hiding){transform:none}'
                . '.sertar-oferta.offcanvas.showing,.sertar-oferta.offcanvas.hiding,.sertar-oferta.offcanvas.show{visibility:visible}'
                . '.sertar-oferta .offcanvas-header{display:flex;justify-content:space-between;gap:12px;padding:16px}'
                . '.sertar-oferta .offcanvas-body{flex-grow:1;padding:16px;overflow-y:auto}'
                . '.sertar-oferta .btn-close{border:0;background:transparent;font-size:24px;line-height:1;cursor:pointer}'
                . '.sertar-oferta .btn-close::before{content:"\00d7"}'
                . '.sertar-oferta .mb-3{margin-bottom:12px}.sertar-oferta .form-control{display:block;width:100%;box-sizing:border-box}'
                . '.offcanvas-backdrop{position:fixed;top:0;left:0;z-index:1040;width:100vw;height:100vh;background:#000}'
                . '.offcanvas-backdrop.fade{opacity:0;transition:opacity .15s linear}.offcanvas-backdrop.show{opacity:.5}</style>';
            $html .= "\n" . '<script src="' . $e(Tema::url(Tema::BOOTSTRAP_JS)) . '" defer></script>';
        }
        $fisierJs = Paths::public('assets/js/sertar-oferta.js');
        $versiune = is_file($fisierJs) ? (string) filemtime($fisierJs) : '1';
        $html .= "\n" . '<script src="/assets/js/sertar-oferta.js?v=' . $e($versiune) . '" defer></script>';
        return $html;
    }

    /** Doar pentru teste: uită ce s-a citit. */
    public static function reseteaza(): void
    {
        self::$areSchema = null;
    }
}
