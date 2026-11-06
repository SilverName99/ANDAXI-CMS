<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Parolă pe un folder din bunny.net.
 *
 * Un folder poate fi „închis": ca să-i vezi conținutul — din admin sau din
 * aplicația de desktop — trebuie să scrii parola lui. Parola apără tot ce e
 * înăuntru, oricât de adânc, fără să fie nevoie de bifat fișier cu fișier.
 *
 * Ce apără și ce nu — de spus limpede, fiindcă altfel se cred lucruri care nu
 * sunt adevărate: parola e o barieră între colegi, la nivelul site-ului.
 * Fișierul din bunny.net rămâne cum a fost, necriptat, iar adresa lui publică
 * de pe CDN funcționează mai departe pentru cine o cunoaște. Cine are cheia
 * zonei de storage îl vede oricum. Pentru așa ceva ar trebui criptat fișierul
 * însuși, ceea ce e cu totul altă discuție.
 *
 * Deblocarea nu ține veșnic: expiră după o oră de neatingere, iar o parolă
 * greșită de prea multe ori închide ușa pentru câteva minute.
 *
 * Schimbări față de Biovitality (revizia de securitate, docs/PLUGINURI.md, „Fișiere în cloud”):
 *  - copiile din `_versiuni/<cale>/…` sunt păzite de parola lui `<cale>` (calea „efectivă”, fără `_versiuni/` în față);
 *  - căile se compară fără să țină cont de litere mari și mici (`Contracte/x` e tot în `contracte`);
 *  - dacă lista folderelor cu parolă nu se poate citi, totul e închis (`no_db`), nu deschis;
 *  - încercările greșite se numără pe administrator și folder, nu pe sesiune sau cheie, cu o singură scriere
 *    atomică înaintea verificării (cereri trimise deodată nu primesc mai multe încercări).
 *
 * Pluginul `fisiere_cloud` (din Biovitality; docs/PLUGINURI.md, „Fișiere în cloud”), copiat cum e, fără `ensureSchema`:
 * tabelele `bunny_protected_folders` și `bunny_folder_unlocks` le face migrarea
 * `fisiere_cloud/0059` (adoptivă: pe Biovitality există, cu aceeași definiție).
 */
final class FolderPasswords
{
    /** Cât ține o deblocare de la ultima atingere. */
    public const UNLOCK_MINUTES = 60;

    /** Câte greșeli sunt îngăduite înainte de o pauză forțată. */
    public const MAX_GRESELI = 5;

    /** Cât durează pauza după prea multe greșeli. */
    public const PAUZA_MINUTE = 5;

    /** Lungimea minimă a unei parole de folder. */
    public const MIN_LUNGIME = 6;

    /** Amprenta unei căi, ca să poată fi cheie unică. */
    public static function hash(string $path): string
    {
        return hash('sha256', 'folder:' . BunnyStorage::normalizePath($path));
    }

    // ───────────────────────────────────────────────────────────
    // Punerea și scoaterea parolei
    // ───────────────────────────────────────────────────────────

    /**
     * Pune sau schimbă parola unui folder.
     *
     * @return array{ok: bool, error: string}
     */
    public static function protect(
        PDO $db,
        string $folder,
        string $password,
        string $hint,
        int $adminId,
        string $adminEmail
    ): array {
        $folder = BunnyStorage::normalizePath($folder);

        if ($folder === '') {
            return ['ok' => false, 'error' => 'Rădăcina zonei nu poate primi parolă.'];
        }
        if (FileVersions::isArchivePath($folder)) {
            return ['ok' => false, 'error' => 'Arhiva de versiuni nu poate primi parolă.'];
        }
        if (mb_strlen($password) < self::MIN_LUNGIME) {
            return ['ok' => false, 'error' => 'Parola trebuie să aibă cel puțin ' . self::MIN_LUNGIME . ' caractere.'];
        }
        if (self::indisponibil($db)) {
            return ['ok' => false, 'error' => 'Nu pot citi acum parolele folderelor. Încearcă din nou peste puțin.'];
        }
        // Același folder scris cu alte litere mari („Contracte”) primește parola existentă, nu un rând al doilea.
        $existent = self::find($db, $folder);
        if ($existent !== null) {
            $folder = (string) $existent['folder_path'];
        }

        try {
            $db->prepare(
                'INSERT INTO bunny_protected_folders
                    (folder_path, path_hash, password_hash, hint, created_by, created_by_email)
                 VALUES (:folder_path, :path_hash, :password_hash, :hint, :created_by, :created_by_email)
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), hint = VALUES(hint)'
            )->execute([
                'folder_path' => mb_substr($folder, 0, 700),
                'path_hash' => self::hash($folder),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'hint' => $hint !== '' ? mb_substr($hint, 0, 190) : null,
                'created_by' => $adminId > 0 ? $adminId : null,
                'created_by_email' => $adminEmail !== '' ? $adminEmail : null,
            ]);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Nu am putut salva parola folderului.'];
        }

        // O parolă nouă închide ușa pentru toți cei care o aveau deschisă cu cea
        // veche — altfel schimbarea n-ar însemna nimic până la expirare.
        self::forgetUnlocks($db, $folder);

        return ['ok' => true, 'error' => ''];
    }

    /** Scoate parola unui folder. */
    public static function remove(PDO $db, string $folder): bool
    {
        $folder = BunnyStorage::normalizePath($folder);
        if ($folder === '') {
            return false;
        }
        $existent = self::find($db, $folder);
        if ($existent !== null) {
            $folder = (string) $existent['folder_path'];
        }

        try {
            $stmt = $db->prepare('DELETE FROM bunny_protected_folders WHERE path_hash = :hash');
            $stmt->execute(['hash' => self::hash($folder)]);
            self::forgetUnlocks($db, $folder);

            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Datele de protecție ale unui folder anume (fără parola în clar, evident).
     *
     * @return array<string, mixed>|null
     */
    public static function find(PDO $db, string $folder): ?array
    {
        $cheie = self::cheie($folder);
        foreach (self::all($db) as $record) {
            if (self::cheie((string) $record['folder_path']) === $cheie) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Calea care contează pentru parole: copia `_versiuni/contracte/a.xlsx/20260101-a.xlsx` e păzită ca
     * `contracte/a.xlsx/20260101-a.xlsx`, deci de parola lui `contracte`. Arhiva însăși (`_versiuni`) dă rădăcina.
     */
    public static function efectiva(string $path): string
    {
        $path = BunnyStorage::normalizePath($path);
        $arhiva = mb_strtolower(FileVersions::ARCHIVE_FOLDER);
        while ($path !== '') {
            $mic = mb_strtolower($path);
            if ($mic === $arhiva) {
                return '';
            }
            if (!str_starts_with($mic, $arhiva . '/')) {
                break;
            }
            $path = substr($path, strlen($arhiva) + 1);
        }

        return $path;
    }

    /** Forma căii după care se compară: efectivă, cu litere mici. */
    private static function cheie(string $path): string
    {
        return mb_strtolower(self::efectiva($path));
    }

    /** Lista folderelor cu parolă nu s-a putut citi (atunci totul e închis). */
    public static function indisponibil(PDO $db): bool
    {
        self::all($db);

        return self::$eroare;
    }

    /**
     * Răspunsul „închis” când lista nu se poate citi. `no_db` îl recunosc apelanții (mesaj în admin, 503 în aplicație).
     *
     * @return array<string, mixed>
     */
    private static function inchisFaraBaza(string $path): array
    {
        return ['folder_path' => self::efectiva($path), 'hint' => null, 'no_db' => true];
    }

    /**
     * Toate folderele cu parolă. Se citesc o singură dată pe cerere: sunt
     * puține, iar căutarea celui care păzește o cale le cere pe toate.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = $db->query('SELECT * FROM bunny_protected_folders ORDER BY folder_path ASC')->fetchAll() ?: [];
        } catch (Throwable) {
            // Lista goală nu înseamnă aici „nimic protejat”: `guardFor` și celelalte se uită la `$eroare` și închid tot.
            self::$eroare = true;

            return self::$cache = [];
        }
        self::$eroare = false;

        return self::$cache = array_values(array_filter($rows, 'is_array'));
    }

    /** Uită lista citită. Se cheamă după orice schimbare de protecție. */
    public static function resetCache(): void
    {
        self::$cache = null;
        self::$eroare = false;
    }

    /** @var list<array<string, mixed>>|null */
    private static ?array $cache = null;

    private static bool $eroare = false;

    /**
     * Folderul cu parolă care păzește calea dată, sau null dacă nu e păzită.
     *
     * Se caută în sus: pentru `contracte/2026/ianuarie.xlsx` întâi calea însăși
     * (dacă e chiar un folder închis), apoi `contracte/2026`, apoi `contracte`.
     * Câștigă cel mai adânc: un folder închis dinăuntrul altuia își cere propria
     * parolă, nu pe a părintelui (de aceea parola unui subfolder o poate pune
     * doar cine are deschise folderele de deasupra; vezi `lockedChain`).
     *
     * Calea se ia „efectivă” (o copie din `_versiuni` e păzită ca originalul) și
     * fără să conteze literele mari. Dacă lista nu se poate citi, întoarce un
     * paznic `no_db`: totul e închis.
     *
     * @return array<string, mixed>|null
     */
    public static function guardFor(PDO $db, string $path): ?array
    {
        $paznici = self::guardsFor($db, $path);

        return $paznici === [] ? null : $paznici[count($paznici) - 1];
    }

    /**
     * Toate folderele cu parolă care acoperă calea, de la cel mai de sus la cel
     * mai adânc (cu `no_db` singur, dacă lista nu se poate citi).
     *
     * @return list<array<string, mixed>>
     */
    public static function guardsFor(PDO $db, string $path): array
    {
        $protejate = self::all($db);
        if (self::$eroare) {
            return [self::inchisFaraBaza($path)];
        }
        if ($protejate === []) {
            return [];
        }

        $cale = self::cheie($path);
        $gasite = [];
        foreach ($protejate as $record) {
            $folder = self::cheie((string) $record['folder_path']);
            if ($folder === '') {
                continue;
            }
            if ($cale === $folder || str_starts_with($cale . '/', $folder . '/')) {
                $gasite[] = $record;
            }
        }
        usort($gasite, static fn (array $a, array $b): int =>
            mb_strlen(self::cheie((string) $a['folder_path'])) <=> mb_strlen(self::cheie((string) $b['folder_path'])));

        return $gasite;
    }

    /** Parola dată se potrivește cu cea a folderului? */
    public static function verify(string $password, array $record): bool
    {
        $hash = (string) ($record['password_hash'] ?? '');

        return $hash !== '' && password_verify($password, $hash);
    }

    // ───────────────────────────────────────────────────────────
    // Deblocarea
    // ───────────────────────────────────────────────────────────

    /** Cine cere: sesiunea din browser. */
    public static function whoSession(): string
    {
        $id = session_id();

        return 'sesiune:' . substr(hash('sha256', is_string($id) && $id !== '' ? $id : 'fara-sesiune'), 0, 40);
    }

    /** Cine cere: o cheie de aplicație. */
    public static function whoToken(int $tokenId): string
    {
        return 'aplicatie:' . $tokenId;
    }

    /**
     * Încearcă deblocarea. Întoarce ce s-a întâmplat, ca apelantul să poată
     * spune omului adevărul: parolă greșită, prea multe încercări, sau gata.
     *
     * @return array{ok: bool, reason: string, wait_minutes: int}
     */
    public static function unlock(PDO $db, string $who, string $folder, string $password, int $adminId = 0): array
    {
        $rezultat = self::checkPassword($db, $who, $folder, $password, $adminId);
        if ($rezultat['reason'] === 'ok') {
            $record = self::find($db, $folder);
            self::scrieDeblocare($db, $who, $record !== null ? (string) $record['folder_path'] : $folder);

            return ['ok' => true, 'reason' => 'unlocked', 'wait_minutes' => 0];
        }

        return $rezultat;
    }

    /**
     * Parola folderului e cea dată? Fără să deschidă nimic, dar cu aceeași numărătoare de greșeli ca deblocarea —
     * o folosesc și schimbarea și scoaterea parolei, ca să nu fie o a doua ușă fără limită de încercări.
     *
     * @return array{ok: bool, reason: string, wait_minutes: int} reason: ok, not_protected, wrong_password,
     *         throttled, unavailable
     */
    public static function checkPassword(PDO $db, string $who, string $folder, string $password, int $adminId = 0): array
    {
        if (self::indisponibil($db)) {
            return ['ok' => false, 'reason' => 'unavailable', 'wait_minutes' => 0];
        }
        $record = self::find($db, $folder);
        if ($record === null) {
            // Nu e închis: nu e nimic de deblocat, dar nici o eroare.
            return ['ok' => true, 'reason' => 'not_protected', 'wait_minutes' => 0];
        }
        $folder = (string) $record['folder_path'];
        $numarator = self::whoAdmin($adminId, $who);

        // Încercarea se numără înainte de verificare, cu o singură scriere atomică: zece cereri trimise deodată
        // primesc tot cel mult MAX_GRESELI verificări, oricâte sesiuni sau chei ar folosi administratorul.
        $rezervare = self::rezervaIncercare($db, $numarator, $folder);
        if ($rezervare['wait_minutes'] > 0) {
            return ['ok' => false, 'reason' => 'throttled', 'wait_minutes' => $rezervare['wait_minutes']];
        }

        if (!self::verify($password, $record)) {
            if ($rezervare['greseli'] >= self::MAX_GRESELI) {
                self::blocheaza($db, $numarator, $folder);
            }

            return ['ok' => false, 'reason' => 'wrong_password', 'wait_minutes' => 0];
        }

        self::uitaGreselile($db, $numarator, $folder);

        return ['ok' => true, 'reason' => 'ok', 'wait_minutes' => 0];
    }

    /** Cine numără greșelile: administratorul (orice sesiune sau cheie ar avea). Fără id, cel care cere. */
    public static function whoAdmin(int $adminId, string $who = ''): string
    {
        return $adminId > 0 ? 'admin:' . $adminId : $who;
    }

    /**
     * Folderul e deblocat acum pentru cine întreabă?
     *
     * Fiecare atingere reîmprospătează valabilitatea: expirarea numără de la
     * ultima folosire, nu de la deblocare, ca să nu-i sară parola în față
     * omului tocmai când lucrează.
     */
    public static function isUnlocked(PDO $db, string $who, string $folder, bool $refresh = true): bool
    {
        $stare = self::stare($db, $who, $folder);
        $pana = trim((string) ($stare['unlocked_until'] ?? ''));
        if ($pana === '' || strtotime($pana) === false || strtotime($pana) < time()) {
            return false;
        }

        if ($refresh) {
            self::scrieDeblocare($db, $who, $folder);
        }

        return true;
    }

    /**
     * Calea e blocată pentru cine întreabă? Întoarce folderul care o păzește,
     * sau null dacă e liberă (fie nu e păzită, fie a fost deblocată).
     *
     * @return array<string, mixed>|null
     */
    public static function lockedFor(PDO $db, string $who, string $path, bool $refresh = true): ?array
    {
        $guard = self::guardFor($db, $path);
        if ($guard === null) {
            return null;
        }
        if (($guard['no_db'] ?? false) === true) {
            return $guard;
        }

        return self::isUnlocked($db, $who, (string) $guard['folder_path'], $refresh) ? null : $guard;
    }

    /**
     * Primul folder cu parolă, de sus în jos, închis pentru cine întreabă — calea însăși sau oricare de deasupra.
     * Pentru punerea și scoaterea parolei: altfel cine n-are parola lui `contracte` ar pune una a lui pe
     * `contracte/2026` și ar intra cu ea (paznicul cel mai adânc câștigă).
     *
     * @return array<string, mixed>|null
     */
    public static function lockedChain(PDO $db, string $who, string $path): ?array
    {
        foreach (self::guardsFor($db, $path) as $guard) {
            if (($guard['no_db'] ?? false) === true
                || !self::isUnlocked($db, $who, (string) $guard['folder_path'], false)) {
                return $guard;
            }
        }

        return null;
    }

    /**
     * Un folder cu parolă de sub calea dată (strict dedesubt), închis pentru cine întreabă. Pentru ștergerea unui
     * folder: n-ai voie să ștergi ce n-ai voie să vezi.
     *
     * @return array<string, mixed>|null
     */
    public static function lockedUnder(PDO $db, string $who, string $path): ?array
    {
        $protejate = self::all($db);
        if (self::$eroare) {
            return self::inchisFaraBaza($path);
        }

        $cale = self::cheie($path);
        foreach ($protejate as $record) {
            $folder = self::cheie((string) $record['folder_path']);
            if ($folder === '' || $folder === $cale) {
                continue;
            }
            if ($cale !== '' && !str_starts_with($folder, $cale . '/')) {
                continue;
            }
            if (!self::isUnlocked($db, $who, (string) $record['folder_path'], false)) {
                return $record;
            }
        }

        return null;
    }

    /** Închide la loc un folder pentru cine cere (sau toate, dacă e null). */
    public static function lock(PDO $db, string $who, ?string $folder = null): void
    {
        try {
            if ($folder === null) {
                $db->prepare('DELETE FROM bunny_folder_unlocks WHERE who = :who')->execute(['who' => $who]);
                return;
            }
            $record = self::find($db, $folder);
            $db->prepare('DELETE FROM bunny_folder_unlocks WHERE who = :who AND path_hash = :hash')
               ->execute(['who' => $who, 'hash' => self::hash($record !== null ? (string) $record['folder_path'] : $folder)]);
        } catch (Throwable) {
        }
    }

    /** Câte minute mai are de așteptat cine a greșit prea des. Zero = poate încerca. */
    public static function waitMinutes(PDO $db, string $who, string $folder, int $adminId = 0): int
    {
        $record = self::find($db, $folder);

        return self::minuteDeAsteptat(self::stare(
            $db,
            self::whoAdmin($adminId, $who),
            $record !== null ? (string) $record['folder_path'] : $folder
        ));
    }

    // ───────────────────────────────────────────────────────────
    // Mutarea și ștergerea folderelor
    // ───────────────────────────────────────────────────────────

    /**
     * Mută protecția odată cu folderul redenumit, împreună cu cele ale
     * subfolderelor lui. Altfel ar rămâne agățată de o cale moartă, iar folderul
     * nou ar apărea deschis.
     *
     * @return int câte protecții s-au mutat
     */
    public static function repathPrefix(PDO $db, string $oldPrefix, string $newPrefix): int
    {
        $oldPrefix = BunnyStorage::normalizePath($oldPrefix);
        $newPrefix = BunnyStorage::normalizePath($newPrefix);
        if ($oldPrefix === '' || $newPrefix === '' || $oldPrefix === $newPrefix) {
            return 0;
        }

        $mutate = 0;
        foreach (self::all($db) as $record) {
            $vechi = BunnyStorage::normalizePath((string) $record['folder_path']);
            if (!self::subPrefix($vechi, $oldPrefix)) {
                continue;
            }
            $nou = $newPrefix . mb_substr($vechi, mb_strlen($oldPrefix));
            try {
                $db->prepare(
                    'UPDATE bunny_protected_folders SET folder_path = :nou, path_hash = :hash_nou
                     WHERE path_hash = :hash_vechi'
                )->execute([
                    'nou' => mb_substr($nou, 0, 700),
                    'hash_nou' => self::hash($nou),
                    'hash_vechi' => self::hash($vechi),
                ]);
                $mutate++;
            } catch (Throwable) {
            }
            self::forgetUnlocks($db, $vechi);
        }

        if ($mutate > 0) {
            self::resetCache();
        }

        return $mutate;
    }

    /**
     * Uită protecția unui folder șters, cu tot cu subfolderele lui.
     *
     * @return int câte protecții s-au șters
     */
    public static function forgetPrefix(PDO $db, string $folder): int
    {
        $folder = BunnyStorage::normalizePath($folder);
        if ($folder === '') {
            return 0;
        }

        $sterse = 0;
        foreach (self::all($db) as $record) {
            $cale = BunnyStorage::normalizePath((string) $record['folder_path']);
            if (!self::subPrefix($cale, $folder)) {
                continue;
            }
            if (self::remove($db, $cale)) {
                $sterse++;
            }
        }

        if ($sterse > 0) {
            self::resetCache();
        }

        return $sterse;
    }

    // ───────────────────────────────────────────────────────────
    // Intern
    // ───────────────────────────────────────────────────────────

    /** `$cale` e chiar `$prefix` sau ceva dinăuntrul lui (fără să conteze literele mari)? */
    private static function subPrefix(string $cale, string $prefix): bool
    {
        $cale = mb_strtolower($cale);
        $prefix = mb_strtolower($prefix);

        return $cale === $prefix || str_starts_with($cale, $prefix . '/');
    }

    /** @return array<string, mixed> */
    private static function stare(PDO $db, string $who, string $folder): array
    {
        try {
            $stmt = $db->prepare('SELECT * FROM bunny_folder_unlocks WHERE who = :who AND path_hash = :hash LIMIT 1');
            $stmt->execute(['who' => $who, 'hash' => self::hash($folder)]);
            $row = $stmt->fetch();

            return is_array($row) ? $row : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function minuteDeAsteptat(array $stare): int
    {
        $pana = trim((string) ($stare['blocked_until'] ?? ''));
        if ($pana === '') {
            return 0;
        }
        $ts = strtotime($pana);
        if ($ts === false || $ts <= time()) {
            return 0;
        }

        return (int) max(1, ceil(($ts - time()) / 60));
    }

    private static function scrieDeblocare(PDO $db, string $who, string $folder): void
    {
        try {
            $db->prepare(
                'INSERT INTO bunny_folder_unlocks (who, folder_path, path_hash, unlocked_until, greseli, blocked_until)
                 VALUES (:who, :folder_path, :path_hash, :pana, 0, NULL)
                 ON DUPLICATE KEY UPDATE unlocked_until = VALUES(unlocked_until), greseli = 0, blocked_until = NULL'
            )->execute([
                'who' => $who,
                'folder_path' => mb_substr(BunnyStorage::normalizePath($folder), 0, 700),
                'path_hash' => self::hash($folder),
                'pana' => date('Y-m-d H:i:s', time() + self::UNLOCK_MINUTES * 60),
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Ține locul unei încercări: o adună la numărătoare (INSERT … ON DUPLICATE KEY UPDATE, atomic) și citește
     * rezultatul. Peste MAX_GRESELI, sau cu pauza în curs, nu se mai verifică nimic. Dacă scrierea nu merge, nici
     * verificarea nu se face (fără numărătoare n-ar exista limită).
     *
     * @return array{greseli: int, wait_minutes: int}
     */
    private static function rezervaIncercare(PDO $db, string $who, string $folder): array
    {
        $asteapta = self::minuteDeAsteptat(self::stare($db, $who, $folder));
        if ($asteapta > 0) {
            return ['greseli' => 0, 'wait_minutes' => $asteapta];
        }

        try {
            $db->prepare(
                'INSERT INTO bunny_folder_unlocks (who, folder_path, path_hash, unlocked_until, greseli, blocked_until)
                 VALUES (:who, :folder_path, :path_hash, NULL, 1, NULL)
                 ON DUPLICATE KEY UPDATE greseli = IF(greseli >= 250, 250, greseli + 1)'
            )->execute([
                'who' => $who,
                'folder_path' => mb_substr(BunnyStorage::normalizePath($folder), 0, 700),
                'path_hash' => self::hash($folder),
            ]);
        } catch (Throwable) {
            return ['greseli' => 0, 'wait_minutes' => 1];
        }

        $stare = self::stare($db, $who, $folder);
        if ($stare === []) {
            return ['greseli' => 0, 'wait_minutes' => 1];
        }
        $asteapta = self::minuteDeAsteptat($stare);
        if ($asteapta > 0) {
            return ['greseli' => 0, 'wait_minutes' => $asteapta];
        }
        $greseli = (int) ($stare['greseli'] ?? 0);
        if ($greseli > self::MAX_GRESELI) {
            self::blocheaza($db, $who, $folder);

            return ['greseli' => $greseli, 'wait_minutes' => self::PAUZA_MINUTE];
        }

        return ['greseli' => $greseli, 'wait_minutes' => 0];
    }

    /** Pauza după prea multe greșeli; numărătoarea o ia de la capăt după ea. */
    private static function blocheaza(PDO $db, string $who, string $folder): void
    {
        try {
            $db->prepare(
                'UPDATE bunny_folder_unlocks SET greseli = 0, blocked_until = :blocat
                  WHERE who = :who AND path_hash = :hash AND (blocked_until IS NULL OR blocked_until < :acum)'
            )->execute([
                'blocat' => date('Y-m-d H:i:s', time() + self::PAUZA_MINUTE * 60),
                'who' => $who,
                'hash' => self::hash($folder),
                'acum' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
        }
    }

    /** Parola bună: greșelile de până acum nu mai contează. */
    private static function uitaGreselile(PDO $db, string $who, string $folder): void
    {
        try {
            $db->prepare('UPDATE bunny_folder_unlocks SET greseli = 0 WHERE who = :who AND path_hash = :hash')
               ->execute(['who' => $who, 'hash' => self::hash($folder)]);
        } catch (Throwable) {
        }
    }

    /** Închide folderul pentru toată lumea — la schimbarea sau scoaterea parolei. */
    private static function forgetUnlocks(PDO $db, string $folder): void
    {
        try {
            $db->prepare('DELETE FROM bunny_folder_unlocks WHERE path_hash = :hash')
               ->execute(['hash' => self::hash($folder)]);
        } catch (Throwable) {
        }
        self::resetCache();
    }
}
