<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AdminActivityLog;
use App\Support\AdminCsrf;
use App\Support\Auth;
use App\Support\BrandedEmail;
use App\Support\ConfirmareEmail;
use App\Support\CustomerAuth;
use App\Support\Flash;
use App\Support\IpClient;
use App\Support\OrderMailer;
use App\Support\Paths;
use App\Support\ProfessionAccounts;
use App\Support\Settings;
use App\Support\StripeEvenimente;
use App\Support\StripeGateway;
use App\Support\View;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Pluginul `membri` (din vertij d3439a5 … ffa3d9c „conturi profesionale, categorii de membri, cereri noi”, 50ef812
 * „confirmarea emailului, emailuri de cont cu brand”, af8ab87 „poza de profil”, 7d7d8cc + b0a81e1 „plata activării în
 * listele adminului”, la 9ab7999; docs/PLUGINURI.md, „Membri”):
 *
 *  - înscrierea cu tipul de cont (categoria de membru): câmpurile categoriei, documentele (în `storage/account-documents`,
 *    servite doar adminului), acordul GDPR, codul de membru, emailul de confirmare a adresei (linkul
 *    `/cont/confirmare/{token}`), pagina „Verifică-ți adresa de email” și retrimiterea, reluarea unei înscrieri neconfirmate;
 *  - cererea nouă din cont (după o respingere), aprobarea sau respingerea din Admin → Cereri conturi, cu emailul deciziei;
 *  - cotizația pe 1–12 luni prin Stripe Checkout (`/contul-meu/activare`, întoarcerea, evenimentele `checkout.session.*`),
 *    perioada prelungită de la data expirării, chitanța, emailul „Contul tău este activ”, tab-ul „Cotizația mea” din cont;
 *  - conținutul doar pentru membri (agățările nucleului `continut.membru_activ`, `continut.nota`; bifa „Doar cont activ” din
 *    editoare), poza de profil (în Galerie, folderul „Poze de profil”), ultima plată a cotizației în listele adminului.
 *
 * Copiat cum e (aceleași texte, reguli, adrese, câmpuri și cereri spre Stripe), cu schimbările codului comun:
 *  - schema o face migrarea `membri/0063`; documentele și jurnalul prin `Paths`;
 *  - evenimentele Stripe prin dispecerul nucleului (`StripeEvenimente`), emailurile doar cu pluginul vizibil;
 *  - jetonul formularului (AdminCsrf) pe fiecare POST din admin și pe formularele contului (cotizația, poza, cererea nouă);
 *  - linkul de confirmare se deschide (GET) într-o pagină cu un buton și cere parola contului (POST), ca linkul de confirmare
 *    al nucleului (revizuirea WP-25: un program care deschide linkurile din emailuri nu mai poate confirma și loga pe nimeni;
 *    cine își face cont cu adresa altuia nu poate confirma fără proprietarul adresei, iar acesta nu poate confirma fără
 *    parola contului); confirmarea dovedește și adresa pentru nucleu (`ConfirmareEmail::marcheazaConfirmat`: comenzile și
 *    punctele fără cont cu adresa lui, donațiile);
 *  - cu confirmarea cerută la înregistrare (`customer_email_confirmation_required`), un cont cu cererea „așteaptă
 *    confirmarea pe email” nu intră până nu confirmă (cum spune vertij: „contul se deschide abia după confirmare”).
 */
final class MembriController
{
    use Comun;

    public const SESIUNE_EMAIL = 'pending_verification_email';

    /** Întoarcerile de la Stripe Checkout ale aceluiași vizitator în 15 minute (LimitaCereri). */
    public const INTOARCERI_LA_15_MINUTE = 20;

    /** Câte zile e valabil linkul de confirmare al unei cereri de membru (revizuirea de securitate, 9 nov 2026). */
    public const ZILE_LINK_CONFIRMARE = 7;

    // ================================================================== înscrierea (agățările nucleului)

    /**
     * Meseriile active + câmpurile lor, plus configurația de afișare a formularului.
     *
     * @return array{professions: array<int, array<string, mixed>>, config: array<string, mixed>}
     */
    private static function professionRegistrationData(?PDO $db, array $settings = []): array
    {
        $empty = ['professions' => [], 'config' => []];
        if (!$db instanceof PDO) {
            return $empty;
        }

        $professions = ProfessionAccounts::professions($db, true);
        if ($professions === []) {
            return $empty;
        }

        if ($settings === []) {
            $settings = Settings::all($db);
        }

        foreach ($professions as $index => $profession) {
            $professions[$index]['fields'] = ProfessionAccounts::professionFields(
                $db,
                (int) ($profession['id'] ?? 0),
                true
            );
        }

        return [
            'professions' => $professions,
            'config' => [
                'intro' => (string) ($settings['account_registration_intro'] ?? ''),
                'gdpr_label' => (string) ($settings['account_gdpr_label'] ?? ''),
                'gdpr_url' => (string) ($settings['account_gdpr_url'] ?? ''),
                'max_upload_mb' => (float) ($settings['account_upload_max_mb'] ?? 8),
                'allowed_extensions' => ProfessionAccounts::allowedExtensions($settings),
                'selected_profession_id' => (int) ($_GET['profession_id'] ?? 0),
            ],
        ];
    }

    /** Agățarea `inregistrare.inlocuieste_campurile`: cu tipuri de cont, formularul îl randează componenta (ca în vertij). */
    public static function inlocuiesteCampurile(): bool
    {
        $db = self::conexiune();
        return $db instanceof PDO && ProfessionAccounts::professions($db, true) !== [];
    }

    /** Agățarea `inregistrare.formular`: selectorul tipului de cont, datele contului și câmpurile categoriei. */
    public static function formular(): string
    {
        $db = self::conexiune();
        if (!$db instanceof PDO) {
            return '';
        }
        $data = self::professionRegistrationData($db);
        if ($data['professions'] === []) {
            return '';
        }
        return View::fragment('site/membri/profession-fields', [
            'professions' => $data['professions'],
            'professionConfig' => $data['config'],
            'cuStil' => true,
        ]);
    }

    /**
     * Agățarea `inregistrare.campuri`: în modul profesional se cer prenumele, numele, emailul, telefonul și parola, fără data
     * nașterii și gen (ca în vertij: „restul datelor sunt câmpurile definite pe tipul de cont”).
     *
     * @return array<string, bool>|null
     */
    public static function campuri(PDO $db): ?array
    {
        if (ProfessionAccounts::professions($db, true) === []) {
            return null;
        }
        return [
            'first_name' => true,
            'last_name' => true,
            'phone' => true,
            'birth_date' => false,
            'gender' => false,
            'email' => true,
            'password' => true,
            'password_confirm' => true,
        ];
    }

    /**
     * Agățarea `inregistrare.valideaza`: cererea profesională (tipul de cont, acordul, câmpurile, documentele) și telefonul,
     * înainte de crearea contului, ca să nu rămână conturi create pe jumătate.
     *
     * @return array{error?: string, data?: array<string, mixed>}
     */
    public static function valideaza(PDO $db, array $settings): array
    {
        $submission = self::collectProfessionSubmission($db, $settings);
        if (is_string($submission['error'] ?? null)) {
            return ['error' => (string) $submission['error']];
        }
        if (!empty($submission['required']) && trim((string) ($_POST['phone'] ?? '')) === '') {
            return ['error' => 'Numărul de telefon este obligatoriu.'];
        }
        return ['data' => ['membri' => $submission]];
    }

    /**
     * Agățarea `inregistrare.cont_existent`: adresa are deja cont. Revizuirea de securitate (9 nov 2026): codul vertij relua
     * înscrierea peste cont (parola, numele, telefonul, cererea și documentele noi) cât adresa nu era confirmată prin linkul
     * cererii; un cont confirmat altfel (resetarea parolei, logarea cu Google) putea fi astfel preluat. Acum contul nu se
     * schimbă niciodată din formularul de înscriere:
     *  - adresa nedovedită, ultima cerere „așteaptă confirmarea pe email”, contul nefolosit (fără comenzi, plăți, donații,
     *    puncte, Google): linkul cererii existente se trimite din nou (token nou, bugetul retrimiterilor),
     *    celelalte sesiuni ale contului se încheie (doar pentru o cerere de după adoptare: un cont vechi intră ca pe vertij), iar pagina „Verifică-ți adresa” spune să confirme cu parola de la prima
     *    înscriere (sau să o reseteze); datele noi din formular nu se salvează;
     *  - altfel: „Există deja un cont … Intră în cont sau resetează parola”.
     */
    public static function contExistent(PDO $db, string $email, array $date): ?string
    {
        $submission = is_array($date['membri'] ?? null) ? $date['membri'] : [];
        if (empty($submission['required'])) {
            return null;
        }
        $unconfirmed = self::findUnconfirmedAccount($db, $email);
        if ($unconfirmed === null || !$unconfirmed['nefolosit']) {
            Flash::set('error', 'Există deja un cont cu această adresă de email. Intră în cont sau, dacă nu mai știi parola, '
                . 'folosește „Am uitat parola”.');
            return '/login';
        }
        // Celelalte sesiuni se încheie doar pentru o înscriere de după adoptare (contul ei n-a putut intra niciodată); un cont vechi
        // cu cererea neconfirmată intra pe codul vertij și intră și acum (logareBlocata), deci sesiunile lui rămân.
        $adoptare = self::momentulAdoptarii($db);
        if ($adoptare === '' || $unconfirmed['creata'] >= $adoptare) {
            CustomerAuth::incheieAlteSesiuni($db, (int) $unconfirmed['user_id']);
        }
        $ip = mb_substr(IpClient::adresa(), 0, 64);
        if (!ConfirmareEmail::limitat($db, $ip, 'resend')) {
            ConfirmareEmail::noteazaRetrimitere($db, $ip);
            self::resendVerificationEmail($db, $email, Settings::all($db));
        }
        $_SESSION[self::SESIUNE_EMAIL] = $email;
        Flash::set('success', 'Ai deja o înscriere care așteaptă confirmarea adresei. Ți-am trimis din nou linkul: confirmă cu parola '
            . 'aleasă la prima înscriere (dacă n-o mai știi, folosește „Am uitat parola”). Datele noi din formular nu au fost salvate.');
        return '/cont/verifica-email';
    }

    /**
     * Agățarea `inregistrare.dupa_creare`: cererea de membru (cu documentele) și emailul de confirmare; contul se deschide
     * abia după confirmarea adresei, deci nu autentificăm pe nimeni aici.
     */
    public static function dupaCreare(PDO $db, int $userId, array $date): ?string
    {
        $submission = is_array($date['membri'] ?? null) ? $date['membri'] : [];
        if (empty($submission['required']) || !is_array($submission['profession'] ?? null)) {
            return null;
        }
        $settings = Settings::all($db);
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        ProfessionAccounts::assignMemberCode($db, $userId, $settings);
        self::storeProfessionSubmission(
            $db,
            $userId,
            $submission,
            $settings,
            trim(trim((string) ($_POST['first_name'] ?? '')) . ' ' . trim((string) ($_POST['last_name'] ?? ''))),
            $email
        );
        // Adresa rămâne în sesiune, nu în URL: e dată cu caracter personal și,
        // pusă în link, face pagina să semene cu o capcană de phishing.
        $_SESSION[self::SESIUNE_EMAIL] = $email;
        return '/cont/verifica-email';
    }

    /**
     * Validează selecția de meserie și câmpurile ei înainte de a crea contul,
     * ca să nu rămână utilizatori pe jumătate înregistrați.
     *
     * @return array{required: bool, error: ?string, profession: ?array<string, mixed>, answers: array<string, mixed>, files: array<int, array<string, mixed>>, gdpr: bool}
     */
    private static function collectProfessionSubmission(PDO $db, array $settings): array
    {
        $result = [
            'required' => false,
            'error' => null,
            'profession' => null,
            'answers' => [],
            'files' => [],
            'gdpr' => false,
        ];

        $professions = ProfessionAccounts::professions($db, true);
        if ($professions === []) {
            // Nicio meserie configurată încă: înregistrarea rămâne cea clasică.
            return $result;
        }

        $result['required'] = true;
        $professionId = (int) ($_POST['profession_id'] ?? 0);
        $profession = null;
        foreach ($professions as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $professionId) {
                $profession = $candidate;
                break;
            }
        }

        if (!is_array($profession)) {
            $result['error'] = 'Alege tipul de cont din listă.';
            return $result;
        }
        $result['profession'] = $profession;

        $gdprLabel = trim((string) ($settings['account_gdpr_label'] ?? ''));
        $gdprAccepted = ((string) ($_POST['account_gdpr_accept'] ?? '')) === '1';
        if ($gdprLabel !== '' && !$gdprAccepted) {
            $result['error'] = 'Trebuie să accepți prelucrarea datelor pentru a crea contul.';
            return $result;
        }
        $result['gdpr'] = $gdprAccepted;

        $posted = $_POST['profession_field'][$professionId] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }

        $allowedExtensions = ProfessionAccounts::allowedExtensions($settings);
        $maxBytes = ProfessionAccounts::maxUploadBytes($settings);
        $maxFiles = (int) ($settings['account_upload_max_files'] ?? 10);
        if ($maxFiles <= 0) {
            $maxFiles = 10;
        }

        foreach (ProfessionAccounts::professionFields($db, $professionId, true) as $field) {
            $fieldKey = (string) ($field['field_key'] ?? '');
            if ($fieldKey === '') {
                continue;
            }
            $label = (string) ($field['label'] ?? $fieldKey);
            $isRequired = (int) ($field['is_required'] ?? 0) === 1;
            $fieldType = (string) ($field['field_type'] ?? 'text');

            if ($fieldType === 'file') {
                $file = $_FILES['profession_file_' . $professionId . '_' . $fieldKey] ?? null;
                $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if (!$hasFile) {
                    if ($isRequired) {
                        $result['error'] = 'Încarcă documentul „' . $label . '”.';
                        return $result;
                    }
                    continue;
                }

                $validated = self::validateVerificationUpload($file, $allowedExtensions, $maxBytes);
                if (is_string($validated)) {
                    $result['error'] = 'Documentul „' . $label . '”: ' . $validated;
                    return $result;
                }

                if (count($result['files']) >= $maxFiles) {
                    $result['error'] = 'Poți încărca maximum ' . $maxFiles . ' fișiere.';
                    return $result;
                }

                $validated['field_key'] = $fieldKey;
                $validated['field_label'] = $label;
                $result['files'][] = $validated;
                $result['answers'][$fieldKey] = [
                    'label' => $label,
                    'type' => 'file',
                    'value' => (string) ($validated['original_name'] ?? ''),
                ];
                continue;
            }

            if ($fieldType === 'checkbox') {
                $checked = isset($posted[$fieldKey]) && (string) $posted[$fieldKey] === '1';
                if ($isRequired && !$checked) {
                    $result['error'] = 'Bifează „' . $label . '”.';
                    return $result;
                }
                $result['answers'][$fieldKey] = [
                    'label' => $label,
                    'type' => 'checkbox',
                    'value' => $checked ? 'Da' : 'Nu',
                ];
                continue;
            }

            $value = trim((string) ($posted[$fieldKey] ?? ''));
            if ($value === '') {
                if ($isRequired) {
                    $result['error'] = 'Completează câmpul „' . $label . '”.';
                    return $result;
                }
                continue;
            }

            if ($fieldType === 'select') {
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                if ($options !== [] && !in_array($value, $options, true)) {
                    $result['error'] = 'Valoarea aleasă pentru „' . $label . '” nu este validă.';
                    return $result;
                }
            }

            if ($fieldType === 'date' && strtotime($value) === false) {
                $result['error'] = 'Data completată pentru „' . $label . '” nu este validă.';
                return $result;
            }

            if ($fieldType === 'number' && !is_numeric($value)) {
                $result['error'] = 'Câmpul „' . $label . '” trebuie să fie un număr.';
                return $result;
            }

            $result['answers'][$fieldKey] = [
                'label' => $label,
                'type' => $fieldType,
                'value' => mb_substr($value, 0, 2000),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|string Datele fișierului validat sau mesajul de eroare.
     */
    private static function validateVerificationUpload(array $file, array $allowedExtensions, int $maxBytes): array|string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'fișierul depășește dimensiunea maximă acceptată.';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'încărcarea nu a reușit. Încearcă din nou.';
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return 'fișierul nu a putut fi citit.';
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return 'fișierul este gol.';
        }
        if ($size > $maxBytes) {
            return 'fișierul depășește ' . ProfessionAccounts::humanFileSize($maxBytes) . '.';
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            return 'formatul nu este acceptat (' . strtoupper(implode(', ', $allowedExtensions)) . ').';
        }

        // Extensia poate minți; verificăm și conținutul real al fișierului.
        $detectedMime = '';
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detectedMime = (string) @finfo_file($finfo, $tmpPath);
                @finfo_close($finfo);
            }
        }
        if ($detectedMime !== '') {
            $allowedMimes = [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'image/heic',
            ];
            if (!in_array($detectedMime, $allowedMimes, true)) {
                return 'conținutul fișierului nu corespunde unui document sau unei imagini.';
            }
        }

        return [
            'tmp_path' => $tmpPath,
            'original_name' => mb_substr($originalName !== '' ? $originalName : ('document.' . $extension), 0, 255),
            'extension' => $extension,
            'mime_type' => $detectedMime !== '' ? $detectedMime : (string) ($file['type'] ?? ''),
            'file_size' => $size,
        ];
    }

    /**
     * Salvează cererea de verificare și trimite emailul de confirmare. Cererea devine
     * vizibilă pentru administratori abia după confirmarea adresei de email.
     */
    private static function storeProfessionSubmission(
        PDO $db,
        int $userId,
        array $submission,
        array $settings,
        string $customerName,
        string $customerEmail,
        bool $emailAlreadyConfirmed = false
    ): void {
        $profession = is_array($submission['profession'] ?? null) ? $submission['profession'] : [];
        $professionId = (int) ($profession['id'] ?? 0);
        if ($userId <= 0 || $professionId <= 0) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $answers = (array) ($submission['answers'] ?? []);
        // Cerere trimisă din contul propriu: adresa a fost deja confirmată o dată,
        // deci sare direct în verificarea administratorului.
        $status = $emailAlreadyConfirmed
            ? ProfessionAccounts::STATUS_PENDING
            : ProfessionAccounts::STATUS_AWAITING_EMAIL;

        try {
            $stmt = $db->prepare(
                'INSERT INTO account_verifications
                    (user_id, profession_id, profession_name, status, answers_json, gdpr_accepted,
                     gdpr_accepted_at, submitted_ip, submitted_user_agent, confirm_token_hash, confirmed_at)
                 VALUES
                    (:user_id, :profession_id, :profession_name, :status, :answers_json, :gdpr_accepted,
                     :gdpr_accepted_at, :submitted_ip, :submitted_user_agent, :confirm_token_hash, :confirmed_at)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'profession_id' => $professionId,
                'profession_name' => (string) ($profession['name'] ?? ''),
                'status' => $status,
                'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                'gdpr_accepted' => !empty($submission['gdpr']) ? 1 : 0,
                'gdpr_accepted_at' => !empty($submission['gdpr']) ? date('Y-m-d H:i:s') : null,
                'submitted_ip' => mb_substr(IpClient::adresa(), 0, 64) ?: null,
                'submitted_user_agent' => mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255) ?: null,
                'confirm_token_hash' => $emailAlreadyConfirmed ? null : hash('sha256', $token),
                'confirmed_at' => $emailAlreadyConfirmed ? date('Y-m-d H:i:s') : null,
            ]);
            $verificationId = (int) $db->lastInsertId();
        } catch (Throwable $exception) {
            DonatiiController::logAccountIssue('salvare_cerere', $exception->getMessage());
            Flash::set('error', 'Cererea ta nu a putut fi salvată. Te rugăm să ne contactezi.');
            return;
        }

        if ($verificationId <= 0) {
            DonatiiController::logAccountIssue('salvare_cerere', 'INSERT-ul nu a returnat un id.');
            Flash::set('error', 'Cererea ta nu a putut fi salvată. Te rugăm să ne contactezi.');
            return;
        }

        self::persistVerificationDocuments($db, $verificationId, (array) ($submission['files'] ?? []));

        try {
            $update = $db->prepare(
                'UPDATE users SET profession_id = :pid, account_status = :status WHERE id = :id'
            );
            $update->execute([
                'pid' => $professionId,
                'status' => $status,
                'id' => $userId,
            ]);
        } catch (Throwable) {
        }

        if ($emailAlreadyConfirmed) {
            $verification = ProfessionAccounts::verification($db, $verificationId);
            if (is_array($verification)) {
                self::notifyAdminOfVerification($db, $settings, $verification);
            }
            return;
        }

        self::sendVerificationConfirmationEmail($db, $settings, $customerEmail, $customerName, $token);
    }

    /** Mută fișierele încărcate în storage (în afara document root) și le înregistrează. */
    private static function persistVerificationDocuments(PDO $db, int $verificationId, array $files): void
    {
        if ($files === [] || !ProfessionAccounts::ensureDocumentRoot()) {
            return;
        }

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $tmpPath = (string) ($file['tmp_path'] ?? '');
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                continue;
            }

            $storedName = ProfessionAccounts::generateStoredName((string) ($file['extension'] ?? 'bin'));
            $targetPath = ProfessionAccounts::documentPath($storedName);
            if (!@move_uploaded_file($tmpPath, $targetPath)) {
                continue;
            }
            @chmod($targetPath, 0640);

            try {
                $stmt = $db->prepare(
                    'INSERT INTO account_verification_documents
                        (verification_id, field_key, field_label, original_name, stored_name, mime_type, file_size)
                     VALUES (:vid, :field_key, :field_label, :original_name, :stored_name, :mime_type, :file_size)'
                );
                $stmt->execute([
                    'vid' => $verificationId,
                    'field_key' => (string) ($file['field_key'] ?? '') ?: null,
                    'field_label' => (string) ($file['field_label'] ?? '') ?: null,
                    'original_name' => (string) ($file['original_name'] ?? 'document'),
                    'stored_name' => $storedName,
                    'mime_type' => (string) ($file['mime_type'] ?? '') ?: null,
                    'file_size' => (int) ($file['file_size'] ?? 0),
                ]);
            } catch (Throwable) {
                @unlink($targetPath);
            }
        }
    }

    private static function sendVerificationConfirmationEmail(
        PDO $db,
        array $settings,
        string $email,
        string $name,
        string $token
    ): void {
        if ($email === '' || $token === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $link = \App\Support\AppUrl::base() . '/cont/confirmare/' . urlencode($token);
        $displayName = trim($name) !== '' ? trim($name) : 'coleg';
        $html = BrandedEmail::render(DonatiiController::emailBrandSettings($settings), [
            'title' => 'Confirmă adresa de email',
            'greeting' => 'Bună, ' . $displayName . '!',
            'intro' => 'Îți mulțumim pentru cererea de înscriere. Mai avem nevoie de un singur pas din partea ta,'
                . ' apoi preluăm noi restul.',
            'steps' => [
                [
                    'title' => 'Confirmă adresa de email',
                    'text' => 'Apasă butonul de mai jos. Abia după acest pas îți poți accesa contul.',
                ],
                [
                    'title' => 'Îți verificăm documentele',
                    'text' => 'Un membru al echipei se uită peste datele trimise și te anunțăm pe email'
                        . ' imediat ce cererea este aprobată.',
                ],
                [
                    'title' => 'Achiți cotizația',
                    'text' => 'Din contul tău alegi perioada dorită și plătești securizat. Contul devine activ pe loc.',
                ],
            ],
            'button' => ['label' => 'Confirmă adresa de email', 'url' => $link],
            'note' => 'Dacă nu tu ai creat acest cont, ignoră acest email — nu se întâmplă nimic fără confirmare.',
            'recipient' => $email,
        ]);

        try {
            OrderMailer::sendCustom($email, 'Confirmă adresa de email', $html, $settings, $db, [
                'email_type' => 'account_verification_confirm',
                'source' => 'customer_account',
                'trigger' => 'account_verification_submitted',
            ]);
        } catch (Throwable $exception) {
            DonatiiController::logAccountIssue('confirmare_email', $exception->getMessage());
            Flash::set(
                'error',
                'Contul a fost creat, dar emailul de confirmare nu a putut fi trimis.'
                . ' Folosește butonul „Trimite-mi din nou emailul” sau contactează-ne.'
            );
        }
    }

    private static function notifyAdminOfVerification(PDO $db, array $settings, array $verification): void
    {
        $recipient = trim((string) ($settings['account_verification_notify_email'] ?? ''));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $verificationId = (int) ($verification['id'] ?? 0);
        $link = \App\Support\AppUrl::base() . '/admin/accounts/verifications/' . $verificationId;
        $html = BrandedEmail::render(DonatiiController::emailBrandSettings($settings), [
            'title' => 'Cerere nouă de cont',
            'greeting' => 'A intrat o cerere nouă',
            'intro' => 'Un utilizator și-a confirmat adresa de email, iar cererea lui așteaptă verificarea documentelor.',
            'facts' => [
                ['label' => 'Categorie', 'value' => (string) ($verification['profession_name'] ?? '—')],
                ['label' => 'Cerere', 'value' => '#' . $verificationId],
            ],
            'button' => ['label' => 'Deschide cererea', 'url' => $link],
            'recipient' => $recipient,
        ]);

        try {
            OrderMailer::sendCustom($recipient, 'Cerere nouă de cont profesional', $html, $settings, $db, [
                'email_type' => 'account_verification_admin',
                'source' => 'admin_notification',
                'trigger' => 'account_verification_confirmed',
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Contul cu adresa `$email` a cărui înscriere de membru așteaptă confirmarea: adresa nedovedită pentru nucleu
     * (`users.email_confirmed_at`) și ultima lui cerere încă „awaiting_email”. `nefolosit`: contul nu a fost folosit deloc
     * (fără Google, fără puncte, comenzi, plăți ale cotizației sau donații din cont; o parolă resetată sau o logare cu Google
     * dovedesc oricum adresa, deci contul nu mai e „neconfirmat”).
     *
     * @return array{user_id: int, verification_id: int, nefolosit: bool, creata: string}|null
     */
    private static function findUnconfirmedAccount(PDO $db, string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        try {
            $stmt = $db->prepare('SELECT id, google_id, loyalty_points FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
        } catch (Throwable) {
            return null;
        }
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId <= 0 || ConfirmareEmail::confirmat($db, $userId)) {
            return null;
        }
        $verification = ProfessionAccounts::latestVerification($db, $userId);
        if (!is_array($verification) || (string) ($verification['status'] ?? '') !== ProfessionAccounts::STATUS_AWAITING_EMAIL) {
            return null;
        }

        $nefolosit = trim((string) ($user['google_id'] ?? '')) === '' && (int) ($user['loyalty_points'] ?? 0) === 0;
        foreach ([
            'SELECT COUNT(*) FROM orders WHERE user_id = :id',
            'SELECT COUNT(*) FROM account_activations WHERE user_id = :id',
            'SELECT COUNT(*) FROM donations WHERE user_id = :id',
        ] as $sql) {
            if (!$nefolosit) {
                break;
            }
            try {
                $q = $db->prepare($sql);
                $q->execute(['id' => $userId]);
                $nefolosit = (int) $q->fetchColumn() === 0;
            } catch (Throwable) {
                // Un tabel sau o coloană care lipsește (alt plugin): nimic de numărat acolo.
            }
        }

        return [
            'user_id' => $userId,
            'verification_id' => (int) ($verification['id'] ?? 0),
            'nefolosit' => $nefolosit,
            'creata' => (string) ($verification['created_at'] ?? ''),
        ];
    }

    // ================================================================== confirmarea adresei

    /** Cererea care așteaptă confirmarea, după tokenul din link (doar hash-ul lui e în bază), sau null. */
    private static function verificareDupaToken(PDO $db, string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        try {
            // Linkul e valabil 7 zile de la trimitere (ca linkul nucleului): `updated_at` se schimbă când se scrie tokenul (la
            // înscriere și la fiecare retrimitere), iar o cerere în „awaiting_email” nu se schimbă altfel.
            $stmt = $db->prepare('SELECT * FROM account_verifications WHERE confirm_token_hash = :hash
                AND COALESCE(updated_at, created_at) >= (NOW() - INTERVAL ' . self::ZILE_LINK_CONFIRMARE . ' DAY) LIMIT 1');
            $stmt->execute(['hash' => hash('sha256', $token)]);
            $verification = $stmt->fetch() ?: null;
        } catch (Throwable) {
            $verification = null;
        }
        return is_array($verification) ? $verification : null;
    }

    /**
     * Agățarea `confirmare.link_plugin` (GET `/cont/confirmare/{token}`, după linkurile nucleului): tokenul e al unei cereri
     * de membru? Atunci pagina nucleului cu butonul „Confirmă adresa” (și parola contului) se arată pentru el.
     */
    public static function linkConfirmare(PDO $db, string $token): bool
    {
        return self::verificareDupaToken($db, $token) !== null;
    }

    /**
     * Agățarea `confirmare.foloseste_plugin` (POST `/cont/confirmare/{token}`, apăsarea butonului): confirmarea adresei
     * unei cereri de membru (vertij `accountVerificationConfirm`), cu parola contului. Null: tokenul nu e al pluginului.
     *
     * @return array{location: string, flash: array{0: string, 1: string}}|null
     */
    public static function folosesteLink(PDO $db, string $token, string $parola): ?array
    {
        $verification = self::verificareDupaToken($db, $token);
        if ($verification === null) {
            return null;
        }

        $status = (string) ($verification['status'] ?? '');
        if ($status !== ProfessionAccounts::STATUS_AWAITING_EMAIL) {
            return ['location' => '/contul-meu?section=activare', 'flash' => ['success', 'Adresa de email era deja confirmată. Cererea ta este în lucru.']];
        }

        $confirmedUserId = (int) ($verification['user_id'] ?? 0);
        $stmt = $db->prepare('SELECT email, password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $confirmedUserId]);
        $cont = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($cont) || $parola === '' || !\App\Support\WordPressPassword::verify($parola, (string) ($cont['password_hash'] ?? ''))) {
            return ['location' => '/cont/confirmare/' . rawurlencode($token),
                'flash' => ['error', 'Parola nu este corectă. Scrie parola contului pe care l-ai creat, apoi apasă „Confirmă adresa”.']];
        }

        $verificationId = (int) ($verification['id'] ?? 0);
        try {
            $update = $db->prepare(
                'UPDATE account_verifications
                 SET status = :status, confirmed_at = NOW(), confirm_token_hash = NULL
                 WHERE id = :id AND confirm_token_hash = :hash'
            );
            $update->execute([
                'status' => ProfessionAccounts::STATUS_PENDING,
                'id' => $verificationId,
                'hash' => hash('sha256', trim($token)),
            ]);
            if ($update->rowCount() !== 1) {
                // Folosit chiar acum de altă cerere.
                return ['location' => '/contul-meu', 'flash' => ['error', 'Link-ul de confirmare nu este valid sau a fost deja folosit.']];
            }

            $updateUser = $db->prepare('UPDATE users SET account_status = :status WHERE id = :id');
            $updateUser->execute([
                'status' => ProfessionAccounts::STATUS_PENDING,
                'id' => $confirmedUserId,
            ]);
        } catch (Throwable) {
        }

        $verification['status'] = ProfessionAccounts::STATUS_PENDING;
        $settings = Settings::all($db);
        self::notifyAdminOfVerification($db, $settings, $verification);

        // Confirmarea dovedește că adresa îi aparține (nucleul: comenzile și punctele fără cont, donațiile), deci îl ducem
        // direct în cont.
        if ($confirmedUserId > 0) {
            try {
                ConfirmareEmail::marcheazaConfirmat($db, $confirmedUserId, 'membri', (string) ($cont['email'] ?? ''));
            } catch (Throwable) {
            }
            CustomerAuth::login($confirmedUserId);
        }

        return ['location' => '/contul-meu?section=activare', 'flash' => ['success', 'Adresa de email a fost confirmată. Cererea ta a intrat la verificare.']];
    }

    /**
     * Agățarea `confirmare.dupa` (linkul nucleului folosit: de exemplu cel retrimis de pe pagina de intrare): cererea de
     * membru care aștepta confirmarea pe email trece la verificare, ca la linkul pluginului.
     */
    public static function dupaConfirmareNucleu(PDO $db, int $userId): void
    {
        $verification = ProfessionAccounts::latestVerification($db, $userId);
        if (!is_array($verification) || (string) ($verification['status'] ?? '') !== ProfessionAccounts::STATUS_AWAITING_EMAIL) {
            return;
        }
        try {
            $db->prepare('UPDATE account_verifications SET status = :status, confirmed_at = NOW(), confirm_token_hash = NULL WHERE id = :id')
                ->execute(['status' => ProfessionAccounts::STATUS_PENDING, 'id' => (int) $verification['id']]);
            $db->prepare('UPDATE users SET account_status = :status WHERE id = :id')
                ->execute(['status' => ProfessionAccounts::STATUS_PENDING, 'id' => $userId]);
        } catch (Throwable) {
            return;
        }
        $verification['status'] = ProfessionAccounts::STATUS_PENDING;
        self::notifyAdminOfVerification($db, Settings::all($db), $verification);
    }

    /**
     * Agățarea `logare.blocata` (cu `customer_email_confirmation_required`): un cont cu cererea „așteaptă confirmarea pe
     * email” și adresa nedovedită nu intră până nu confirmă.
     */
    public static function logareBlocata(PDO $db, int $userId): bool
    {
        if ($userId <= 0 || ConfirmareEmail::confirmat($db, $userId)) {
            return false;
        }
        $verification = ProfessionAccounts::latestVerification($db, $userId);
        if (!is_array($verification) || (string) ($verification['status'] ?? '') !== ProfessionAccounts::STATUS_AWAITING_EMAIL) {
            return false;
        }
        // Cererile de dinainte de adoptare (codul vertij lăsa contul să intre și neconfirmat): nu blocăm pe nimeni care intra
        // ieri. Adoptarea = momentul în care migrarea membri/0063 a găsit tabelele vertij („detectata”); pe un site nou nu e.
        $adoptare = self::momentulAdoptarii($db);
        return $adoptare === '' || (string) ($verification['created_at'] ?? '') >= $adoptare;
    }

    /** Când a găsit migrarea `membri/0063` tabelele unui site existent (adoptarea vertij), sau '' (instalare nouă). */
    private static function momentulAdoptarii(PDO $db): string
    {
        static $moment = null;
        if ($moment !== null) {
            return $moment;
        }
        try {
            $stmt = $db->query("SELECT terminata_la FROM _platforma_migrari WHERE id = 'membri/0063_membri' AND rezultat = 'detectata' LIMIT 1");
            $moment = $stmt !== false ? trim((string) ($stmt->fetchColumn() ?: '')) : '';
        } catch (Throwable) {
            $moment = '';
        }
        return $moment;
    }

    /** `GET /cont/verifica-email`: pagina afișată imediat după înregistrare, cât timp adresa nu e confirmată. */
    public function verificaEmail(): void
    {
        $db = $this->db();
        if ($db instanceof PDO && CustomerAuth::check()) {
            header('Location: /contul-meu?section=activare');
            return;
        }

        $email = trim((string) ($_SESSION[self::SESIUNE_EMAIL] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
            unset($_SESSION[self::SESIUNE_EMAIL]);
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sentTo = $email !== ''
            ? '<p class="verify-email-address">Am trimis emailul la <strong>' . $escape($email) . '</strong></p>'
            : '';

        $html = '<section class="verify-email">'
            . '<div class="verify-email-card">'
            . '<div class="verify-email-icon">✉</div>'
            . '<h1>Verifică-ți adresa de email</h1>'
            . '<p class="verify-email-lead">Contul tău a fost creat, dar mai e nevoie de un pas: '
            . 'confirmă adresa de email ca să îl poți folosi.</p>'
            . $sentTo
            . '<ol class="verify-email-steps">'
            . '<li><strong>Deschide emailul</strong> primit de la noi și apasă butonul de confirmare.</li>'
            . '<li><strong>Echipa noastră îți verifică documentele</strong> — te anunțăm pe email când e gata.</li>'
            . '<li><strong>Achiți cotizația</strong> din contul tău și devii membru cu drepturi depline.</li>'
            . '</ol>'
            . '<p class="verify-email-note">Nu găsești emailul? Verifică folderul Spam sau Promoții. '
            . 'Poate dura câteva minute până ajunge.</p>'
            . '<form class="verify-email-resend" method="post" action="/cont/retrimite-confirmarea">' . AdminCsrf::camp()
            . ($email !== ''
                ? '<button type="submit" class="is-wide">Trimite-mi din nou emailul</button>'
                : '<label for="verify-email-resend-input">Trimite-mi din nou emailul</label>'
                    . '<div class="verify-email-resend-row">'
                    . '<input id="verify-email-resend-input" type="email" name="email" required'
                    . ' autocomplete="email" placeholder="adresa ta de email">'
                    . '<button type="submit">Retrimite</button>'
                    . '</div>')
            . '</form>'
            . '<a class="verify-email-btn" href="/login">Mergi la autentificare</a>'
            . '</div>'
            . '</section>';

        $css = '.verify-email{display:flex;justify-content:center;padding:52px 18px;}'
            . '.verify-email-card{max-width:600px;width:100%;background:#fff;border:1px solid #efe3f2;border-radius:20px;'
            . 'box-shadow:0 18px 44px rgba(111,43,152,.09);padding:38px 34px;text-align:center;}'
            . '.verify-email-icon{width:64px;height:64px;margin:0 auto 18px;border-radius:50%;font-size:28px;line-height:64px;'
            . 'color:#fff;background:linear-gradient(135deg,#ea4968,#6f2b98);}'
            . '.verify-email-card h1{margin:0 0 12px;font-size:26px;line-height:1.25;color:#1f2937;}'
            . '.verify-email-lead{margin:0 0 14px;color:#4b5563;font-size:15.5px;line-height:1.65;}'
            . '.verify-email-address{margin:0 0 22px;padding:11px 16px;border-radius:10px;background:#faf6fc;'
            . 'color:#4b5563;font-size:14.5px;}'
            . '.verify-email-steps{margin:0 0 22px;padding:0 0 0 20px;text-align:left;color:#4b5563;'
            . 'font-size:14.5px;line-height:1.7;}'
            . '.verify-email-steps li{margin-bottom:8px;}'
            . '.verify-email-steps strong{color:#1f2937;}'
            . '.verify-email-note{margin:0 0 24px;padding:13px 16px;border-radius:10px;background:#fdf7f9;'
            . 'color:#6b7280;font-size:13.5px;line-height:1.6;}'
            . '.verify-email-resend{margin:0 0 22px;padding:16px 18px;border-radius:12px;background:#faf6fc;text-align:left;}'
            . '.verify-email-resend label{display:block;margin-bottom:8px;font-size:13px;font-weight:700;color:#4b5563;}'
            . '.verify-email-resend-row{display:flex;gap:9px;flex-wrap:wrap;}'
            . '.verify-email-resend input{flex:1;min-width:190px;box-sizing:border-box;padding:11px 13px;'
            . 'border:1px solid #e5d9ec;border-radius:10px;font-size:14.5px;background:#fff;font-family:inherit;}'
            . '.verify-email-resend button{padding:11px 20px;border:0;border-radius:10px;cursor:pointer;'
            . 'font-weight:700;font-size:14px;font-family:inherit;background:#6f2b98;color:#fff;}'
            . '.verify-email-resend button.is-wide{width:100%;padding:12px 20px;font-size:14.5px;}'
            . '.verify-email-btn{display:inline-block;padding:13px 28px;border-radius:12px;font-weight:700;font-size:15px;'
            . 'text-decoration:none;background:linear-gradient(135deg,#ea4968,#6f2b98);color:#fff;}'
            . '@media(max-width:560px){.verify-email-card{padding:28px 20px;}}';

        header('Cache-Control: no-store');
        View::render('site/custom-page', [
            'title' => 'Verifică-ți adresa de email',
            'page' => ['html_content' => $html, 'css_content' => $css, 'js_content' => ''],
            'mannequinSectionHtml' => '',
            'metaRobots' => 'noindex, nofollow',
        ]);
    }

    /**
     * Retrimite emailul de confirmare pentru o cerere neconfirmată, cu un token nou.
     * Întoarce `false` dacă adresa nu are o astfel de cerere.
     */
    private static function resendVerificationEmail(PDO $db, string $email, array $settings): bool
    {
        $account = self::findUnconfirmedAccount($db, $email);
        if ($account === null) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        try {
            $stmt = $db->prepare(
                'UPDATE account_verifications
                 SET confirm_token_hash = :hash
                 WHERE id = :id AND status = :status'
            );
            $stmt->execute([
                'hash' => hash('sha256', $token),
                'id' => $account['verification_id'],
                'status' => ProfessionAccounts::STATUS_AWAITING_EMAIL,
            ]);

            $userStmt = $db->prepare('SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1');
            $userStmt->execute(['id' => $account['user_id']]);
            $user = $userStmt->fetch() ?: [];
        } catch (Throwable $exception) {
            DonatiiController::logAccountIssue('retrimitere', $exception->getMessage());
            return false;
        }

        $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
        self::sendVerificationConfirmationEmail($db, $settings, (string) ($user['email'] ?? $email), $name, $token);

        return true;
    }

    /** `POST /cont/retrimite-confirmarea`: formularul „nu am primit emailul” de pe pagina de verificare. */
    public function retrimite(): void
    {
        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea la baza de date nu este disponibilă.');
            header('Location: /cont/verifica-email');
            return;
        }
        // Jetonul formularului (revizuirea de securitate, 9 nov 2026): un formular de pe alt site nu poate trimite emailuri
        // de confirmare în numele unui vizitator.
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Încearcă din nou.');
            header('Location: ' . (CustomerAuth::check() ? '/contul-meu?section=activare' : '/cont/verifica-email'));
            return;
        }

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $isLoggedIn = false;
        if ($email === '') {
            // Cerut din contul propriu: adresa o știm deja, nu o mai cerem.
            $currentUser = CustomerAuth::user($db);
            if (is_array($currentUser)) {
                $email = strtolower(trim((string) ($currentUser['email'] ?? '')));
                $isLoggedIn = true;
            }
        }
        if ($email === '') {
            // Cerut de pe pagina de verificare, imediat după înscriere.
            $email = strtolower(trim((string) ($_SESSION[self::SESIUNE_EMAIL] ?? '')));
        }
        $ip = mb_substr(IpClient::adresa(), 0, 64);

        // Răspunsul e identic indiferent de rezultat, ca să nu se poată afla ce adrese
        // există în sistem, iar limita pe IP oprește folosirea formularului ca sursă de spam.
        $neutralMessage = 'Dacă adresa are o înscriere neconfirmată, ți-am trimis din nou emailul de confirmare.';

        $redirectTo = $isLoggedIn ? '/contul-meu?section=activare' : '/cont/verifica-email';

        // Limita nu depinde de existența adresei, deci putem fi expliciți fără să
        // dezvăluim cine e înscris — iar a pretinde că am trimis ar fi minciună.
        if (ConfirmareEmail::limitat($db, $ip, 'resend')) {
            Flash::set('error', 'Ai cerut prea multe emailuri în ultimele minute. Încearcă din nou peste 15 minute.');
            header('Location: ' . $redirectTo);
            return;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Introdu o adresă de email validă.');
            header('Location: ' . $redirectTo);
            return;
        }

        ConfirmareEmail::noteazaRetrimitere($db, $ip);
        $sent = self::resendVerificationEmail($db, $email, Settings::all($db));

        if ($isLoggedIn) {
            Flash::set(
                'success',
                $sent
                    ? 'Ți-am trimis din nou emailul de confirmare.'
                    : 'Adresa ta este deja confirmată.'
            );
            header('Location: ' . $redirectTo);
            return;
        }

        $_SESSION[self::SESIUNE_EMAIL] = $email;
        Flash::set('success', $neutralMessage);
        header('Location: ' . $redirectTo);
    }

    // ================================================================== cererea nouă din cont

    /** `GET /contul-meu/cerere-noua`: un membru cu cererea respinsă (sau fără cerere) trimite una nouă, fără alt cont. */
    public function cerereNoua(): void
    {
        $context = $this->newRequestContext();
        if ($context === null) {
            return;
        }

        $settings = $context['settings'];
        $professionData = self::professionRegistrationData($context['db'], $settings);
        if (($professionData['professions'] ?? []) === []) {
            Flash::set('error', 'Nu există categorii de membru configurate.');
            header('Location: /contul-meu?section=activare');
            return;
        }

        $config = $professionData['config'];
        $config['hide_account_fields'] = true;

        View::render('site/membri/account-new-request', [
            'title' => 'Trimite o cerere nouă',
            'professions' => $professionData['professions'],
            'professionConfig' => $config,
            'accountState' => $context['state'],
            'customer' => $context['user'],
        ], 'layout');
    }

    /** `POST /contul-meu/cerere-noua`. */
    public function cerereNouaTrimite(): void
    {
        $context = $this->newRequestContext();
        if ($context === null) {
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Încearcă din nou.');
            header('Location: /contul-meu/cerere-noua');
            return;
        }

        $db = $context['db'];
        $settings = $context['settings'];
        $user = $context['user'];

        $submission = self::collectProfessionSubmission($db, $settings);
        if (($submission['error'] ?? null) !== null) {
            Flash::set('error', (string) $submission['error']);
            header('Location: /contul-meu/cerere-noua');
            return;
        }
        if (!is_array($submission['profession'] ?? null)) {
            Flash::set('error', 'Alege categoria de membru.');
            header('Location: /contul-meu/cerere-noua');
            return;
        }

        $name = trim(trim((string) ($user['first_name'] ?? '')) . ' ' . trim((string) ($user['last_name'] ?? '')));
        self::storeProfessionSubmission(
            $db,
            (int) ($user['id'] ?? 0),
            $submission,
            $settings,
            $name !== '' ? $name : (string) ($user['email'] ?? ''),
            (string) ($user['email'] ?? ''),
            true
        );

        Flash::set('success', 'Cererea ta a fost trimisă. Îți răspundem pe email după verificare.');
        header('Location: /contul-meu?section=activare');
    }

    /**
     * Datele comune celor două metode de mai sus: utilizatorul autentificat,
     * setările și starea contului — plus verificarea că are voie să re-aplice.
     *
     * @return array{db: PDO, settings: array<string, mixed>, user: array<string, mixed>, state: array<string, mixed>}|null
     */
    private function newRequestContext(): ?array
    {
        $db = $this->db();
        $userId = CustomerAuth::id();
        if (!$db instanceof PDO || $userId === null || $userId <= 0) {
            Flash::set('error', 'Autentifică-te pentru a trimite o cerere.');
            header('Location: /login');
            return null;
        }

        $settings = Settings::all($db);

        try {
            $stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();
        } catch (Throwable) {
            $user = null;
        }

        if (!is_array($user)) {
            Flash::set('error', 'Contul nu a putut fi încărcat.');
            header('Location: /contul-meu');
            return null;
        }

        $state = ProfessionAccounts::accountState($db, $user, $settings);
        if (empty($state['can_reapply'])) {
            Flash::set('error', self::newRequestBlockedReason((string) ($state['verification_status'] ?? 'none')));
            header('Location: /contul-meu?section=activare');
            return null;
        }

        return ['db' => $db, 'settings' => $settings, 'user' => $user, 'state' => $state];
    }

    private static function newRequestBlockedReason(string $status): string
    {
        return match ($status) {
            ProfessionAccounts::STATUS_AWAITING_EMAIL => 'Confirmă întâi adresa de email din cererea trimisă.',
            ProfessionAccounts::STATUS_PENDING => 'Ai deja o cerere în verificare.',
            ProfessionAccounts::STATUS_APPROVED => 'Cererea ta este deja aprobată.',
            default => 'Nu poți trimite o cerere nouă acum.',
        };
    }

    // ================================================================== poza de profil

    /**
     * `POST /contul-meu/poza`: încarcă poza de profil. Fișierul ajunge în galerie, într-un folder dedicat,
     * ca administratorii să îl regăsească alături de restul imaginilor site-ului.
     */
    public function poza(): void
    {
        $db = $this->db();
        $userId = CustomerAuth::id();
        if (!$db instanceof PDO || $userId === null || $userId <= 0) {
            Flash::set('error', 'Trebuie să fii autentificat pentru a schimba poza de profil.');
            header('Location: /login');
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Încearcă din nou.');
            header('Location: /contul-meu?section=profile');
            return;
        }

        $settings = Settings::all($db);

        if (((string) ($_POST['remove_avatar'] ?? '')) === '1') {
            try {
                $db->prepare('UPDATE users SET avatar_url = NULL WHERE id = :id')->execute(['id' => $userId]);
                Flash::set('success', 'Poza de profil a fost ștearsă.');
            } catch (Throwable) {
                Flash::set('error', 'Nu am putut șterge poza de profil.');
            }
            header('Location: /contul-meu?section=profile');
            return;
        }

        $file = $_FILES['avatar'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::set('error', 'Alege o imagine înainte de a salva.');
            header('Location: /contul-meu?section=profile');
            return;
        }

        $maxMb = (float) ($settings['account_avatar_max_mb'] ?? 3);
        if ($maxMb <= 0) {
            $maxMb = 3.0;
        }
        $validated = self::validateVerificationUpload(
            $file,
            ['jpg', 'jpeg', 'png', 'webp'],
            (int) round($maxMb * 1024 * 1024)
        );
        if (is_string($validated)) {
            Flash::set('error', 'Poza de profil: ' . $validated);
            header('Location: /contul-meu?section=profile');
            return;
        }

        $mime = (string) ($validated['mime_type'] ?? '');
        if ($mime !== '' && !str_starts_with($mime, 'image/')) {
            Flash::set('error', 'Poza de profil trebuie să fie o imagine.');
            header('Location: /contul-meu?section=profile');
            return;
        }

        $publicDir = Paths::uploads('gallery/profile');
        if (!is_dir($publicDir) && !@mkdir($publicDir, 0775, true) && !is_dir($publicDir)) {
            Flash::set('error', 'Nu am putut pregăti folderul pentru poze de profil.');
            header('Location: /contul-meu?section=profile');
            return;
        }

        $extension = (string) ($validated['extension'] ?? 'jpg');
        $fileName = 'membru-' . $userId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        $targetPath = $publicDir . '/' . $fileName;
        if (!@move_uploaded_file((string) ($validated['tmp_path'] ?? ''), $targetPath)) {
            Flash::set('error', 'Încărcarea imaginii nu a reușit. Încearcă din nou.');
            header('Location: /contul-meu?section=profile');
            return;
        }
        @chmod($targetPath, 0644);

        $avatarUrl = '/uploads/gallery/profile/' . $fileName;
        $previousAvatar = '';
        try {
            $stmt = $db->prepare('SELECT avatar_url FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $previousAvatar = trim((string) ($stmt->fetchColumn() ?: ''));

            $db->prepare('UPDATE users SET avatar_url = :url WHERE id = :id')
                ->execute(['url' => $avatarUrl, 'id' => $userId]);
        } catch (Throwable) {
            @unlink($targetPath);
            Flash::set('error', 'Nu am putut salva poza de profil.');
            header('Location: /contul-meu?section=profile');
            return;
        }

        self::registerAvatarInGallery($db, $settings, $avatarUrl, $userId);
        self::removeReplacedAvatar($db, $previousAvatar, $avatarUrl);

        Flash::set('success', 'Poza de profil a fost actualizată.');
        header('Location: /contul-meu?section=profile');
    }

    /** Înregistrează poza în Galerie, într-un folder propriu creat la nevoie. */
    private static function registerAvatarInGallery(PDO $db, array $settings, string $avatarUrl, int $userId): void
    {
        $folderName = trim((string) ($settings['account_avatar_folder'] ?? 'Poze de profil'));
        if ($folderName === '') {
            $folderName = 'Poze de profil';
        }

        try {
            $stmt = $db->prepare('SELECT id FROM gallery_folders WHERE name = :name LIMIT 1');
            $stmt->execute(['name' => $folderName]);
            $folderId = (int) ($stmt->fetchColumn() ?: 0);

            if ($folderId <= 0) {
                $insertFolder = $db->prepare('INSERT INTO gallery_folders (name, slug) VALUES (:name, :slug)');
                $insertFolder->execute(['name' => $folderName, 'slug' => self::slugifyAvatarFolder($folderName)]);
                $folderId = (int) $db->lastInsertId();
            }

            $insertImage = $db->prepare(
                'INSERT INTO gallery_images (title, media_type, image_url, folder_id, alt_text, sort_order, is_active)
                 VALUES (:title, :media_type, :image_url, :folder_id, :alt_text, 0, 1)'
            );
            $insertImage->execute([
                'title' => 'Poză de profil — membru #' . $userId,
                'media_type' => 'image',
                'image_url' => $avatarUrl,
                'folder_id' => $folderId > 0 ? $folderId : null,
                'alt_text' => 'Poză de profil',
            ]);
        } catch (Throwable) {
            // Poza rămâne salvată pe cont chiar dacă înregistrarea în galerie eșuează.
        }
    }

    /** Poza înlocuită nu mai e folosită nicăieri, deci o scoatem din galerie și de pe disc. */
    private static function removeReplacedAvatar(PDO $db, string $previousAvatar, string $newAvatar): void
    {
        $previousAvatar = trim($previousAvatar);
        if ($previousAvatar === '' || $previousAvatar === $newAvatar) {
            return;
        }
        if (!str_starts_with($previousAvatar, '/uploads/gallery/profile/')) {
            return;
        }

        try {
            $db->prepare('DELETE FROM gallery_images WHERE image_url = :url')->execute(['url' => $previousAvatar]);
        } catch (Throwable) {
        }

        $realPath = realpath(Paths::uploads(substr($previousAvatar, strlen('/uploads/'))));
        $realDir = realpath(Paths::uploads('gallery/profile'));
        if ($realPath !== false && $realDir !== false && str_starts_with($realPath, $realDir . '/')) {
            @unlink($realPath);
        }
    }

    private static function slugifyAvatarFolder(string $name): string
    {
        $slug = strtr(mb_strtolower(trim($name)), [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return $slug !== '' ? substr($slug, 0, 140) : 'poze-de-profil';
    }

    /** Agățarea `cont.profil`: poza de profil (sau inițialele), cu încărcarea ei, deasupra datelor personale. */
    public static function blocPoza(array $customer): string
    {
        if ($customer === []) {
            return '';
        }
        $customer = self::contMembru(self::conexiune(), $customer) ?? $customer;
        return View::fragment('site/membri/poza-profil', ['customer' => $customer]);
    }

    // ================================================================== cotizația (activarea cu plata)

    /** @return array<string, string> Texte editabile din admin pentru secțiunea de activare. */
    private static function activationViewConfig(array $settings): array
    {
        return [
            'title' => (string) ($settings['account_activation_title'] ?? 'Activează contul'),
            'intro' => (string) ($settings['account_activation_intro'] ?? ''),
            'button' => (string) ($settings['account_activation_button'] ?? 'Activează contul'),
            'active_text' => (string) ($settings['account_activation_active_text'] ?? ''),
            'renew_button' => (string) ($settings['account_activation_renew_button'] ?? 'Reactivează contul'),
            'awaiting_email_text' => (string) ($settings['restricted_awaiting_email_text'] ?? ''),
            'pending_text' => (string) ($settings['restricted_pending_text'] ?? ''),
            'rejected_text' => (string) ($settings['restricted_rejected_text'] ?? ''),
        ];
    }

    /**
     * Cotizațiile plătite, cu link-ul chitanței Stripe.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function accountActivationHistory(PDO $db, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $stmt = $db->prepare(
                "SELECT * FROM account_activations
                 WHERE user_id = :uid AND status = 'paid'
                 ORDER BY id DESC LIMIT 50"
            );
            $stmt->execute(['uid' => $userId]);
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Agățarea `cont.taburi`: tab-ul „Cotizația mea” / „Activează contul” (ca în vertij, mereu în cont), cu insigna: zilele
     * rămase în ultima lună, „!” cât cotizația nu e la zi.
     *
     * @return list<array<string, mixed>>
     */
    public static function tabCont(PDO $db, array $user): array
    {
        $user = self::contMembru($db, $user) ?? $user;
        $settings = Settings::all($db);
        $state = ProfessionAccounts::accountState($db, $user, $settings);
        $isActive = (bool) ($state['is_active'] ?? false);
        $daysLeft = (int) ($state['days_left'] ?? 0);
        $insigna = '';
        if ($isActive && $daysLeft > 0 && $daysLeft <= 30) {
            $insigna = $daysLeft . 'z';
        } elseif (!$isActive) {
            $insigna = '!';
        }
        return [[
            'cheie' => 'activare',
            'eticheta' => $isActive ? 'Cotizația mea' : 'Activează contul',
            'icon' => 'activare',
            'insigna' => $insigna,
            'render' => static function (PDO $db, array $user) use ($state, $settings): string {
                return View::fragment('site/membri/account-activation', [
                    'accountState' => $state,
                    'activationConfig' => self::activationViewConfig($settings),
                    'activationHistory' => self::accountActivationHistory($db, (int) ($user['id'] ?? 0)),
                ]);
            },
        ]];
    }

    /**
     * Agățarea `cont.antet`: categoria și starea contului de membru, lângă comenzi și puncte (antetul contului din vertij).
     *
     * @return list<array{eticheta: string, valoare: string}>
     */
    public static function antetCont(PDO $db, array $user): array
    {
        $user = self::contMembru($db, $user) ?? $user;
        $state = ProfessionAccounts::accountState($db, $user, Settings::all($db));
        $isActive = (bool) ($state['is_active'] ?? false);
        $daysLeft = (int) ($state['days_left'] ?? 0);
        if ($isActive) {
            $monthsLeft = $daysLeft > 0 ? (int) floor($daysLeft / 30) : 0;
            $statusValue = $monthsLeft >= 1
                ? 'Activ (' . $monthsLeft . ($monthsLeft === 1 ? ' lună' : ' luni') . ')'
                : 'Activ (' . $daysLeft . ($daysLeft === 1 ? ' zi' : ' zile') . ')';
        } else {
            $statusValue = 'Inactiv';
        }
        $statistici = [];
        $professionName = trim((string) ($state['profession_name'] ?? ''));
        if ($professionName !== '') {
            $statistici[] = ['eticheta' => 'Categorie', 'valoare' => $professionName];
        }
        $statistici[] = ['eticheta' => 'Status cont', 'valoare' => $statusValue];
        $memberCode = trim((string) ($state['member_code'] ?? ''));
        if ($memberCode !== '') {
            $statistici[] = ['eticheta' => 'Cod de membru', 'valoare' => $memberCode];
        }
        return $statistici;
    }

    /** `POST /contul-meu/activare`: plata cotizației pentru perioada aleasă (Stripe Checkout). */
    public function activare(): void
    {
        $db = $this->db();
        $userId = CustomerAuth::id();
        if (!$db instanceof PDO || $userId === null || $userId <= 0) {
            Flash::set('error', 'Trebuie să fii autentificat pentru a activa contul.');
            header('Location: /login');
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Încearcă din nou.');
            header('Location: /contul-meu?section=activare');
            return;
        }

        $settings = Settings::all($db);
        $user = self::contMembru($db, CustomerAuth::user($db));
        $state = ProfessionAccounts::accountState($db, $user, $settings);

        if (!$state['is_approved']) {
            Flash::set('error', 'Contul tău nu este încă aprobat de un administrator.');
            header('Location: /contul-meu?section=activare');
            return;
        }
        if ($state['is_active']) {
            Flash::set('success', 'Contul tău este deja activ.');
            header('Location: /contul-meu?section=activare');
            return;
        }
        $durationOptions = is_array($state['duration_options'] ?? null) ? $state['duration_options'] : [];
        if ($durationOptions === []) {
            Flash::set('error', 'Cotizația nu este configurată pentru acest tip de cont. Te rugăm să ne contactezi.');
            header('Location: /contul-meu?section=activare');
            return;
        }
        if (!\App\Support\Plugins::permiteInceput('membri')) {
            Flash::set('error', 'Plata cotizației nu este disponibilă momentan.');
            header('Location: /contul-meu?section=activare');
            return;
        }

        $requestedMonths = (int) ($_POST['activation_months'] ?? 0);
        $selectedOption = null;
        foreach ($durationOptions as $option) {
            if ((int) ($option['months'] ?? 0) === $requestedMonths) {
                $selectedOption = $option;
                break;
            }
        }
        if ($selectedOption === null) {
            Flash::set('error', 'Alege perioada pentru care vrei să achiți cotizația.');
            header('Location: /contul-meu?section=activare');
            return;
        }

        try {
            $checkoutUrl = self::createActivationCheckoutSession($db, $settings, $userId, $user ?? [], $state, $selectedOption);
        } catch (Throwable $exception) {
            // Mesajul Stripe (sau al excepției) doar în jurnal (revizuirea de securitate, 9 nov 2026).
            DonatiiController::logAccountIssue('activare_stripe', $exception->getMessage());
            Flash::set('error', 'Plata nu a putut fi inițiată acum. Încearcă din nou peste câteva minute sau scrie-ne.');
            header('Location: /contul-meu?section=activare');
            return;
        }

        header('Location: ' . $checkoutUrl);
    }

    private static function createActivationCheckoutSession(
        PDO $db,
        array $settings,
        int $userId,
        array $user,
        array $state,
        array $selectedOption
    ): string {
        $secretKey = trim((string) ($settings['stripe_secret_key'] ?? ''));
        if ($secretKey === '') {
            throw new RuntimeException('cheia Stripe nu este configurată.');
        }

        $currency = strtolower(trim((string) ($settings['stripe_currency'] ?? 'ron')));
        if (preg_match('/^[a-z]{3}$/', $currency) !== 1) {
            $currency = 'ron';
        }

        $price = (float) ($selectedOption['price'] ?? 0);
        $months = (int) ($selectedOption['months'] ?? 0);
        $amount = (int) max(0, round($price * 100));
        if ($amount <= 0 || $months <= 0) {
            throw new RuntimeException('suma de plată este invalidă.');
        }

        $profession = is_array($state['profession'] ?? null) ? $state['profession'] : [];
        $professionId = (int) ($profession['id'] ?? 0);
        $professionName = (string) ($state['profession_name'] ?? 'Cont profesional');
        $durationLabel = (string) ($selectedOption['label'] ?? ProfessionAccounts::durationLabel($months));

        $insert = $db->prepare(
            'INSERT INTO account_activations
                (user_id, profession_id, profession_name, amount, currency, status, months)
             VALUES (:user_id, :profession_id, :profession_name, :amount, :currency, :status, :months)'
        );
        $insert->execute([
            'user_id' => $userId,
            'profession_id' => $professionId > 0 ? $professionId : null,
            'profession_name' => $professionName,
            'amount' => round($price, 2),
            'currency' => $currency,
            'status' => 'pending',
            'months' => $months,
        ]);
        $activationId = (int) $db->lastInsertId();
        if ($activationId <= 0) {
            throw new RuntimeException('nu am putut înregistra plata.');
        }

        $appUrl = \App\Support\AppUrl::base();
        $successUrl = $appUrl . '/contul-meu/activare/retur?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $appUrl . '/contul-meu?section=activare&activare=anulata';

        $fields = [
            'payment_method_types[0]' => 'card',
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => 'activation-' . $activationId,
            'metadata[type]' => 'account_activation',
            'metadata[activation_id]' => (string) $activationId,
            'metadata[user_id]' => (string) $userId,
            'metadata[months]' => (string) $months,
            'payment_intent_data[description]' => 'Cotizație ' . $professionName . ' - ' . $durationLabel,
            'payment_intent_data[metadata][type]' => 'account_activation',
            'payment_intent_data[metadata][activation_id]' => (string) $activationId,
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][product_data][name]' => 'Cotizație ' . $professionName,
            'line_items[0][price_data][product_data][description]' => 'Valabilitate ' . $durationLabel,
            'line_items[0][price_data][unit_amount]' => (string) $amount,
            'line_items[0][quantity]' => '1',
            'locale' => 'auto',
        ];

        $customerEmail = trim((string) ($user['email'] ?? ''));
        if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $fields['customer_email'] = $customerEmail;
        }

        $session = StripeGateway::createCheckoutSession($secretKey, $fields);
        $sessionId = (string) ($session['id'] ?? '');
        $checkoutUrl = (string) ($session['url'] ?? '');
        if ($sessionId === '' || $checkoutUrl === '') {
            throw new RuntimeException('Stripe nu a returnat un link de plată valid.');
        }

        try {
            $update = $db->prepare(
                'UPDATE account_activations
                 SET stripe_session_id = :session_id, stripe_payment_intent_id = :payment_intent_id
                 WHERE id = :id'
            );
            $update->execute([
                'session_id' => $sessionId,
                'payment_intent_id' => (string) ($session['payment_intent'] ?? '') ?: null,
                'id' => $activationId,
            ]);
        } catch (Throwable) {
        }

        return $checkoutUrl;
    }

    /** `GET /contul-meu/activare/retur`: întoarcerea de la Stripe (mereu deschisă: plata se notează în orice stare). */
    public function activareRetur(): void
    {
        $db = $this->db();
        $sessionId = trim((string) ($_GET['session_id'] ?? ''));
        if (!$db instanceof PDO || $sessionId === '') {
            header('Location: /contul-meu?section=activare');
            return;
        }

        // Fiecare întoarcere e o cerere spre Stripe: un buget pe vizitator (revizuirea de securitate, 9 nov 2026); peste el,
        // webhook-ul notează oricum plata.
        if (!\App\Support\LimitaCereri::incearca($db, 'activare_retur', self::INTOARCERI_LA_15_MINUTE)) {
            Flash::set('success', 'Îți confirmăm plata imediat ce Stripe ne trimite confirmarea.');
            header('Location: /contul-meu?section=activare');
            return;
        }
        // Doar o sesiune Checkout (id-ul are forma cs_…), nu orice text dat în adresă.
        if (preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $sessionId) !== 1) {
            header('Location: /contul-meu?section=activare');
            return;
        }

        $settings = Settings::all($db);
        $secretKey = trim((string) ($settings['stripe_secret_key'] ?? ''));

        if ($secretKey !== '') {
            try {
                $session = StripeGateway::retrieveCheckoutSession($secretKey, $sessionId);
                $paid = (string) ($session['payment_status'] ?? '') === 'paid';
                // Doar sesiunile de activare (nu altă plată a site-ului cu un session_id dat în adresă).
                if ((string) ($session['metadata']['type'] ?? '') === 'account_activation') {
                    self::applyActivationSessionResult($db, $session, $paid, \App\Support\Plugins::vizibil('membri'));
                }
                if ($paid) {
                    Flash::set('success', 'Plata a fost confirmată. Contul tău este activ.');
                } else {
                    Flash::set('error', 'Plata nu a fost finalizată. Poți încerca din nou.');
                }
            } catch (Throwable) {
                // Webhook-ul rămâne sursa de adevăr dacă interogarea directă eșuează.
                Flash::set('success', 'Îți confirmăm plata imediat ce Stripe ne trimite confirmarea.');
            }
        }

        header('Location: /contul-meu?section=activare');
    }

    /**
     * Handler-ele Stripe ale activărilor (proprietarul „membri”: `metadata.type = account_activation`), pe dispecerul
     * nucleului: sesiunea de checkout plătită („completed” cu payment_status = paid, „async_payment_succeeded”) sau neplătită
     * („expired”, „async_payment_failed”). Plățile (payment_intent.*) cu aceeași metadată nu au handler: activările se
     * confirmă doar prin sesiune, ca în vertij.
     */
    public static function handlereStripe(): void
    {
        $sesiune = static fn (?bool $platita): callable => static function (PDO $db, array $obiect, array $eveniment, bool $vizibil) use ($platita): ?string {
            return self::applyActivationSessionResult($db, $obiect, $platita ?? ((string) ($obiect['payment_status'] ?? '') === 'paid'), $vizibil);
        };
        StripeEvenimente::handler('membri', 'checkout.session.completed', $sesiune(null));
        StripeEvenimente::handler('membri', 'checkout.session.async_payment_succeeded', $sesiune(true));
        StripeEvenimente::handler('membri', 'checkout.session.expired', $sesiune(false));
        StripeEvenimente::handler('membri', 'checkout.session.async_payment_failed', $sesiune(false));
        StripeEvenimente::handler('membri', 'charge.refunded', static fn (PDO $db, array $obiect): ?string => self::applyActivationRefund($db, $obiect));
    }

    /**
     * O cotizație rambursată din Stripe (`charge.refunded`; decizia proprietarului din 9 nov 2026): suma rambursată pe plata ei
     * (`membri/0065`) și un rând în jurnalul adminului; adminul o vede în Cereri conturi, în cerere și în lista utilizatorilor.
     * Contul NU se dezactivează singur (perioada plătită rămâne), decide administratorul. Idempotent (aceeași sumă = nimic).
     */
    private static function applyActivationRefund(PDO $db, array $charge): string
    {
        $paymentIntentId = trim((string) ($charge['payment_intent'] ?? ''));
        $refunded = max(0, (int) ($charge['amount_refunded'] ?? 0));
        if ($paymentIntentId === '' || $refunded <= 0) {
            return 'fara_rambursare';
        }
        try {
            $stmt = $db->prepare("SELECT id, user_id FROM account_activations WHERE stripe_payment_intent_id = :pi AND status = 'paid' ORDER BY id DESC LIMIT 1");
            $stmt->execute(['pi' => $paymentIntentId]);
            $activation = $stmt->fetch();
            if (!is_array($activation)) {
                return 'fara_activare';
            }
            $update = $db->prepare('UPDATE account_activations SET refunded_cents = :r, refunded_at = COALESCE(refunded_at, NOW())
                WHERE id = :id AND refunded_cents <> :r2');
            $update->execute(['r' => $refunded, 'r2' => $refunded, 'id' => (int) $activation['id']]);
            if ($update->rowCount() === 0) {
                return 'nimic_nou';
            }
        } catch (Throwable) {
            return 'eroare';
        }
        AdminActivityLog::log($db, 'account_activation_refunded', [
            'activation_id' => (int) $activation['id'],
            'user_id' => (int) $activation['user_id'],
            'refunded_cents' => $refunded,
        ]);
        return 'rambursata';
    }

    /** Marchează rezultatul plății de activare. Idempotent: webhook-ul și retur-ul pot ajunge amândouă. */
    private static function applyActivationSessionResult(PDO $db, array $session, bool $paid, bool $vizibil): string
    {
        $activationId = (int) ($session['metadata']['activation_id'] ?? 0);
        $sessionId = trim((string) ($session['id'] ?? ''));

        if ($activationId <= 0 && $sessionId === '') {
            return 'fara_activare';
        }

        try {
            if ($activationId > 0) {
                $stmt = $db->prepare('SELECT * FROM account_activations WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $activationId]);
            } else {
                $stmt = $db->prepare('SELECT * FROM account_activations WHERE stripe_session_id = :sid LIMIT 1');
                $stmt->execute(['sid' => $sessionId]);
            }
            $activation = $stmt->fetch() ?: null;
        } catch (Throwable) {
            return 'eroare';
        }

        if (!is_array($activation)) {
            return 'fara_activare';
        }
        if ((string) ($activation['status'] ?? '') === 'paid') {
            return 'deja_platita';
        }

        $activationId = (int) ($activation['id'] ?? 0);
        $userId = (int) ($activation['user_id'] ?? 0);
        $paymentIntentId = trim((string) ($session['payment_intent'] ?? ''));

        if (!$paid) {
            try {
                $fail = $db->prepare(
                    'UPDATE account_activations
                     SET status = :status, payment_error = :payment_error, stripe_session_id = :session_id
                     WHERE id = :id'
                );
                $fail->execute([
                    'status' => 'failed',
                    'payment_error' => 'Plata nu a fost finalizată sau sesiunea a expirat.',
                    'session_id' => $sessionId !== '' ? $sessionId : ($activation['stripe_session_id'] ?? null),
                    'id' => $activationId,
                ]);
            } catch (Throwable) {
            }
            return 'esuata';
        }

        // Plata se notează o singură dată, chiar cu webhook-ul și întoarcerea în aceeași clipă: doar cererea care trece
        // rândul din „pending/failed” în „paid” prelungește contul (altfel perioada s-ar adăuga de două ori).
        try {
            $ia = $db->prepare("UPDATE account_activations SET status = 'paid', paid_at = NOW() WHERE id = :id AND status <> 'paid'");
            $ia->execute(['id' => $activationId]);
            if ($ia->rowCount() !== 1) {
                return 'deja_platita';
            }
        } catch (Throwable) {
            return 'eroare';
        }

        $months = (int) ($activation['months'] ?? 0);
        if ($months <= 0) {
            $months = (int) ($session['metadata']['months'] ?? 0);
        }
        if ($months <= 0) {
            $months = 12;
        }
        $period = ProfessionAccounts::extendActivation($db, $userId, $months);

        $effectivePaymentIntentId = $paymentIntentId !== ''
            ? $paymentIntentId
            : trim((string) ($activation['stripe_payment_intent_id'] ?? ''));
        $receiptUrl = DonatiiController::stripeReceiptUrl(
            trim((string) (Settings::all($db)['stripe_secret_key'] ?? '')),
            $effectivePaymentIntentId
        );

        try {
            $update = $db->prepare(
                'UPDATE account_activations
                 SET status = :status, paid_at = NOW(), payment_error = NULL,
                     stripe_session_id = :session_id, stripe_payment_intent_id = :payment_intent_id,
                     period_start = :period_start, period_end = :period_end,
                     receipt_url = COALESCE(:receipt_url, receipt_url)
                 WHERE id = :id'
            );
            $update->execute([
                'status' => 'paid',
                'session_id' => $sessionId !== '' ? $sessionId : ($activation['stripe_session_id'] ?? null),
                'payment_intent_id' => $effectivePaymentIntentId !== '' ? $effectivePaymentIntentId : null,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'receipt_url' => $receiptUrl !== '' ? $receiptUrl : null,
                'id' => $activationId,
            ]);
        } catch (Throwable) {
        }

        if ($vizibil) {
            self::sendActivationConfirmationEmail($db, $userId, $period['end']);
        }
        return 'platita';
    }

    private static function sendActivationConfirmationEmail(PDO $db, int $userId, string $activeUntil): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare('SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch() ?: null;
        } catch (Throwable) {
            return;
        }

        $email = is_array($user) ? trim((string) ($user['email'] ?? '')) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
        $untilTimestamp = strtotime($activeUntil);
        $untilLabel = $untilTimestamp !== false ? date('d.m.Y', $untilTimestamp) : $activeUntil;

        $settings = Settings::all($db);
        $html = BrandedEmail::render(DonatiiController::emailBrandSettings($settings), [
            'title' => 'Contul tău este activ',
            'greeting' => 'Bine ai venit, ' . ($name !== '' ? $name : 'coleg') . '!',
            'intro' => 'Plata a fost confirmată, iar contul tău de membru este activ de acum.',
            'facts' => [
                ['label' => 'Valabil până la', 'value' => $untilLabel],
            ],
            'button' => ['label' => 'Intră în contul tău', 'url' => \App\Support\AppUrl::base() . '/contul-meu'],
            'note' => 'Ai acces la secțiunile dedicate membrilor. Îți vom aminti pe email înainte de expirare.',
            'recipient' => $email,
        ]);

        try {
            OrderMailer::sendCustom($email, 'Contul tău este activ', $html, $settings, $db, [
                'email_type' => 'account_activation_paid',
                'source' => 'customer_account',
                'trigger' => 'account_activation_paid',
            ]);
        } catch (Throwable) {
        }
    }

    // ================================================================== conținutul pentru membri

    /** Agățarea `continut.membru_activ`: un cont e „activ” doar dacă e aprobat de admin ȘI are cotizația plătită la zi. */
    public static function membruActiv(?PDO $db, array $user): bool
    {
        return ProfessionAccounts::hasActiveAccount($db, self::contMembru($db, $user));
    }

    /** Agățarea `continut.nota_titlu`: titlul paginii „acces restricționat”. */
    public static function titluNota(?PDO $db): string
    {
        $settings = $db instanceof PDO ? Settings::all($db) : [];
        return trim((string) ($settings['restricted_page_title'] ?? '')) ?: 'Conținut dedicat profesioniștilor';
    }

    /** Agățarea `continut.nota`: explicația pe stări a vertij (oaspete, confirmare, verificare, respins, neactivat, expirat). */
    public static function nota(?PDO $db, ?array $user, string $contentTitle): string
    {
        $settings = $db instanceof PDO ? Settings::all($db) : [];
        $user = self::contMembru($db, $user);
        $state = ProfessionAccounts::accountState($db, $user, $settings);
        $isLoggedIn = is_array($user);

        $title = trim((string) ($settings['restricted_page_title'] ?? '')) ?: 'Conținut dedicat profesioniștilor';
        $actions = [];

        if (!$isLoggedIn) {
            $message = (string) ($settings['restricted_guest_text'] ?? '');
            $actions[] = ['href' => '/register', 'label' => 'Creează cont profesional', 'primary' => true];
            $actions[] = ['href' => '/login', 'label' => 'Am deja cont', 'primary' => false];
        } else {
            $status = (string) $state['verification_status'];
            if ($status === ProfessionAccounts::STATUS_AWAITING_EMAIL) {
                $message = (string) ($settings['restricted_awaiting_email_text'] ?? '');
            } elseif ($status === ProfessionAccounts::STATUS_PENDING) {
                $message = (string) ($settings['restricted_pending_text'] ?? '');
            } elseif ($status === ProfessionAccounts::STATUS_REJECTED) {
                $message = (string) ($settings['restricted_rejected_text'] ?? '');
                $actions[] = ['href' => '/contact', 'label' => 'Contactează-ne', 'primary' => true];
            } elseif ($status === ProfessionAccounts::STATUS_APPROVED) {
                $hasExpired = $state['active_until'] !== null;
                $message = $hasExpired
                    ? (string) ($settings['restricted_expired_text'] ?? '')
                    : (string) ($settings['restricted_inactive_text'] ?? '');
                $actions[] = [
                    'href' => '/contul-meu?section=activare',
                    'label' => $hasExpired ? 'Reactivează contul' : 'Activează contul',
                    'primary' => true,
                ];
            } else {
                $message = (string) ($settings['restricted_guest_text'] ?? '');
                $actions[] = ['href' => '/register', 'label' => 'Trimite o cerere de cont', 'primary' => true];
            }
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $buttonsHtml = '';
        foreach ($actions as $action) {
            $isPrimary = (bool) ($action['primary'] ?? false);
            $buttonsHtml .= '<a class="restricted-action' . ($isPrimary ? ' is-primary' : '') . '" href="'
                . $escape((string) $action['href']) . '">' . $escape((string) $action['label']) . '</a>';
        }

        $contextHtml = '';
        if (trim($contentTitle) !== '') {
            $contextHtml = '<p class="restricted-context">Pagină solicitată: <strong>'
                . $escape(trim($contentTitle)) . '</strong></p>';
        }

        $html = '<section class="restricted-notice">'
            . '<div class="restricted-card">'
            . '<span class="restricted-badge">Acces restricționat</span>'
            . '<h1>' . $escape($title) . '</h1>'
            . '<p class="restricted-message">' . $escape($message) . '</p>'
            . $contextHtml
            . ($buttonsHtml !== '' ? '<div class="restricted-actions">' . $buttonsHtml . '</div>' : '')
            . '</div>'
            . '</section>';

        $css = '.restricted-notice{display:flex;justify-content:center;padding:56px 18px;}'
            . '.restricted-card{max-width:620px;width:100%;background:#fff;border:1px solid #efe3f2;border-radius:20px;'
            . 'box-shadow:0 18px 44px rgba(111,43,152,.09);padding:36px 34px;text-align:center;}'
            . '.restricted-badge{display:inline-block;padding:6px 14px;border-radius:999px;background:#f4ecf7;'
            . 'color:#6f2b98;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;}'
            . '.restricted-card h1{margin:18px 0 12px;font-size:27px;line-height:1.25;color:#1f2937;}'
            . '.restricted-message{margin:0;color:#4b5563;font-size:15.5px;line-height:1.65;}'
            . '.restricted-context{margin:16px 0 0;color:#8b8b9c;font-size:13px;}'
            . '.restricted-actions{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:26px;}'
            . '.restricted-action{display:inline-block;padding:12px 22px;border-radius:12px;border:1.5px solid #d9c2e6;'
            . 'color:#6f2b98;background:#fff;font-weight:700;font-size:14.5px;text-decoration:none;}'
            . '.restricted-action.is-primary{background:linear-gradient(135deg,#ea4968,#6f2b98);border-color:transparent;color:#fff;}'
            . '@media(max-width:560px){.restricted-card{padding:28px 22px;}.restricted-action{width:100%;}}';

        return '<style>' . $css . '</style>' . $html;
    }

    /** Agățările `editor.pagina.campuri` / `editor.articol.campuri`: bifa „Doar cont activ” (cu semnul că formularul o are). */
    public static function bifaEditorPagina(array $page): string
    {
        $requiresActiveAccount = (int) ($page['requires_active_account'] ?? 0) === 1;
        return '<input type="hidden" name="requires_active_account_present" value="1">'
            . '<label style="display:flex;align-items:center;gap:8px;white-space:nowrap;"'
            . ' title="Pagina va fi vizibilă doar utilizatorilor cu cont aprobat și activat.">'
            . '<input type="checkbox" name="requires_active_account" value="1"' . ($requiresActiveAccount ? ' checked' : '') . '>'
            . ' Doar cont activ</label>';
    }

    public static function bifaEditorArticol(array $post): string
    {
        $requiresActiveAccount = (int) ($post['requires_active_account'] ?? 0) === 1;
        return '<input type="hidden" name="requires_active_account_present" value="1">'
            . '<div class="field" style="grid-column:1/-1;">'
            . '<label style="display:flex;align-items:flex-start;gap:8px;">'
            . '<input type="checkbox" name="requires_active_account" value="1" style="margin-top:3px;"' . ($requiresActiveAccount ? ' checked' : '') . '>'
            . '<span>Doar pentru conturi active<br><small style="color:#64748b;">'
            . 'Vizitatorii fără cont activat vor vedea pagina care le explică ce au de făcut.</small></span></label></div>';
    }

    /**
     * Agățarea `utilizator.sters` (Admin → Utilizatori și „Șterge contul”, în tranzacția ștergerii): cererile de membru, cu
     * documentele lor de pe disc (acte de identitate, diplome), istoricul activărilor (ca în vertij, care o făcea doar din
     * admin) și poza de profil (în plus față de vertij: fișierul și rândul din Galerie).
     *
     * @param list<int> $ids
     */
    public static function utilizatorSters(PDO $db, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Poza de profil (date personale): fișierul și rândul ei din Galerie pleacă odată cu contul.
        try {
            $stmt = $db->prepare('SELECT avatar_url FROM users WHERE id IN (' . $placeholders . ') AND avatar_url IS NOT NULL');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $avatar) {
                self::removeReplacedAvatar($db, (string) $avatar, '');
            }
        } catch (Throwable) {
            // Fără coloana avatar_url (bază nemigrată): nimic de șters.
        }

        try {
            $stmt = $db->prepare(
                'SELECT d.stored_name
                 FROM account_verification_documents d
                 INNER JOIN account_verifications v ON v.id = d.verification_id
                 WHERE v.user_id IN (' . $placeholders . ')'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
                $path = ProfessionAccounts::documentPath((string) $storedName);
                if ($path !== '' && is_file($path)) {
                    @unlink($path);
                }
            }
        } catch (Throwable) {
            return;
        }

        try {
            $db->prepare(
                'DELETE d FROM account_verification_documents d
                 INNER JOIN account_verifications v ON v.id = d.verification_id
                 WHERE v.user_id IN (' . $placeholders . ')'
            )->execute($ids);
            $db->prepare('DELETE FROM account_verifications WHERE user_id IN (' . $placeholders . ')')
                ->execute($ids);
            $db->prepare('DELETE FROM account_activations WHERE user_id IN (' . $placeholders . ')')
                ->execute($ids);
        } catch (Throwable) {
        }
    }

    /**
     * Agățarea `admin.utilizatori.coloane` (Admin → Utilizatori): ultima plată a cotizației, cu cele cinci celule ale vertij
     * (7d7d8cc): suma, perioada achitată, starea plății, perioada, plătit la. O singură interogare pentru rândurile listei.
     *
     * @param array<int, array<string, mixed>> $users
     * @return array{cap: string, celule: array<int, string>, numar: int}
     */
    public static function coloaneUtilizatori(array $users): array
    {
        $db = self::conexiune();
        $ids = [];
        foreach ($users as $user) {
            $id = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $randuri = [];
        if ($db instanceof PDO && $ids !== []) {
            $randuri = self::ultimeleActivari($db, $ids);
        }
        $celule = [];
        foreach ($ids as $id) {
            $celule[$id] = View::fragment('admin/membri/activation-cells', ['activationRow' => $randuri[$id] ?? []]);
        }
        return ['cap' => View::fragment('admin/membri/activation-head') . self::stilTabelLat(), 'celule' => $celule, 'numar' => 5];
    }

    /**
     * Ultima plată de activare a fiecărui utilizator dat (plățile încasate întâi; altfel ultima încercare), cu aliasurile
     * `activation_*` ale lui ProfessionAccounts::latestActivationColumns().
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function ultimeleActivari(PDO $db, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $db->prepare('SELECT u.id AS user_id, ' . ProfessionAccounts::latestActivationColumns() . ' FROM users u'
                . ProfessionAccounts::latestActivationJoin('u.id') . ' WHERE u.id IN (' . $placeholders . ')');
            $stmt->execute($ids);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rand) {
                $out[(int) $rand['user_id']] = $rand;
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Derularea tabelelor late doar pe listele cu coloanele cotizației (vertij b0a81e1, `.admin-table-scroll`). */
    public static function stilTabelLat(): string
    {
        return '<style>.admin-table-scroll{overflow-x:auto;overflow-y:hidden;}'
            . '.admin-table-scroll>.table{min-width:100%;width:max-content;}'
            . '.admin-table-scroll>.table th:last-child,.admin-table-scroll>.table td:last-child{position:sticky;right:0;background:#fff;'
            . 'box-shadow:-10px 0 12px -10px rgba(15,23,42,.3);}'
            . '.admin-table-scroll>.users-table tr.is-selected td:last-child{background:#f0fdfa;}'
            . '.admin-table-scroll>.users-table tr.is-marked td:last-child{background:#f8fafc;}</style>';
    }

    // ================================================================== admin

    /** `GET /admin/accounts/professions`: tipurile de cont (categoriile de membru) și câmpurile lor. */
    public function profesii(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }

        $professions = ProfessionAccounts::professions($db, false);
        foreach ($professions as $index => $profession) {
            $professions[$index]['fields'] = ProfessionAccounts::professionFields(
                $db,
                (int) ($profession['id'] ?? 0),
                false
            );
        }

        $editingId = (int) ($_GET['edit'] ?? 0);
        $editingProfession = $editingId > 0 ? ProfessionAccounts::profession($db, $editingId) : null;
        if (is_array($editingProfession)) {
            $editingProfession['fields'] = ProfessionAccounts::professionFields($db, $editingId, false);
        }

        View::render('admin/account-professions', [
            'title' => 'Tipuri de cont',
            'professions' => $professions,
            'editingProfession' => $editingProfession,
            'fieldTypes' => ProfessionAccounts::FIELD_TYPES,
        ], 'admin/layout');
    }

    /** `POST /admin/accounts/professions`: un tip de cont nou, editat sau șters (cu câmpurile lui). */
    public function profesiiSalveaza(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/accounts/professions');
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/accounts/professions');
            return;
        }

        $action = trim((string) ($_POST['action'] ?? 'save'));
        $professionId = (int) ($_POST['profession_id'] ?? 0);

        if ($action === 'delete' && $professionId > 0) {
            try {
                $db->prepare('DELETE FROM profession_fields WHERE profession_id = :pid')
                    ->execute(['pid' => $professionId]);
                $db->prepare('DELETE FROM professions WHERE id = :id')->execute(['id' => $professionId]);
                Flash::set('success', 'Tipul de cont a fost șters.');
            } catch (Throwable) {
                Flash::set('error', 'Tipul de cont nu a putut fi șters.');
            }
            header('Location: /admin/accounts/professions');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Denumirea tipului de cont este obligatorie.');
            header('Location: /admin/accounts/professions');
            return;
        }

        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $slug = self::slugify($slugInput !== '' ? $slugInput : $name);
        if ($slug === '') {
            $slug = 'tip-cont-' . time();
        }

        $price = (float) str_replace(',', '.', (string) ($_POST['activation_price'] ?? '0'));
        if ($price < 0) {
            $price = 0.0;
        }
        $months = (int) ($_POST['activation_months'] ?? 12);
        if ($months <= 0) {
            $months = 12;
        }

        $payload = [
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            'activation_price' => round($price, 2),
            'activation_months' => $months,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];

        try {
            if ($professionId > 0) {
                $stmt = $db->prepare(
                    'UPDATE professions
                     SET name = :name, slug = :slug, description = :description,
                         activation_price = :activation_price, activation_months = :activation_months,
                         is_active = :is_active, sort_order = :sort_order
                     WHERE id = :id'
                );
                $stmt->execute($payload + ['id' => $professionId]);
                Flash::set('success', 'Tipul de cont a fost actualizat.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO professions (name, slug, description, activation_price, activation_months, is_active, sort_order)
                     VALUES (:name, :slug, :description, :activation_price, :activation_months, :is_active, :sort_order)'
                );
                $stmt->execute($payload);
                $professionId = (int) $db->lastInsertId();
                Flash::set('success', 'Tipul de cont a fost adăugat.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva. Verifică dacă slug-ul este deja folosit.');
            header('Location: /admin/accounts/professions');
            return;
        }

        header('Location: /admin/accounts/professions?edit=' . $professionId);
    }

    /** `POST /admin/accounts/professions/fields`: un câmp al unui tip de cont, nou, editat sau șters. */
    public function campuriSalveaza(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/accounts/professions');
            return;
        }

        $professionId = (int) ($_POST['profession_id'] ?? 0);
        if ($professionId <= 0 || !is_array(ProfessionAccounts::profession($db, $professionId))) {
            Flash::set('error', 'Tipul de cont nu a fost găsit.');
            header('Location: /admin/accounts/professions');
            return;
        }

        $redirect = '/admin/accounts/professions?edit=' . $professionId;
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: ' . $redirect);
            return;
        }
        $action = trim((string) ($_POST['action'] ?? 'save'));
        $fieldId = (int) ($_POST['field_id'] ?? 0);

        if ($action === 'delete' && $fieldId > 0) {
            try {
                $db->prepare('DELETE FROM profession_fields WHERE id = :id AND profession_id = :pid')
                    ->execute(['id' => $fieldId, 'pid' => $professionId]);
                Flash::set('success', 'Câmpul a fost șters.');
            } catch (Throwable) {
                Flash::set('error', 'Câmpul nu a putut fi șters.');
            }
            header('Location: ' . $redirect);
            return;
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label === '') {
            Flash::set('error', 'Eticheta câmpului este obligatorie.');
            header('Location: ' . $redirect);
            return;
        }

        $fieldKey = ProfessionAccounts::normalizeFieldKey((string) ($_POST['field_key'] ?? ''), $label);
        $fieldType = ProfessionAccounts::normalizeFieldType((string) ($_POST['field_type'] ?? 'text'));
        $payload = [
            'profession_id' => $professionId,
            'field_key' => $fieldKey,
            'label' => $label,
            'field_type' => $fieldType,
            'options_json' => $fieldType === 'select'
                ? (ProfessionAccounts::encodeOptions((string) ($_POST['options'] ?? '')) ?: null)
                : null,
            'help_text' => trim((string) ($_POST['help_text'] ?? '')) ?: null,
            'is_required' => isset($_POST['is_required']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];

        try {
            if ($fieldId > 0) {
                $stmt = $db->prepare(
                    'UPDATE profession_fields
                     SET field_key = :field_key, label = :label, field_type = :field_type,
                         options_json = :options_json, help_text = :help_text, is_required = :is_required,
                         is_active = :is_active, sort_order = :sort_order
                     WHERE id = :id AND profession_id = :profession_id'
                );
                $stmt->execute($payload + ['id' => $fieldId]);
                Flash::set('success', 'Câmpul a fost actualizat.');
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO profession_fields
                        (profession_id, field_key, label, field_type, options_json, help_text, is_required, is_active, sort_order)
                     VALUES
                        (:profession_id, :field_key, :label, :field_type, :options_json, :help_text, :is_required, :is_active, :sort_order)'
                );
                $stmt->execute($payload);
                Flash::set('success', 'Câmpul a fost adăugat.');
            }
        } catch (Throwable) {
            Flash::set('error', 'Nu am putut salva câmpul. Cheia „' . $fieldKey . '” există deja pentru acest tip de cont.');
        }

        header('Location: ' . $redirect);
    }

    /** `GET /admin/accounts/verifications`: cererile, pe stări, cu ultima plată a cotizației (vertij 7d7d8cc). */
    public function cereri(): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }

        $statusFilter = trim((string) ($_GET['status'] ?? ProfessionAccounts::STATUS_PENDING));
        $allowedStatuses = [
            ProfessionAccounts::STATUS_PENDING,
            ProfessionAccounts::STATUS_APPROVED,
            ProfessionAccounts::STATUS_REJECTED,
            ProfessionAccounts::STATUS_AWAITING_EMAIL,
        ];
        if ($statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
            $statusFilter = ProfessionAccounts::STATUS_PENDING;
        }

        $search = trim((string) ($_GET['q'] ?? ''));
        $conditions = [];
        $params = [];
        if ($statusFilter !== 'all') {
            $conditions[] = 'v.status = :status';
            $params['status'] = $statusFilter;
        }
        if ($search !== '') {
            $conditions[] = '(u.email LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR v.profession_name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $rows = [];
        try {
            $stmt = $db->prepare(
                'SELECT v.*, u.first_name, u.last_name, u.email, u.account_active_until,
                        (SELECT COUNT(*) FROM account_verification_documents d WHERE d.verification_id = v.id) AS documents_count,
                        ' . ProfessionAccounts::latestActivationColumns() . '
                 FROM account_verifications v
                 LEFT JOIN users u ON u.id = v.user_id'
                . ProfessionAccounts::latestActivationJoin('v.user_id')
                . $where .
                ' ORDER BY v.id DESC
                 LIMIT 300'
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            $rows = [];
        }

        View::render('admin/account-verifications', [
            'title' => 'Cereri conturi profesionale',
            'verifications' => is_array($rows) ? $rows : [],
            'statusFilter' => $statusFilter,
            'search' => $search,
            'statusCounts' => self::accountVerificationCounts($db),
        ], 'admin/layout');
    }

    /** @return array<string, int> */
    private static function accountVerificationCounts(PDO $db): array
    {
        $counts = [
            ProfessionAccounts::STATUS_AWAITING_EMAIL => 0,
            ProfessionAccounts::STATUS_PENDING => 0,
            ProfessionAccounts::STATUS_APPROVED => 0,
            ProfessionAccounts::STATUS_REJECTED => 0,
        ];

        try {
            $rows = $db->query('SELECT status, COUNT(*) AS total FROM account_verifications GROUP BY status')?->fetchAll();
            foreach (is_array($rows) ? $rows : [] as $row) {
                $status = (string) ($row['status'] ?? '');
                if (array_key_exists($status, $counts)) {
                    $counts[$status] = (int) ($row['total'] ?? 0);
                }
            }
        } catch (Throwable) {
        }

        return $counts;
    }

    /** `GET /admin/accounts/verifications/{id}`: o cerere, cu răspunsurile, documentele și plățile cotizației. */
    public function cerere(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin');
            return;
        }

        $verificationId = (int) ($params['id'] ?? 0);
        $verification = ProfessionAccounts::verification($db, $verificationId);
        if (!is_array($verification)) {
            Flash::set('error', 'Cererea nu a fost găsită.');
            header('Location: /admin/accounts/verifications');
            return;
        }

        $activations = [];
        try {
            $stmt = $db->prepare(
                'SELECT * FROM account_activations WHERE user_id = :uid ORDER BY id DESC LIMIT 25'
            );
            $stmt->execute(['uid' => (int) ($verification['user_id'] ?? 0)]);
            $activations = $stmt->fetchAll() ?: [];
        } catch (Throwable) {
        }

        View::render('admin/account-verification-details', [
            'title' => 'Cerere cont profesional',
            'verification' => $verification,
            'answers' => ProfessionAccounts::decodeAnswers((string) ($verification['answers_json'] ?? '')),
            'documents' => ProfessionAccounts::documents($db, $verificationId),
            'activations' => is_array($activations) ? $activations : [],
            'poatePrelungi' => self::poatePrelungi($db, $verification),
        ], 'admin/layout');
    }

    /**
     * Prelungirea fără plată se poate doar pe cererea aprobată care e ultima cerere a contului (contul e aprobat pe ea);
     * una veche, respinsă sau încă în verificare nu activează nimic.
     */
    private static function poatePrelungi(PDO $db, array $verification): bool
    {
        $userId = (int) ($verification['user_id'] ?? 0);
        if ($userId <= 0 || (string) ($verification['status'] ?? '') !== ProfessionAccounts::STATUS_APPROVED) {
            return false;
        }
        $latest = ProfessionAccounts::latestVerification($db, $userId);
        return is_array($latest) && (int) ($latest['id'] ?? 0) === (int) ($verification['id'] ?? 0);
    }

    /**
     * `POST /admin/accounts/verifications/{id}/extend`: luni de abonament acordate din admin, fără plată (decizia
     * proprietarului, 12 noiembrie 2026). Contul se prelungește ca după o plată (ProfessionAccounts::extendActivation: de la
     * expirare dacă mai are zile, altfel de azi), iar în istoric rămâne un rând „granted” cu suma 0 și perioada; motivul
     * merge în jurnalul adminului. Fără email și fără Stripe.
     */
    public function prelungeste(array $params): void
    {
        if (!$this->guard()) {
            return;
        }
        $db = $this->db();
        $verificationId = (int) ($params['id'] ?? 0);
        $inapoi = '/admin/accounts/verifications/' . $verificationId;
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/accounts/verifications');
            return;
        }
        $verification = ProfessionAccounts::verification($db, $verificationId);
        if (!is_array($verification)) {
            Flash::set('error', 'Cererea nu a fost găsită.');
            header('Location: /admin/accounts/verifications');
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: ' . $inapoi);
            return;
        }
        if (!self::poatePrelungi($db, $verification)) {
            Flash::set('error', 'Contul se poate prelungi doar pe cererea aprobată, cea mai nouă a contului.');
            header('Location: ' . $inapoi);
            return;
        }
        $luni = (int) trim((string) ($_POST['months'] ?? ''));
        if ($luni < 1 || $luni > 60) {
            Flash::set('error', 'Numărul de luni trebuie să fie între 1 și 60.');
            header('Location: ' . $inapoi);
            return;
        }
        $motiv = mb_substr(trim((string) ($_POST['motiv'] ?? '')), 0, 300);
        $userId = (int) $verification['user_id'];

        $perioada = ProfessionAccounts::extendActivation($db, $userId, $luni);
        try {
            $db->prepare(
                "INSERT INTO account_activations (user_id, profession_id, profession_name, amount, currency, status, months,
                    period_start, period_end, paid_at)
                 VALUES (:uid, :pid, :pname, 0, 'ron', 'granted', :luni, :start, :end, NOW())"
            )->execute([
                'uid' => $userId,
                'pid' => isset($verification['profession_id']) ? (int) $verification['profession_id'] : null,
                'pname' => ($verification['profession_name'] ?? null) !== null ? (string) $verification['profession_name'] : null,
                'luni' => $luni,
                'start' => $perioada['start'],
                'end' => $perioada['end'],
            ]);
        } catch (Throwable) {
            // Contul e deja prelungit; rândul din istoric e doar informativ.
        }
        AdminActivityLog::log($db, 'account_extended_free', [
            'verification_id' => $verificationId, 'user_id' => $userId, 'months' => $luni,
            'pana_la' => $perioada['end'], 'motiv' => $motiv,
        ]);
        Flash::set('success', 'Contul a fost prelungit cu ' . ProfessionAccounts::durationLabel($luni) . ', până la '
            . date('d.m.Y', strtotime($perioada['end']) ?: time()) . '.');
        header('Location: ' . $inapoi);
    }

    /** `POST /admin/accounts/verifications/{id}/decision`: aprobarea sau respingerea, cu emailul deciziei. */
    public function decizie(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            Flash::set('error', 'Conexiunea DB nu este disponibilă.');
            header('Location: /admin/accounts/verifications');
            return;
        }

        $verificationId = (int) ($params['id'] ?? 0);
        $verification = ProfessionAccounts::verification($db, $verificationId);
        if (!is_array($verification)) {
            Flash::set('error', 'Cererea nu a fost găsită.');
            header('Location: /admin/accounts/verifications');
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/accounts/verifications/' . $verificationId);
            return;
        }

        $decision = trim((string) ($_POST['decision'] ?? ''));
        if (!in_array($decision, [ProfessionAccounts::STATUS_APPROVED, ProfessionAccounts::STATUS_REJECTED], true)) {
            Flash::set('error', 'Decizie invalidă.');
            header('Location: /admin/accounts/verifications/' . $verificationId);
            return;
        }

        $note = trim((string) ($_POST['review_note'] ?? ''));
        $userId = (int) ($verification['user_id'] ?? 0);

        // Revizuirea de securitate (9 nov 2026): doar o cerere „în verificare” (adresa confirmată) care e ultima cerere a
        // contului primește o decizie; una veche (înlocuită de o cerere nouă), una neconfirmată sau una deja decisă nu mai
        // schimbă starea contului.
        $latest = ProfessionAccounts::latestVerification($db, $userId);
        if ((string) ($verification['status'] ?? '') !== ProfessionAccounts::STATUS_PENDING
            || !is_array($latest) || (int) ($latest['id'] ?? 0) !== $verificationId) {
            Flash::set('error', 'Cererea nu mai așteaptă o decizie (e deja decisă, neconfirmată sau contul are o cerere mai nouă).');
            header('Location: /admin/accounts/verifications/' . $verificationId);
            return;
        }

        try {
            $stmt = $db->prepare(
                'UPDATE account_verifications
                 SET status = :status, review_note = :review_note, reviewed_by = :reviewed_by, reviewed_at = NOW()
                 WHERE id = :id AND status = :pending'
            );
            $stmt->execute([
                'status' => $decision,
                'review_note' => $note !== '' ? $note : null,
                'reviewed_by' => Auth::id(),
                'id' => $verificationId,
                'pending' => ProfessionAccounts::STATUS_PENDING,
            ]);
            if ($stmt->rowCount() !== 1) {
                // Decisă chiar acum de altă cerere.
                Flash::set('error', 'Cererea nu mai așteaptă o decizie.');
                header('Location: /admin/accounts/verifications/' . $verificationId);
                return;
            }

            $updateUser = $db->prepare('UPDATE users SET account_status = :status WHERE id = :id');
            $updateUser->execute(['status' => $decision, 'id' => $userId]);
        } catch (Throwable) {
            Flash::set('error', 'Decizia nu a putut fi salvată.');
            header('Location: /admin/accounts/verifications/' . $verificationId);
            return;
        }

        AdminActivityLog::log(
            $db,
            $decision === ProfessionAccounts::STATUS_APPROVED
                ? 'account_verification_approved'
                : 'account_verification_rejected',
            ['verification_id' => $verificationId, 'user_id' => $userId]
        );

        self::sendVerificationDecisionEmail($db, $verification, $decision, $note);

        Flash::set(
            'success',
            $decision === ProfessionAccounts::STATUS_APPROVED
                ? 'Cererea a fost aprobată. Utilizatorul poate acum plăti activarea.'
                : 'Cererea a fost respinsă.'
        );
        header('Location: /admin/accounts/verifications/' . $verificationId);
    }

    private static function sendVerificationDecisionEmail(PDO $db, array $verification, string $decision, string $note): void
    {
        $email = trim((string) ($verification['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $settings = DonatiiController::emailBrandSettings(Settings::all($db));
        $name = trim(((string) ($verification['first_name'] ?? '')) . ' ' . ((string) ($verification['last_name'] ?? '')));
        $displayName = $name !== '' ? $name : 'coleg';
        $professionName = trim((string) ($verification['profession_name'] ?? ''));

        if ($decision === ProfessionAccounts::STATUS_APPROVED) {
            $subject = 'Contul tău a fost aprobat';
            $facts = [];
            if ($professionName !== '') {
                $facts[] = ['label' => 'Categorie', 'value' => $professionName];
            }
            $memberCode = trim((string) ($verification['member_code'] ?? ''));
            if ($memberCode !== '') {
                $facts[] = ['label' => 'Cod de membru', 'value' => $memberCode];
            }

            $html = BrandedEmail::render($settings, [
                'title' => 'Cererea ta a fost aprobată',
                'greeting' => 'Bună, ' . $displayName . '!',
                'intro' => 'Am verificat documentele trimise și totul este în regulă. A mai rămas un singur pas'
                    . ' până devii membru cu drepturi depline.',
                'facts' => $facts,
                'steps' => [
                    [
                        'title' => 'Intră în contul tău',
                        'text' => 'Deschide secțiunea de cotizație din contul tău.',
                    ],
                    [
                        'title' => 'Alege perioada și plătește',
                        'text' => 'Poți achita cotizația pentru 1, 3, 6 sau 12 luni, cu plata securizată prin card.',
                    ],
                    [
                        'title' => 'Ai acces imediat',
                        'text' => 'Contul devine activ pe loc, iar conținutul dedicat membrilor ți se deschide.',
                    ],
                ],
                'button' => ['label' => 'Achită cotizația', 'url' => \App\Support\AppUrl::base() . '/contul-meu?section=activare'],
                'recipient' => $email,
            ]);
        } else {
            $subject = 'Despre cererea ta de înscriere';
            $html = BrandedEmail::render($settings, [
                'title' => 'Cererea ta de înscriere',
                'greeting' => 'Bună, ' . $displayName . ',',
                'intro' => 'Îți mulțumim pentru interesul acordat. Din păcate, cererea ta nu a putut fi aprobată'
                    . ' în forma actuală.',
                'facts' => $note !== '' ? [['label' => 'Motiv', 'value' => $note]] : [],
                'note' => 'Dacă vrei să reiei procesul cu documente actualizate sau ai nelămuriri,'
                    . ' scrie-ne — te ajutăm cu drag.',
                'recipient' => $email,
            ]);
        }

        try {
            OrderMailer::sendCustom($email, $subject, $html, $settings, $db, [
                'email_type' => 'account_verification_decision',
                'source' => 'admin_panel',
                'trigger' => 'account_verification_' . $decision,
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * `GET /admin/accounts/documents/{id}`: documentele stau în afara document root-ului, deci se servesc doar prin
     * această rută, după verificarea sesiunii de admin.
     */
    public function document(array $params): void
    {
        if (!$this->guard()) {
            return;
        }

        $db = $this->db();
        if (!$db instanceof PDO) {
            http_response_code(503);
            echo 'Conexiunea DB nu este disponibilă.';
            return;
        }

        $documentId = (int) ($params['id'] ?? 0);
        $document = null;
        try {
            $stmt = $db->prepare('SELECT * FROM account_verification_documents WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $documentId]);
            $document = $stmt->fetch() ?: null;
        } catch (Throwable) {
        }

        if (!is_array($document)) {
            http_response_code(404);
            echo 'Documentul nu a fost găsit.';
            return;
        }

        $path = ProfessionAccounts::documentPath((string) ($document['stored_name'] ?? ''));
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            echo 'Fișierul nu mai există pe server.';
            return;
        }

        $mime = trim((string) ($document['mime_type'] ?? ''));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $originalName = (string) ($document['original_name'] ?? 'document');
        // Doar PDF-urile și imaginile se deschid inline; restul se descarcă.
        $inline = $mime === 'application/pdf' || str_starts_with($mime, 'image/');
        $disposition = $inline ? 'inline' : 'attachment';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($originalName) . '"');
        header('X-Content-Type-Options: nosniff');
        // Un document deschis în browser nu rulează nimic și nu încarcă nimic de altundeva.
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; plugin-types application/pdf; sandbox");
        header('Cache-Control: private, no-store');
        readfile($path);
    }

    /** `GET /admin/accounts/settings`. */
    public function setari(): void
    {
        if (!$this->guard()) {
            return;
        }

        View::render('admin/account-settings', [
            'title' => 'Setări conturi profesionale',
            'settings' => Settings::all($this->db()),
        ], 'admin/layout');
    }

    /** `POST /admin/accounts/settings`. */
    public function setariSalveaza(): void
    {
        if (!$this->guard()) {
            return;
        }
        if (!AdminCsrf::valid()) {
            Flash::set('error', 'Formularul a expirat. Reîncarcă pagina și încearcă din nou.');
            header('Location: /admin/accounts/settings');
            return;
        }

        $maxMb = (float) str_replace(',', '.', (string) ($_POST['account_upload_max_mb'] ?? '8'));
        if ($maxMb <= 0) {
            $maxMb = 8.0;
        }
        $maxFiles = (int) ($_POST['account_upload_max_files'] ?? 10);
        if ($maxFiles <= 0) {
            $maxFiles = 10;
        }

        Settings::save($this->db(), [
            'account_upload_max_mb' => (string) $maxMb,
            'account_upload_max_files' => (string) $maxFiles,
            'account_upload_allowed_ext' => strtolower(trim((string) ($_POST['account_upload_allowed_ext'] ?? 'pdf,jpg,jpeg,png,webp'))),
            'account_activation_durations' => implode(',', ProfessionAccounts::allowedDurations([
                'account_activation_durations' => (string) ($_POST['account_activation_durations'] ?? ''),
            ])),
            'account_member_code_prefix' => strtoupper(trim((string) ($_POST['account_member_code_prefix'] ?? ProfessionAccounts::PREFIX_COD_IMPLICIT))),
            'header_account_label' => trim((string) ($_POST['header_account_label'] ?? 'Contul meu')),
            'email_brand_name' => trim((string) ($_POST['email_brand_name'] ?? '')),
            'email_logo_url' => trim((string) ($_POST['email_logo_url'] ?? '')),
            'account_avatar_max_mb' => (string) max(0.5, (float) str_replace(',', '.', (string) ($_POST['account_avatar_max_mb'] ?? '3'))),
            'account_avatar_folder' => trim((string) ($_POST['account_avatar_folder'] ?? 'Poze de profil')),
            'account_gdpr_label' => trim((string) ($_POST['account_gdpr_label'] ?? '')),
            'account_gdpr_url' => trim((string) ($_POST['account_gdpr_url'] ?? '')),
            'account_verification_notify_email' => trim((string) ($_POST['account_verification_notify_email'] ?? '')),
            'account_registration_intro' => trim((string) ($_POST['account_registration_intro'] ?? '')),
            'account_activation_title' => trim((string) ($_POST['account_activation_title'] ?? '')),
            'account_activation_intro' => trim((string) ($_POST['account_activation_intro'] ?? '')),
            'account_activation_button' => trim((string) ($_POST['account_activation_button'] ?? '')),
            'account_activation_active_text' => trim((string) ($_POST['account_activation_active_text'] ?? '')),
            'account_activation_renew_button' => trim((string) ($_POST['account_activation_renew_button'] ?? '')),
            'restricted_page_title' => trim((string) ($_POST['restricted_page_title'] ?? '')),
            'restricted_guest_text' => trim((string) ($_POST['restricted_guest_text'] ?? '')),
            'restricted_awaiting_email_text' => trim((string) ($_POST['restricted_awaiting_email_text'] ?? '')),
            'restricted_pending_text' => trim((string) ($_POST['restricted_pending_text'] ?? '')),
            'restricted_rejected_text' => trim((string) ($_POST['restricted_rejected_text'] ?? '')),
            'restricted_inactive_text' => trim((string) ($_POST['restricted_inactive_text'] ?? '')),
            'restricted_expired_text' => trim((string) ($_POST['restricted_expired_text'] ?? '')),
        ]);

        Flash::set('success', 'Setările conturilor profesionale au fost salvate.');
        header('Location: /admin/accounts/settings');
    }

    // ================================================================== ajutoare

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\- ]/i', '', $value) ?? '';
        $value = preg_replace('/[\s\-]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * Contul cu coloanele de membru (categoria, starea, contul activ până la, codul, poza): `CustomerAuth::user` din nucleu
     * citește doar coloanele contului de client (vertij le citea pe toate). Fără coloane (bază nemigrată), rândul ca atare.
     */
    public static function contMembru(?PDO $db, ?array $user): ?array
    {
        $id = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if (!$db instanceof PDO || $id <= 0) {
            return $user;
        }
        try {
            $stmt = $db->prepare('SELECT profession_id, account_status, account_active_until, member_code, avatar_url FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $rand = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $user;
        }
        return is_array($rand) ? array_merge($user, $rand) : $user;
    }

    private static function conexiune(): ?PDO
    {
        return \App\Support\Database::connection((array) ((require Paths::radacina() . '/config/app.php')['db'] ?? []));
    }
}
