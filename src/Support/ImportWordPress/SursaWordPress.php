<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use PDO;
use Throwable;

/**
 * Baza unui site WordPress, DOAR CITITĂ: conexiunea PDO cu datele din wp-config.php (ConfigWordPress), într-o tranzacție
 * `READ ONLY` (o scriere ar da eroare chiar dacă utilizatorul bazei are drepturi depline), cu o imagine consecventă a
 * datelor (`WITH CONSISTENT SNAPSHOT`: tot importul vede baza din aceeași clipă). Codul de aici trimite doar SELECT,
 * SHOW și CHECKSUM TABLE.
 *
 * Opțiunile serializate de WordPress se desfac cu `unserialize(…, ['allowed_classes' => false])`: niciun obiect nu se
 * construiește (un obiect devine __PHP_Incomplete_Class), deci nimic din baza veche nu rulează cod.
 */
final class SursaWordPress
{
    private function __construct(private readonly PDO $pdo, public readonly string $prefix, public readonly ConfigWordPress $config)
    {
    }

    public static function deschide(ConfigWordPress $config, ?PDO $pdo = null): self
    {
        try {
            $pdo ??= new PDO($config->dsn(), $config->constanta('DB_USER'), $config->constanta('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10,
            ]);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT');
        } catch (Throwable $e) {
            throw new EroareImport('baza WordPress ' . $config->descriere() . ' nu răspunde: ' . $config->fara($e->getMessage()));
        }
        $s = new self($pdo, $config->prefix, $config);
        if (!$s->areTabel('options') || !$s->areTabel('posts') || !$s->areTabel('postmeta')) {
            throw new EroareImport('baza ' . $config->descriere() . ' n-are tabelele WordPress ' . $config->prefix . 'options, posts, postmeta');
        }
        return $s;
    }

    /** Numele întreg al unui tabel WordPress (cu prefixul), între backtick-uri. */
    public function t(string $tabel): string
    {
        if (preg_match('/^[a-z0-9_]+$/', $tabel) !== 1) {
            throw new EroareImport('nume de tabel greșit: ' . $tabel);
        }
        return '`' . $this->prefix . $tabel . '`';
    }

    /** @var array<string, bool> */
    private array $tabele = [];

    public function areTabel(string $tabel): bool
    {
        if (!array_key_exists($tabel, $this->tabele)) {
            $q = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $q->execute([$this->prefix . $tabel]);
            $this->tabele[$tabel] = (int) $q->fetchColumn() > 0;
        }
        return $this->tabele[$tabel];
    }

    /** @return list<array<string, mixed>> */
    public function randuri(string $sql, array $parametri = []): array
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($parametri);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function valoare(string $sql, array $parametri = []): mixed
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($parametri);
        $v = $q->fetchColumn();
        return $v === false ? null : $v;
    }

    /** O opțiune WordPress, desfăcută dacă e serializată (fără obiecte), sau $implicit. */
    public function optiune(string $nume, mixed $implicit = null): mixed
    {
        $v = $this->valoare('SELECT option_value FROM ' . $this->t('options') . ' WHERE option_name = ? LIMIT 1', [$nume]);
        return $v === null ? $implicit : self::desface((string) $v);
    }

    /** O valoare serializată de WordPress (maybe_unserialize), fără să construiască obiecte. */
    public static function desface(string $v): mixed
    {
        $t = trim($v);
        if ($t === 'b:0;') {
            return false;
        }
        if ($t !== '' && preg_match('/^(a|O|s|i|d|b|N):/', $t) === 1) {
            $r = @unserialize($t, ['allowed_classes' => false]);
            if ($r !== false) {
                return $r;
            }
        }
        return $v;
    }

    /**
     * Postările de tipurile și stările date, fără conținutul revizuirilor.
     *
     * @param list<string> $tipuri
     * @param list<string>|null $stari null = toate
     * @return list<array<string, mixed>>
     */
    public function postari(array $tipuri, ?array $stari = null): array
    {
        if ($tipuri === []) {
            return [];
        }
        $p = [];
        $sql = 'SELECT ID, post_date, post_date_gmt, post_modified, post_modified_gmt, post_content, post_title, post_excerpt, post_status, '
            . 'post_name, post_parent, menu_order, post_type, post_mime_type, guid, post_password FROM ' . $this->t('posts')
            . ' WHERE post_type IN (' . implode(',', array_fill(0, count($tipuri), '?')) . ')';
        $p = $tipuri;
        if ($stari !== null && $stari !== []) {
            $sql .= ' AND post_status IN (' . implode(',', array_fill(0, count($stari), '?')) . ')';
            $p = array_merge($p, $stari);
        }
        return $this->randuri($sql . ' ORDER BY ID', $p);
    }

    /**
     * Meta-datele postărilor date: [id => [cheie => valoare desfăcută]] (prima valoare a fiecărei chei, ca get_post_meta
     * cu $single). Cu $chei, doar acelea; cheile care încep cu „%” sunt prefixe (`_aioseo_%`).
     *
     * @param list<int> $iduri
     * @param list<string>|null $chei
     * @return array<int, array<string, mixed>>
     */
    public function meta(array $iduri, ?array $chei = null): array
    {
        $rez = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $iduri))), 500) as $bucata) {
            if ($bucata === []) {
                continue;
            }
            $sql = 'SELECT post_id, meta_key, meta_value FROM ' . $this->t('postmeta') . ' WHERE post_id IN (' . implode(',', $bucata) . ')';
            $p = [];
            if ($chei !== null) {
                $conditii = [];
                foreach ($chei as $c) {
                    if (str_ends_with($c, '%')) {
                        $conditii[] = 'meta_key LIKE ?';
                        $p[] = str_replace('_', '\\_', substr($c, 0, -1)) . '%';
                    } else {
                        $conditii[] = 'meta_key = ?';
                        $p[] = $c;
                    }
                }
                $sql .= ' AND (' . implode(' OR ', $conditii) . ')';
            }
            foreach ($this->randuri($sql . ' ORDER BY meta_id', $p) as $r) {
                $id = (int) $r['post_id'];
                $k = (string) $r['meta_key'];
                if (!isset($rez[$id][$k])) {
                    $rez[$id][$k] = self::desface((string) $r['meta_value']);
                }
            }
        }
        return $rez;
    }

    /**
     * Toate valorile unei chei de meta, pentru postările date (de exemplu `_wp_old_slug`, care poate avea mai multe).
     *
     * @param list<int> $iduri
     * @return array<int, list<string>>
     */
    public function metaMultipla(array $iduri, string $cheie): array
    {
        $rez = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $iduri))), 500) as $bucata) {
            if ($bucata === []) {
                continue;
            }
            foreach ($this->randuri('SELECT post_id, meta_value FROM ' . $this->t('postmeta') . ' WHERE meta_key = ? AND post_id IN ('
                . implode(',', $bucata) . ') ORDER BY meta_id', [$cheie]) as $r) {
                $rez[(int) $r['post_id']][] = (string) $r['meta_value'];
            }
        }
        return $rez;
    }

    /**
     * Termenii unei taxonomii (categorii, etichete, meniuri): [term_id => [id, nume, slug, descriere, parinte, numar]].
     *
     * @return array<int, array<string, mixed>>
     */
    public function termeni(string $taxonomie): array
    {
        if (!$this->areTabel('terms') || !$this->areTabel('term_taxonomy')) {
            return [];
        }
        $rez = [];
        foreach ($this->randuri('SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.description, tt.parent, tt.count FROM ' . $this->t('terms')
            . ' t JOIN ' . $this->t('term_taxonomy') . ' tt ON tt.term_id = t.term_id WHERE tt.taxonomy = ? ORDER BY t.term_id', [$taxonomie]) as $r) {
            $rez[(int) $r['term_id']] = ['id' => (int) $r['term_id'], 'tt_id' => (int) $r['term_taxonomy_id'], 'nume' => html_entity_decode((string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'slug' => (string) $r['slug'], 'descriere' => (string) $r['description'], 'parinte' => (int) $r['parent'], 'numar' => (int) $r['count']];
        }
        return $rez;
    }

    /**
     * Postările legate de un termen (prin term_relationships).
     *
     * @return list<int>
     */
    public function postariTermen(int $termTaxonomyId): array
    {
        if (!$this->areTabel('term_relationships')) {
            return [];
        }
        return array_map('intval', array_column($this->randuri('SELECT object_id FROM ' . $this->t('term_relationships')
            . ' WHERE term_taxonomy_id = ? ORDER BY object_id', [$termTaxonomyId]), 'object_id'));
    }

    /** Numărul de rânduri ale unui tabel WordPress care există (altfel 0). */
    public function numar(string $tabel, string $unde = '', array $parametri = []): int
    {
        if (!$this->areTabel($tabel)) {
            return 0;
        }
        return (int) $this->valoare('SELECT COUNT(*) FROM ' . $this->t($tabel) . ($unde !== '' ? ' WHERE ' . $unde : ''), $parametri);
    }

    /**
     * Amprenta conținutului (CHECKSUM TABLE pe tabelele cu conținut): se schimbă când cineva editează o pagină, un meniu, o
     * poză sau SEO-ul în WordPress. Opțiunile (wp_options) nu intră: WordPress le scrie singur tot timpul (cron, tranzitorii).
     *
     * @return array<string, string>
     */
    public function amprentaContinut(): array
    {
        $rez = [];
        foreach (['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'aioseo_posts'] as $t) {
            if (!$this->areTabel($t)) {
                continue;
            }
            $r = $this->randuri('CHECKSUM TABLE ' . $this->t($t));
            $rez[$t] = (string) ($r[0]['Checksum'] ?? '');
        }
        return $rez;
    }
}
