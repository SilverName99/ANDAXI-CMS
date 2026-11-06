<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Client pentru Bunny Edge Storage (bunny.net).
 *
 * Fișierele stau într-o „Storage Zone", iar publicul le vede printr-o „Pull
 * Zone" (adresa `…b-cdn.net`). Clasa asta face legătura: citește folderele
 * urcate în Bunny, urcă/șterge fișiere și construiește adresa publică pe CDN.
 *
 * Autentificarea la storage se face cu parola zonei (Storage Zone → FTP & API
 * Access → Password), trimisă în antetul `AccessKey`. Cheia de cont
 * (Account Settings → API) e opțională și folosește doar la descoperirea
 * automată a zonelor.
 *
 * Toate metodele aruncă RuntimeException cu mesaj citibil de operator.
 *
 * Pluginul `fisiere_cloud` (din Biovitality; docs/PLUGINURI.md, „Fișiere în cloud”), copiat cum e. Doar în dezvoltare
 * (`APP_ENV=local`), `CMS_DEV_BUNNY_URL` trimite cererile spre o zonă falsă (tools/dev/lib/bunny-fals.php), ca testul
 * aplicației de desktop (tools/dev/test-fisiere-cloud.php) să nu iasă în rețea; pe un site nu se citește.
 */
final class BunnyStorage
{
    /** Prefixul de host al fiecărei regiuni de storage. Cheia goală = Falkenstein (implicit). */
    public const REGIONS = [
        ''    => 'Falkenstein, Germania (implicit)',
        'uk'  => 'Londra, UK',
        'ny'  => 'New York, SUA',
        'la'  => 'Los Angeles, SUA',
        'se'  => 'Stockholm, Suedia',
        'sg'  => 'Singapore',
        'syd' => 'Sydney, Australia',
        'br'  => 'São Paulo, Brazilia',
        'jh'  => 'Johannesburg, Africa de Sud',
    ];

    /** Codul de regiune întors de API-ul de cont → prefixul de host de storage. */
    private const REGION_CODES = [
        'DE' => '', 'FS' => '',
        'UK' => 'uk', 'LN' => 'uk',
        'NY' => 'ny',
        'LA' => 'la',
        'SE' => 'se', 'STK' => 'se',
        'SG' => 'sg',
        'SYD' => 'syd',
        'BR' => 'br',
        'JH' => 'jh',
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif', 'bmp', 'ico'];
    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v', 'ogv'];

    /** Fișierul urcat ca să existe un folder gol în storage (Bunny nu are „mkdir"). */
    public const FOLDER_PLACEHOLDER = '.bunny-folder';

    private string $zone;
    private string $accessKey;
    private string $region;
    private string $cdnUrl;
    private string $baseFolder;
    private int $timeout;

    public function __construct(
        string $zone,
        string $accessKey,
        string $region = '',
        string $cdnUrl = '',
        string $baseFolder = '',
        int $timeout = 20
    ) {
        $this->zone = trim($zone, " \t\n\r\0\x0B/");
        $this->accessKey = trim($accessKey);
        $this->region = self::normalizeRegion($region);
        $this->cdnUrl = self::normalizeCdnUrl($cdnUrl);
        $this->baseFolder = self::normalizePath($baseFolder);
        $this->timeout = max(5, $timeout);
    }

    /** Clientul din setările salvate în admin, sau null dacă lipsesc datele minime. */
    public static function fromSettings(array $settings): ?self
    {
        $zone = trim((string) ($settings['bunny_storage_zone'] ?? ''));
        $key = trim((string) ($settings['bunny_storage_password'] ?? ''));
        if ($zone === '' || $key === '') {
            return null;
        }

        return new self(
            $zone,
            $key,
            (string) ($settings['bunny_storage_region'] ?? ''),
            (string) ($settings['bunny_cdn_url'] ?? ''),
            (string) ($settings['bunny_base_folder'] ?? ''),
            (int) ($settings['bunny_timeout'] ?? 20)
        );
    }

    /** Integrarea e pornită ȘI configurată complet (inclusiv adresa CDN). */
    public static function isEnabled(array $settings): bool
    {
        if ((string) ($settings['bunny_enabled'] ?? '0') !== '1') {
            return false;
        }
        $client = self::fromSettings($settings);

        return $client !== null && $client->cdnUrl !== '';
    }

    public function zoneName(): string
    {
        return $this->zone;
    }

    public function cdnUrl(): string
    {
        return $this->cdnUrl;
    }

    public function baseFolder(): string
    {
        return $this->baseFolder;
    }

    public function hasCdnUrl(): bool
    {
        return $this->cdnUrl !== '';
    }

    /** Hostul de storage folosit pentru zona curentă. */
    public function storageHost(): string
    {
        return ($this->region !== '' ? $this->region . '.' : '') . 'storage.bunnycdn.com';
    }

    /**
     * Conținutul unui folder din Bunny.
     *
     * @return array{folders: list<array{name: string, path: string}>, files: list<array<string, mixed>>}
     */
    public function listPath(string $path = ''): array
    {
        $path = self::normalizePath($path);
        $raw = $this->request('GET', $this->storagePath($path) . '/');
        $items = json_decode((string) $raw, true);
        if (!is_array($items)) {
            throw new RuntimeException('Bunny a răspuns cu un conținut neașteptat pentru folderul cerut.');
        }

        $folders = [];
        $files = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = (string) ($item['ObjectName'] ?? '');
            if ($name === '' || $name === self::FOLDER_PLACEHOLDER) {
                continue;
            }
            $childPath = $path === '' ? $name : $path . '/' . $name;

            if (($item['IsDirectory'] ?? false) === true) {
                $folders[] = [
                    'name' => $name,
                    'path' => $childPath,
                ];
                continue;
            }

            $files[] = [
                'name' => $name,
                'path' => $childPath,
                'size' => (int) ($item['Length'] ?? 0),
                'updated_at' => (string) ($item['LastChanged'] ?? ($item['DateCreated'] ?? '')),
                'media_type' => self::mediaType($name),
                'url' => $this->publicUrl($childPath),
            ];
        }

        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        usort($files, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['folders' => $folders, 'files' => $files];
    }

    /** Verifică datele de acces citind rădăcina zonei. Întoarce un rezumat. */
    public function ping(): array
    {
        $contents = $this->listPath('');

        return [
            'folders' => count($contents['folders']),
            'files' => count($contents['files']),
            'host' => $this->storageHost(),
        ];
    }

    /** Urcă un fișier de pe disc la calea dată (relativă la folderul de bază). */
    public function uploadFile(string $path, string $localFile): void
    {
        if (!is_file($localFile)) {
            throw new RuntimeException('Fișierul de urcat nu a fost găsit pe server.');
        }
        $handle = @fopen($localFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Nu am putut citi fișierul de urcat.');
        }
        try {
            $this->put($path, $handle, (int) (filesize($localFile) ?: 0));
        } finally {
            @fclose($handle);
        }
    }

    /** Urcă un conținut generat în cod (folosit pentru fișierul-marcaj de folder). */
    public function uploadContents(string $path, string $contents): void
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Nu am putut pregăti conținutul pentru urcare.');
        }
        try {
            fwrite($handle, $contents);
            rewind($handle);
            $this->put($path, $handle, strlen($contents));
        } finally {
            @fclose($handle);
        }
    }

    /**
     * Creează un folder. Bunny nu are „mkdir": folderul apare abia când conține
     * un fișier, așa că urcăm un marcaj minuscul, ascuns din listări.
     */
    public function createFolder(string $path): void
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            throw new RuntimeException('Numele folderului este obligatoriu.');
        }
        $this->uploadContents($path . '/' . self::FOLDER_PLACEHOLDER, "bunny\n");
    }

    /**
     * Conținutul unui fișier, citit cu cheia zonei.
     *
     * Documentele de lucru nu trebuie luate de pe adresa publică de CDN — aceea
     * e deschisă oricui nimerește linkul. Aici trecem prin API, cu autentificare.
     */
    public function download(string $path): string
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            throw new RuntimeException('Calea fișierului lipsește.');
        }

        return $this->request('GET', $this->storagePath($path));
    }

    /** Salvează un fișier din zonă direct pe disc, fără să-l ținem tot în memorie. */
    public function downloadToFile(string $path, string $localFile): void
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            throw new RuntimeException('Calea fișierului lipsește.');
        }
        $this->guardConfig();

        $handle = @fopen($localFile, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Nu am putut scrie fișierul temporar pe server.');
        }

        $ch = curl_init($this->adresaStorage() . $this->storagePath($path));
        curl_setopt($ch, CURLOPT_FILE, $handle);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['AccessKey: ' . $this->accessKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(120, $this->timeout));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);

        if ($ok === false) {
            @unlink($localFile);
            throw new RuntimeException('Nu am putut descărca fișierul din Bunny: ' . ($error !== '' ? $error : 'eroare de rețea'));
        }
        if ($status < 200 || $status >= 300) {
            @unlink($localFile);
            throw new RuntimeException($this->errorMessage($status, ''));
        }
    }

    /**
     * Datele unui singur fișier (dimensiune, data modificării), citite din
     * listarea folderului părinte. Null dacă fișierul nu există.
     *
     * @return array<string, mixed>|null
     */
    public function fileInfo(string $path): ?array
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            return null;
        }
        $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
        $name = basename($path);

        foreach ($this->listPath($parent)['files'] as $file) {
            if ((string) $file['name'] === $name) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Mută un fișier sub alt nume.
     *
     * Bunny nu are redenumire: singura cale e să citim conținutul, să-l scriem
     * sub numele nou și abia apoi să ștergem originalul. Ordinea contează —
     * dacă scrierea eșuează, fișierul vechi e încă acolo, neatins.
     */
    public function moveFile(string $from, string $to): void
    {
        $from = self::normalizePath($from);
        $to = self::normalizePath($to);
        if ($from === '' || $to === '') {
            throw new RuntimeException('Calea veche sau cea nouă lipsește.');
        }
        if ($from === $to) {
            return;
        }

        $contents = $this->download($from);
        $this->uploadContents($to, $contents);
        $this->deleteFile($from);
    }

    /**
     * Există deja un folder cu numele ăsta?
     *
     * Se uită în listarea părintelui, nu la o cale directă: Bunny nu are
     * foldere adevărate, ci doar căi, așa că singurul răspuns de încredere e
     * ce vede părintele.
     */
    public function folderExists(string $path): bool
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            return true;
        }
        $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
        $name = basename($path);

        foreach ($this->listPath($parent)['folders'] as $folder) {
            if ((string) $folder['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tot ce se află sub un folder, pe căi relative la el.
     *
     * Limita nu e o toană: mutarea unui folder trece fiecare fișier prin
     * serverul nostru, iar un folder uriaș ar ține cererea deschisă până
     * expiră. Mai bine un „nu se poate” explicit decât o mutare pe jumătate.
     *
     * @return array{folders: list<string>, files: list<string>}
     */
    public function tree(string $root, int $maxItems = 400): array
    {
        $root = self::normalizePath($root);
        if ($root === '') {
            throw new RuntimeException('Calea folderului lipsește.');
        }

        $folders = [];
        $files = [];
        $queue = [''];

        while ($queue !== []) {
            $relative = array_shift($queue);
            $contents = $this->listPath($relative === '' ? $root : $root . '/' . $relative);

            foreach ($contents['folders'] as $folder) {
                $child = $relative === '' ? (string) $folder['name'] : $relative . '/' . $folder['name'];
                $folders[] = $child;
                $queue[] = $child;
            }
            foreach ($contents['files'] as $file) {
                $files[] = $relative === '' ? (string) $file['name'] : $relative . '/' . $file['name'];
            }

            if (count($files) + count($folders) > $maxItems) {
                throw new RuntimeException(
                    'Folderul „' . basename($root) . '” are peste ' . $maxItems . ' de elemente. '
                    . 'Mută-l pe bucăți sau cere ridicarea limitei.'
                );
            }
        }

        return ['folders' => $folders, 'files' => $files];
    }

    /**
     * Mută un folder cu tot ce conține, sub alt nume sau în altă parte.
     *
     * Ca la fișiere, Bunny n-are redenumire — dar aici sunt multe fișiere, deci
     * ordinea contează și mai mult: întâi se copiază tot, și abia dacă a mers
     * tot se șterge originalul. Dacă pică ceva la mijloc, folderul vechi e
     * neatins, iar copia începută se șterge, ca să nu rămână două variante pe
     * jumătate din care nu se mai înțelege care e bună.
     *
     * @return int câte fișiere s-au mutat
     */
    public function moveFolder(string $from, string $to, int $maxItems = 400): int
    {
        $from = self::normalizePath($from);
        $to = self::normalizePath($to);
        if ($from === '' || $to === '') {
            throw new RuntimeException('Calea veche sau cea nouă lipsește.');
        }
        if ($from === $to) {
            return 0;
        }
        // Un folder nu poate ajunge în propriul lui interior: ar copia la
        // nesfârșit în ce tocmai a copiat.
        if (str_starts_with($to . '/', $from . '/')) {
            throw new RuntimeException('Nu pot muta un folder în interiorul lui însuși.');
        }
        if ($this->folderExists($to)) {
            throw new RuntimeException('Există deja un folder cu numele „' . basename($to) . '” acolo.');
        }

        $tree = $this->tree($from, $maxItems);

        try {
            $this->createFolder($to);
            foreach ($tree['folders'] as $relative) {
                $this->createFolder($to . '/' . $relative);
            }
            foreach ($tree['files'] as $relative) {
                $this->uploadContents($to . '/' . $relative, $this->download($from . '/' . $relative));
            }
        } catch (RuntimeException $e) {
            // Ștergerea e sigură: am refuzat deja mai sus dacă folderul exista
            // dinainte, deci tot ce e acolo e ce-am scris noi acum.
            try {
                $this->deleteFolder($to);
            } catch (RuntimeException) {
            }

            throw new RuntimeException('Mutarea folderului a eșuat, iar folderul vechi a rămas neatins: ' . $e->getMessage());
        }

        $this->deleteFolder($from);

        return count($tree['files']);
    }

    /** Există deja un fișier cu numele ăsta în folder? */
    public function exists(string $path): bool
    {
        return $this->fileInfo($path) !== null;
    }

    public function deleteFile(string $path): void
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            throw new RuntimeException('Calea fișierului lipsește.');
        }
        $this->request('DELETE', $this->storagePath($path));
    }

    /** Șterge folderul cu tot ce conține (Bunny șterge recursiv la „/" final). */
    public function deleteFolder(string $path): void
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            throw new RuntimeException('Nu pot șterge rădăcina zonei.');
        }
        $this->request('DELETE', $this->storagePath($path) . '/');
    }

    /** Adresa publică pe CDN a unui fișier din zonă. */
    public function publicUrl(string $path): string
    {
        if ($this->cdnUrl === '') {
            return '';
        }
        $path = self::normalizePath($path);
        if ($path === '') {
            return $this->cdnUrl;
        }
        $full = $this->baseFolder === '' ? $path : $this->baseFolder . '/' . $path;
        $encoded = implode('/', array_map('rawurlencode', explode('/', $full)));

        return $this->cdnUrl . '/' . $encoded;
    }

    /**
     * Zonele de storage din cont, citite cu cheia de API a contului.
     * Folosit ca să completăm singuri numele zonei, parola și adresa CDN.
     *
     * @return list<array{name: string, password: string, region: string, cdn_url: string}>
     */
    public static function accountZones(string $apiKey, int $timeout = 20): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new RuntimeException('Cheia de API a contului bunny.net lipsește.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensia cURL lipsește pe server; nu pot contacta bunny.net.');
        }

        $ch = curl_init('https://api.bunny.net/storagezone?page=1&perPage=1000');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'AccessKey: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeout));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Nu am putut contacta bunny.net: ' . ($error !== '' ? $error : 'eroare de rețea'));
        }
        if ($status === 401) {
            throw new RuntimeException('Cheia de API a contului nu este acceptată de bunny.net (401).');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('bunny.net a răspuns cu eroarea ' . $status . ' la citirea zonelor.');
        }

        $decoded = json_decode((string) $raw, true);
        // API-ul poate întoarce fie lista directă, fie un obiect cu „Items".
        $items = is_array($decoded['Items'] ?? null) ? $decoded['Items'] : $decoded;
        if (!is_array($items)) {
            return [];
        }

        $zones = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $zones[] = [
                'name' => $name,
                'password' => trim((string) ($item['Password'] ?? '')),
                'region' => self::regionFromCode((string) ($item['Region'] ?? ''), (string) ($item['StorageHostname'] ?? '')),
                'cdn_url' => self::firstPullZoneUrl($item['PullZones'] ?? null),
            ];
        }

        return $zones;
    }

    /**
     * Numele deja ocupate într-un folder, ca să nu suprascriem la urcare.
     * Se cere o singură dată pentru un lot întreg de fișiere.
     *
     * @return array<string, true>
     */
    public function existingNames(string $folder): array
    {
        try {
            $contents = $this->listPath($folder);
        } catch (RuntimeException) {
            return [];
        }

        $taken = [];
        foreach ([...$contents['folders'], ...$contents['files']] as $item) {
            $taken[strtolower((string) $item['name'])] = true;
        }

        return $taken;
    }

    /**
     * Primul nume liber pornind de la cel cerut: „raport.xlsx", „raport-1.xlsx"…
     *
     * @param array<string, true> $taken
     */
    public static function pickFreeName(string $name, array $taken): string
    {
        if ($name === '' || !isset($taken[strtolower($name)])) {
            return $name;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $suffix = $ext !== '' ? '.' . $ext : '';
        for ($i = 1; $i < 1000; $i++) {
            $candidate = $base . '-' . $i . $suffix;
            if (!isset($taken[strtolower($candidate)])) {
                return $candidate;
            }
        }

        return $base . '-' . substr((string) time(), -6) . $suffix;
    }

    /** Curăță o cale: fără „/" la capete, fără „..", fără segmente goale. */
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /** Nume de fișier/folder acceptat de Bunny, fără diacritice și fără spații. */
    public static function sanitizeName(string $name): string
    {
        $name = trim(str_replace(['\\', '/'], '-', $name));
        $name = strtr($name, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        ]);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
        $name = preg_replace('/-*\.-*/', '.', preg_replace('/-+/', '-', $name) ?? '') ?? '';

        return trim($name, '-.');
    }

    /** „image", „video" sau „file", după extensie. */
    public static function mediaType(string $path): string
    {
        $ext = strtolower(pathinfo((string) (parse_url($path, PHP_URL_PATH) ?? $path), PATHINFO_EXTENSION));
        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }
        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }

        return 'file';
    }

    /** Dimensiune citibilă („1,4 MB"). */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $value >= 10 ? 0 : 1, ',', '.') . ' ' . $units[$index];
    }

    public static function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));
        // Acceptă și hostul întreg lipit din Bunny („ny.storage.bunnycdn.com").
        $region = (string) preg_replace('/\.?storage\.bunnycdn\.com$/', '', $region);

        return array_key_exists($region, self::REGIONS) ? $region : '';
    }

    /** Adresa CDN, normalizată la „https://host" fără „/" final. */
    public static function normalizeCdnUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return rtrim($url, '/');
    }

    /** `https://<regiune>.storage.bunnycdn.com` (în dezvoltare, zona falsă din CMS_DEV_BUNNY_URL; vezi capul clasei). */
    private function adresaStorage(): string
    {
        $fals = trim((string) Env::get('CMS_DEV_BUNNY_URL', ''));
        if ($fals !== '' && (string) Env::get('APP_ENV', '') === 'local' && preg_match('#^http://127\.0\.0\.1:\d+$#', $fals) === 1) {
            return $fals;
        }

        return 'https://' . $this->storageHost();
    }

    /** Calea completă în zonă, codificată pentru URL. */
    private function storagePath(string $path): string
    {
        $path = self::normalizePath($path);
        $full = $this->baseFolder === '' ? $path : ($path === '' ? $this->baseFolder : $this->baseFolder . '/' . $path);
        $encoded = $full === '' ? '' : '/' . implode('/', array_map('rawurlencode', explode('/', $full)));

        return '/' . rawurlencode($this->zone) . $encoded;
    }

    /** @param resource $stream */
    private function put(string $path, $stream, int $size): void
    {
        $path = self::normalizePath($path);
        if ($path === '') {
            throw new RuntimeException('Calea fișierului de urcat lipsește.');
        }
        $this->guardConfig();

        $ch = curl_init($this->adresaStorage() . $this->storagePath($path));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $stream);
        curl_setopt($ch, CURLOPT_INFILESIZE, $size);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'AccessKey: ' . $this->accessKey,
            'Content-Type: application/octet-stream',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(60, $this->timeout));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Nu am putut urca fișierul în Bunny: ' . ($error !== '' ? $error : 'eroare de rețea'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->errorMessage($status, (string) $raw));
        }
    }

    private function request(string $method, string $path): string
    {
        $this->guardConfig();

        $ch = curl_init($this->adresaStorage() . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'AccessKey: ' . $this->accessKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Nu am putut contacta Bunny Storage: ' . ($error !== '' ? $error : 'eroare de rețea'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->errorMessage($status, (string) $raw));
        }

        return (string) $raw;
    }

    private function guardConfig(): void
    {
        if ($this->zone === '' || $this->accessKey === '') {
            throw new RuntimeException('Zona de storage sau cheia de acces bunny.net lipsesc din setări.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensia cURL lipsește pe server; nu pot contacta bunny.net.');
        }
    }

    private function errorMessage(int $status, string $raw): string
    {
        $decoded = json_decode($raw, true);
        $detail = '';
        if (is_array($decoded)) {
            $detail = trim((string) ($decoded['Message'] ?? ($decoded['message'] ?? '')));
        }

        return match (true) {
            $status === 401 => 'Bunny a refuzat cheia de acces a zonei (401). Verifică parola din Storage Zone → FTP & API Access.',
            $status === 404 => 'Bunny nu găsește zona sau folderul cerut (404). Verifică numele zonei și regiunea.',
            default => 'Bunny a răspuns cu eroarea ' . $status . ($detail !== '' ? ': ' . $detail : '.'),
        };
    }

    private static function regionFromCode(string $code, string $storageHostname): string
    {
        if (trim($storageHostname) !== '') {
            return self::normalizeRegion($storageHostname);
        }

        return self::REGION_CODES[strtoupper(trim($code))] ?? '';
    }

    private static function firstPullZoneUrl(mixed $pullZones): string
    {
        if (!is_array($pullZones)) {
            return '';
        }
        foreach ($pullZones as $pullZone) {
            if (!is_array($pullZone)) {
                continue;
            }
            $hostnames = $pullZone['Hostnames'] ?? null;
            if (is_array($hostnames)) {
                // Un hostname propriu (biovitality.ro) e de preferat celui „…b-cdn.net".
                $fallback = '';
                foreach ($hostnames as $hostname) {
                    $value = is_array($hostname) ? trim((string) ($hostname['Value'] ?? '')) : '';
                    if ($value === '') {
                        continue;
                    }
                    if (!str_ends_with(strtolower($value), '.b-cdn.net')) {
                        return self::normalizeCdnUrl($value);
                    }
                    if ($fallback === '') {
                        $fallback = $value;
                    }
                }
                if ($fallback !== '') {
                    return self::normalizeCdnUrl($fallback);
                }
            }
            $name = trim((string) ($pullZone['Name'] ?? ''));
            if ($name !== '') {
                return self::normalizeCdnUrl($name . '.b-cdn.net');
            }
        }

        return '';
    }
}
