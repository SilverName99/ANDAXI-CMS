<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Pluginul `fisiere_cloud` în restul adminului (docs/PLUGINURI.md, „Fișiere în cloud”): încărcările din admin (Galerie, produse,
 * blog …) direct în bunny.net, când Setări → bunny.net spune „În bunny.net (CDN)” (din Biovitality, `uploadMediaToBunny` al lui
 * AdminController la d95598e). Cu pluginul ascuns nu se cheamă (AdminController::handleMediaUpload întreabă întâi), deci pe
 * celelalte site-uri încărcările rămân pe server, ca înainte.
 */
final class FisiereCloud
{
    /**
     * Urcă fișierul în Bunny, dacă integrarea e pornită și setată pe „CDN”. Întoarce adresa publică de pe CDN și numele sub care a
     * rămas, sau null dacă nu e cazul sau dacă Bunny nu l-a primit (apelantul îl pune atunci pe discul serverului: încărcarea nu
     * se pierde).
     *
     * @return array{url: string, nume: string}|null
     */
    public static function urcaMedia(?PDO $db, string $tmpPath, string $namespace, string $filename): ?array
    {
        $settings = Settings::all($db);
        if (!BunnyStorage::isEnabled($settings)) {
            return null;
        }
        if ((string) ($settings['bunny_upload_target'] ?? 'local') !== 'bunny') {
            return null;
        }

        $client = BunnyStorage::fromSettings($settings);
        if ($client === null) {
            return null;
        }

        $folder = BunnyStorage::normalizePath(
            (string) ($settings['bunny_gallery_folder'] ?? 'galerie') . '/' . $namespace
        );
        $name = BunnyStorage::pickFreeName(BunnyStorage::sanitizeName($filename), $client->existingNames($folder));
        if ($name === '') {
            return null;
        }

        $path = $folder === '' ? $name : $folder . '/' . $name;
        try {
            $client->uploadFile($path, $tmpPath);
        } catch (Throwable) {
            return null;
        }

        $url = $client->publicUrl($path);
        if ($url === '') {
            return null;
        }

        return ['url' => $url, 'nume' => $name];
    }
}
