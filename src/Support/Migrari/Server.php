<?php

declare(strict_types=1);

namespace App\Support\Migrari;

use PDO;

/**
 * Serverul de baze de date: familia (MariaDB sau MySQL), versiunea și ce știe să facă
 * fără să blocheze (docs/MIGRARI.md, „Serverele”).
 *
 * Pe Hostinger familia și versiunea nu sunt cunoscute dinainte (le citește sonda), deci
 * nimic din runner nu presupune una: fiecare ALTER pleacă cu un ALGORITHM explicit, iar
 * serverul spune singur „nu se poate” (1845/1846) în loc să copieze tabelul pe ascuns.
 *
 * Minim: MariaDB 10.3 sau MySQL 8.0. Sub ele runner-ul iese cu 2.
 */
final class Server
{
    public const MARIADB = 'mariadb';
    public const MYSQL = 'mysql';

    // Fără `readonly` (PHP 8.1): scris când PHP-ul minim era 8.0 (acum 8.3, decizia OD-48).
    private function __construct(
        private string $familie,
        private string $versiune,
        private string $text,
    ) {
    }

    public function familie(): string
    {
        return $this->familie;
    }

    /** „10.11.14”, „8.0.46” (fără sufixul distribuției). */
    public function versiune(): string
    {
        return $this->versiune;
    }

    /** Textul întreg al lui SELECT VERSION() (se scrie în _platforma_migrari.server_versiune). */
    public function text(): string
    {
        return $this->text;
    }

    public static function detecteaza(PDO $db): self
    {
        return self::dinVersiune((string) $db->query('SELECT VERSION()')->fetchColumn());
    }

    /**
     * Din textul lui SELECT VERSION(): „10.11.14-MariaDB-0ubuntu0.24.04.1”, „8.0.46-0ubuntu…”,
     * „5.5.5-10.3.39-MariaDB” (prefixul de compatibilitate al unor clienți vechi), „8.4.3”.
     */
    public static function dinVersiune(string $text): self
    {
        $text = trim($text);
        $familie = stripos($text, 'mariadb') !== false ? self::MARIADB : self::MYSQL;
        $curat = preg_replace('/^5\.5\.5-/', '', $text) ?? $text;
        $versiune = preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $curat, $m) === 1
            ? $m[1] . '.' . $m[2] . '.' . ($m[3] ?? '0')
            : '0.0.0';
        return new self($familie, $versiune, $text);
    }

    public function esteMariaDb(): bool
    {
        return $this->familie === self::MARIADB;
    }

    public function minim(string $versiune): bool
    {
        return version_compare($this->versiune, $versiune, '>=');
    }

    /** MariaDB ≥ 10.3 sau MySQL ≥ 8.0. */
    public function suportat(): bool
    {
        return $this->esteMariaDb() ? $this->minim('10.3.0') : $this->minim('8.0.0');
    }

    public function nume(): string
    {
        return ($this->esteMariaDb() ? 'MariaDB ' : 'MySQL ') . $this->versiune;
    }

    /** ADD COLUMN la coadă, fără copiere: MariaDB 10.3.2, MySQL 8.0.12. */
    public function coloanaNouaInstant(): bool
    {
        return $this->esteMariaDb() ? $this->minim('10.3.2') : $this->minim('8.0.12');
    }

    /** DROP COLUMN fără copiere: MariaDB 10.4, MySQL 8.0.29. */
    public function stergereColoanaInstant(): bool
    {
        return $this->esteMariaDb() ? $this->minim('10.4.0') : $this->minim('8.0.29');
    }

    /**
     * Clauzele încercate pe rând pentru o operație (fiecare se lipește la ALTER cu „, ”).
     * Șirul gol = fără clauză, adică „cum vrea serverul”, deci posibil o copie a tabelului
     * cu scrierile oprite: doar dacă migrarea are voie să blocheze.
     *
     * @param string $operatie coloana | index | sterge_index | sterge_coloana | modifica
     * @return list<string>
     */
    public function lantClauze(string $operatie, bool $poateBloca): array
    {
        $lant = match ($operatie) {
            'coloana' => $this->coloanaNouaInstant()
                ? ['ALGORITHM=INSTANT', 'ALGORITHM=INPLACE, LOCK=NONE']
                : ['ALGORITHM=INPLACE, LOCK=NONE'],
            'sterge_coloana' => $this->stergereColoanaInstant()
                ? ['ALGORITHM=INSTANT', 'ALGORITHM=INPLACE, LOCK=NONE']
                : ['ALGORITHM=INPLACE, LOCK=NONE'],
            'modifica' => ['ALGORITHM=INSTANT', 'ALGORITHM=INPLACE, LOCK=NONE'],
            default => ['ALGORITHM=INPLACE, LOCK=NONE'],
        };
        if ($poateBloca) {
            $lant[] = '';
        }
        return $lant;
    }

    /**
     * Cum clasează planul o operație înainte s-o încerce (o estimare: serverul are ultimul
     * cuvânt, prin lanțul de clauze).
     */
    public function clasaEstimata(string $operatie): string
    {
        return match ($operatie) {
            'coloana' => $this->coloanaNouaInstant() ? 'instant' : 'inplace',
            'sterge_coloana' => $this->stergereColoanaInstant() ? 'instant' : 'inplace',
            'modifica' => 'copie',
            default => 'inplace',
        };
    }
}
