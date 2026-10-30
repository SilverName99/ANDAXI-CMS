<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Poarta conținutului pentru membri, în nucleu (WP-24: VT-X2, port-vertij §4.2). O pagină sau un articol cu
 * `requires_active_account = 1` (migrarea nucleu/0034) se vede așa:
 *
 *  | pluginul `membri`         | cine se uită                                   | răspuns                                   |
 *  |---------------------------|------------------------------------------------|-------------------------------------------|
 *  | oprit sau lipsă           | oricine (și foștii membri)                      | 404 (pagina 404 a site-ului; API: JSON 404)|
 *  | pornit sau se oprește     | un membru activ (`continut.membru_activ`)       | pagina, normal                            |
 *  | pornit sau se oprește     | oricine altcineva                               | nota „acces restricționat”: 403, noindex  |
 *
 * Fără niciun rând restricționat (site-urile fără membri) nu se schimbă nimic. În liste: cu pluginul oprit
 * rândurile restricționate lipsesc (niciun card spre o pagină 404); cu el vizibil, la cine nu e membru activ rămân doar
 * titlul și rezumatul scris (textul se golește, `is_locked`). În SQL (`conditieSql`): aceeași excludere, direct în
 * interogare (numărătoarea paginilor e corectă). Harta site-ului le exclude mereu, evenimentul următor la fel.
 *
 * Pe o bază încă nemigrată (codul nou urcat înainte de `migreaza.php --aplica`) coloanele lipsesc: totul se poartă ca
 * fără rânduri restricționate, fără nicio interogare care să cadă.
 */
final class AccesMembri
{
    public const LIBER = 'liber';
    public const NOTA = 'nota';
    public const NEGASIT = '404';

    /** @var array<string, bool> tabel => are coloana (o dată pe cerere) */
    private static array $areColoana = [];
    /** @var array<string, bool>|null căile restricționate („/<slug pagină>”, „/blog/<slug>”), o dată pe cerere */
    private static ?array $cai = null;
    private static ?bool $membruActiv = null;

    /** Rândul (o pagină sau un articol) e doar pentru membri? */
    public static function restrictionat(array $rand): bool
    {
        return (int) ($rand['requires_active_account'] ?? 0) === 1;
    }

    /** Pluginul `membri` e vizibil (pornit sau se oprește)? */
    public static function membriPeSite(): bool
    {
        return Plugins::vizibil('membri');
    }

    /**
     * Ce primește vizitatorul de acum pentru acest rând: `liber`, `nota` (403 cu explicația) sau `404`.
     */
    public static function decizie(?PDO $db, array $rand): string
    {
        if (!self::restrictionat($rand)) {
            return self::LIBER;
        }
        if (!self::membriPeSite()) {
            return self::NEGASIT;
        }
        return self::membruActiv($db) ? self::LIBER : self::NOTA;
    }

    /** Vizitatorul de acum e un client logat cu cont activ (după pluginul `membri`)? O dată pe cerere. */
    public static function membruActiv(?PDO $db): bool
    {
        if (self::$membruActiv !== null) {
            return self::$membruActiv;
        }
        $utilizator = $db instanceof PDO ? CustomerAuth::user($db) : null;
        return self::$membruActiv = is_array($utilizator) && Hooks::oricare('continut.membru_activ', $db, $utilizator);
    }

    /**
     * O listă de articole (sau pagini) pentru vizitatorul de acum: cu `membri` oprit, fără cele restricționate; cu el
     * vizibil, la cine nu e membru activ, cele restricționate fără text (`content`, `content_text` goale, `is_locked`).
     *
     * @param array<int, mixed> $randuri
     * @return array<int, mixed>
     */
    public static function filtreazaLista(?PDO $db, array $randuri): array
    {
        $are = false;
        foreach ($randuri as $rand) {
            if (is_array($rand) && self::restrictionat($rand)) {
                $are = true;
                break;
            }
        }
        if (!$are) {
            return $randuri;
        }
        if (!self::membriPeSite()) {
            return array_values(array_filter($randuri, static fn ($rand): bool => !is_array($rand) || !self::restrictionat($rand)));
        }
        if (self::membruActiv($db)) {
            return $randuri;
        }
        foreach ($randuri as $i => $rand) {
            if (is_array($rand) && self::restrictionat($rand)) {
                $randuri[$i]['content'] = '';
                if (array_key_exists('content_text', $rand)) {
                    $randuri[$i]['content_text'] = '';
                }
                $randuri[$i]['is_locked'] = true;
            }
        }
        return $randuri;
    }

    /**
     * Condiția SQL care scoate rândurile restricționate (alias-ul tabelului, de exemplu „p”): cu `membri` oprit, sau
     * mereu (`$mereu`: harta site-ului, evenimentul următor). Gol când nu e nimic de scos sau baza n-are coloana.
     */
    public static function conditieSql(?PDO $db, string $alias, string $tabel = 'blog_posts', bool $mereu = false): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $alias) !== 1 || !self::areColoana($db, $tabel)) {
            return '';
        }
        if (!$mereu && self::membriPeSite()) {
            return '';
        }
        return ' AND ' . $alias . '.requires_active_account = 0';
    }

    /**
     * Coloana de selectat într-o listă (pentru `filtreazaLista`): `, p.requires_active_account` sau, pe o bază
     * nemigrată, `, 0 AS requires_active_account`.
     */
    public static function coloanaSql(?PDO $db, string $alias, string $tabel = 'blog_posts'): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $alias) !== 1) {
            return '';
        }
        return self::areColoana($db, $tabel)
            ? ', ' . $alias . '.requires_active_account'
            : ', 0 AS requires_active_account';
    }

    /** Baza are coloana pe tabelul ăsta (`pages`, `blog_posts`)? O dată pe cerere. */
    public static function areColoana(?PDO $db, string $tabel): bool
    {
        if (!$db instanceof PDO || !in_array($tabel, ['pages', 'blog_posts'], true)) {
            return false;
        }
        if (!isset(self::$areColoana[$tabel])) {
            try {
                $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabel AND COLUMN_NAME = :coloana');
                $stmt->execute(['tabel' => $tabel, 'coloana' => 'requires_active_account']);
                self::$areColoana[$tabel] = (int) $stmt->fetchColumn() > 0;
            } catch (Throwable) {
                self::$areColoana[$tabel] = false;
            }
        }
        return self::$areColoana[$tabel];
    }

    /**
     * O adresă a site-ului („/pagina”, „/blog/articol”) duce la un rând restricționat? Pentru `Plugins::adresaVizibila`:
     * cu `membri` oprit, linkurile spre ele (meniul, subsolul, textul paginilor) dispar. O dată pe cerere.
     */
    public static function caleRestrictionata(string $cale): bool
    {
        self::citesteCai();
        if (self::$cai === null || self::$cai === []) {
            return false;
        }
        $cale = strtolower(rtrim(rawurldecode($cale), '/'));
        return isset(self::$cai[$cale]);
    }

    /** Are site-ul măcar o pagină sau un articol doar pentru membri (nesters)? */
    public static function areCaiRestrictionate(): bool
    {
        self::citesteCai();
        return self::$cai !== null && self::$cai !== [];
    }

    private static function citesteCai(): void
    {
        if (self::$cai === null) {
            $db = Database::connection([]);
            if (!$db instanceof PDO) {
                return; // încă fără bază: nimic restricționat acum, se citește la prima întrebare de după conectare
            }
            self::$cai = [];
            foreach (['pages' => '/', 'blog_posts' => '/blog/'] as $tabel => $prefix) {
                if (!self::areColoana($db, $tabel)) {
                    continue;
                }
                try {
                    $stmt = $db->query('SELECT slug FROM ' . $tabel . ' WHERE requires_active_account = 1 AND deleted_at IS NULL');
                    foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $slug) {
                        $slug = trim((string) $slug, '/');
                        if ($slug !== '') {
                            self::$cai[strtolower($prefix . $slug)] = true;
                        }
                    }
                } catch (Throwable) {
                    // Fără listă: nicio adresă nu e tratată ca restricționată.
                }
            }
        }
    }

    /**
     * HTML-ul paginii „acces restricționat” (403): al pluginului `membri` (`continut.nota`), altfel o notă neutră a
     * nucleului (titlul, explicația, intrarea în cont și crearea unui cont).
     */
    public static function nota(?PDO $db, string $titluContinut): string
    {
        $utilizator = $db instanceof PDO ? CustomerAuth::user($db) : null;
        $html = Hooks::html('continut.nota', $db, $utilizator, $titluContinut);
        if (trim($html) !== '') {
            return $html;
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $actiuni = is_array($utilizator)
            ? '<a class="acces-restrictionat__actiune" href="/contul-meu">Contul meu</a>'
            : '<a class="acces-restrictionat__actiune" href="/login">Intră în cont</a>'
                . '<a class="acces-restrictionat__actiune" href="/register">Creează un cont</a>';
        return '<section class="acces-restrictionat" style="display:flex;justify-content:center;padding:56px 18px;">'
            . '<div style="max-width:620px;width:100%;text-align:center;">'
            . '<h1>Conținut pentru membri</h1>'
            . '<p>Această pagină se vede doar cu un cont de membru activ.</p>'
            . (trim($titluContinut) !== '' ? '<p>Pagina cerută: <strong>' . $e(trim($titluContinut)) . '</strong></p>' : '')
            . '<p style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">' . $actiuni . '</p>'
            . '</div></section>';
    }

    /** Doar pentru teste și pentru linia de comandă care servește mai multe cereri: uită ce s-a citit. */
    public static function reseteaza(): void
    {
        self::$areColoana = [];
        self::$cai = null;
        self::$membruActiv = null;
    }
}
