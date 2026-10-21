<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

/**
 * Doar pentru dezvoltare: numărul instrucțiunilor SQL ale unei cereri, pe feluri (docs/MIGRARI.md,
 * „Garda”; testul tools/dev/test-instalare.php). Pornește doar cu variabila de mediu
 * `CMS_DEV_NUMARA_SQL=1` (o pune testul pe serverul lui; pe site-uri nu există, deci
 * `Database::connection` face un PDO obișnuit). Atunci conexiunea aplicației e un ContorSql: fiecare
 * exec/query/execute se numără, iar la sfârșitul cererii se scrie un rând JSON în
 * `$CMS_DEV_DIR/numara-sql.jsonl`: metoda, calea, câte instrucțiuni, câte de schemă (DDL: create,
 * alter, drop, rename, truncate) și începutul fiecăreia (texte din cod, fără valori), câte citiri
 * din information_schema / SHOW.
 */
final class ContorSql extends PDO
{
    public static int $total = 0;
    public static int $ddl = 0;
    public static int $introspectie = 0;
    /** @var list<string> */
    public static array $ddlTexte = [];
    private static bool $laFinal = false;

    public function __construct(string $dsn, ?string $utilizator = null, ?string $parola = null, ?array $optiuni = null)
    {
        parent::__construct($dsn, $utilizator, $parola, $optiuni);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ContorSqlInstructiune::class, []]);
        if (!self::$laFinal) {
            self::$laFinal = true;
            register_shutdown_function([self::class, 'scrie']);
        }
    }

    public function exec(string $statement): int|false
    {
        self::noteaza($statement);
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        self::noteaza($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public static function noteaza(string $sql): void
    {
        self::$total++;
        if (preg_match('/^\s*(?:CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $sql) === 1) {
            self::$ddl++;
            self::$ddlTexte[] = mb_substr((string) preg_replace('/\s+/', ' ', trim($sql)), 0, 100);
        } elseif (preg_match('/\binformation_schema\b|^\s*SHOW\b/i', $sql) === 1) {
            self::$introspectie++;
        }
    }

    /** Rândul cererii, la final (register_shutdown_function). */
    public static function scrie(): void
    {
        $dosar = rtrim((string) (getenv('CMS_DEV_DIR') ?: Paths::stocare('logs/dev')), '/');
        $cale = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        $rand = [
            'metoda' => PHP_SAPI === 'cli' ? 'CLI' : (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'cale' => PHP_SAPI === 'cli' ? (string) ($_SERVER['argv'][0] ?? '') : $cale,
            'cod' => PHP_SAPI === 'cli' ? null : http_response_code(),
            'instructiuni' => self::$total,
            'ddl' => self::$ddl,
            'introspectie' => self::$introspectie,
            'ddl_texte' => array_slice(self::$ddlTexte, 0, 20),
        ];
        @file_put_contents($dosar . '/numara-sql.jsonl', json_encode($rand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX);
    }
}

/** Instrucțiunile pregătite ale lui ContorSql: fiecare execute() se numără. */
final class ContorSqlInstructiune extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        ContorSql::noteaza((string) $this->queryString);
        return parent::execute($params);
    }
}
