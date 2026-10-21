<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ce fel de valoare stă în `admins.password_hash` (G-M5, WP-12b). O singură regulă, folosită de logarea
 * adminului (`Auth::attempt`) și de numărătoarea de pe site-urile live (grupul „admini” din
 * `tools/verificare/seturi/comun.php`, care o scrie în SQL din constantele de aici; `tools/dev/test-sonde.php`
 * verifică, rând cu rând, că SQL-ul și `fel()` spun același lucru).
 *
 * - `hash`: un hash pe care PHP-ul îl recunoaște (`password_get_info()['algo'] !== null`: bcrypt `$2y$` de 60 de
 *   octeți, argon2i, argon2id) și care începe chiar cu `$2y$`, `$argon2i$` sau `$argon2id$` (`password_get_info` nu
 *   se uită la primul caracter al unui argon2: „xargon2id$…” e un text, nu un hash). Se verifică doar cu
 *   `password_verify`; la logare se reface după `deRefacut()`.
 * - `bcrypt_vechi`: bcrypt `$2a$` sau `$2b$` de 60 de octeți. `password_get_info` nu le recunoaște, dar
 *   `password_verify` le verifică, ca și codul de dinainte (care se uita doar la începutul valorii); la prima
 *   logare devin `$2y$`. Fără regula asta, un astfel de admin ar fi ajuns la comparația în clar și n-ar mai fi
 *   intrat deloc (WP-10 le numără separat, `admini.parola_bcrypt_2a_2b`).
 * - `hash_stricat`: are forma unui hash (`TIPAR_HASH`: `$`, numele algoritmului, `$`), dar nu e unul de mai sus:
 *   un bcrypt tăiat sau de altă lungime, argon2 pe un PHP fără argon2, alt algoritm (SHA-crypt `$6$`, MD5-crypt
 *   `$1$`, WordPress `$P$`/`$H$`/`$wp$`, `$2x$`). Nu se compară niciodată în clar (cine vede valoarea în bază ar
 *   intra cu ea) și nici nu se verifică: logarea nu reușește cu nicio parolă, până adminul primește o parolă nouă
 *   (Setări → Administratori). Codul de dinainte le compara în clar, deci nici atunci nu intra cineva cu parola lui.
 * - `gol`: nicio parolă; logarea nu reușește.
 * - `text_clar`: orice altceva, adică o parolă veche salvată necriptat. Se compară în clar (`hash_equals`) și, la
 *   prima logare reușită, se criptează pe loc. Ramura se șterge în R2 (MIG-11), după ce numărătoarea WP-10
 *   (`admini.parola_necunoscuta`) arată 0 pe toate cele patru site-uri.
 *
 * Fișierul nu depinde de nimic altceva: îl încarcă și uneltele de pe server (`tools/verificare/`), fără autoloader.
 */
final class ParolaAdmin
{
    public const HASH = 'hash';
    public const BCRYPT_VECHI = 'bcrypt_vechi';
    public const HASH_STRICAT = 'hash_stricat';
    public const GOL = 'gol';
    public const TEXT_CLAR = 'text_clar';

    /**
     * Forma unui hash („modular crypt”: `$`, numele algoritmului din litere, cifre sau cratimă, `$`). Ce începe așa nu
     * e niciodată comparat în clar. Același text e expresia regulată din PHP (`fel()`) și din SQL (`REGEXP`, în
     * MariaDB și în MySQL 8, pe valoarea trecută în utf8mb4_bin): fără backslash, doar ASCII.
     */
    public const TIPAR_HASH = '^[$][A-Za-z0-9-]{1,32}[$]';

    /**
     * Începuturile hash-urilor pe care `password_get_info` le recunoaște: bcrypt (doar cu `LUNGIME_BCRYPT` octeți) și
     * argon2 (`test-securitate.php` verifică că orice algoritm al PHP-ului de acum începe cu unul dintre ele).
     */
    public const PREFIX_BCRYPT = '$2y$';
    public const PREFIXE_ARGON = ['$argon2i$', '$argon2id$'];

    /** bcrypt-ul de dinainte de `$2y$`: `password_verify` îl știe, `password_get_info` nu. */
    public const PREFIXE_BCRYPT_VECHI = ['$2a$', '$2b$'];

    /** Lungimea unui hash bcrypt întreg, în octeți (aceeași în SQL: `LENGTH`, nu `CHAR_LENGTH`). */
    public const LUNGIME_BCRYPT = 60;

    /** Felul valorii salvate (una dintre constantele de mai sus). */
    public static function fel(string $stocat): string
    {
        if ($stocat === '') {
            return self::GOL;
        }
        // password_get_info citește numele algoritmului de la al doilea caracter, iar la argon2 nu se uită la primul:
        // „xargon2id$…” ar fi un argon2id. Un hash începe deci și cu unul dintre începuturile de mai sus (aceleași ca
        // în SQL-ul numărătorii WP-10).
        if (self::incepeCaHashCunoscut($stocat) && password_get_info($stocat)['algo'] !== null) {
            return self::HASH;
        }
        foreach (self::PREFIXE_BCRYPT_VECHI as $prefix) {
            if (str_starts_with($stocat, $prefix) && strlen($stocat) === self::LUNGIME_BCRYPT) {
                return self::BCRYPT_VECHI;
            }
        }
        if (preg_match('/' . self::TIPAR_HASH . '/', $stocat) === 1) {
            return self::HASH_STRICAT;
        }
        return self::TEXT_CLAR;
    }

    /** Începe valoarea cu `PREFIX_BCRYPT` sau cu unul dintre `PREFIXE_ARGON` (comparat ca octeți)? */
    private static function incepeCaHashCunoscut(string $stocat): bool
    {
        foreach (array_merge([self::PREFIX_BCRYPT], self::PREFIXE_ARGON) as $prefix) {
            if (str_starts_with($stocat, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * După o logare reușită cu un hash (`hash` sau `bcrypt_vechi`): trebuie refăcut cu `PASSWORD_DEFAULT`? Da pentru
     * bcrypt-ul vechi și pentru ce cere `password_needs_rehash($stocat, PASSWORD_DEFAULT)` (alt algoritm, de exemplu
     * argon2, sau un bcrypt cu cost mai mic decât cel implicit al PHP-ului de acum). Nu când e chiar algoritmul
     * implicit cu un cost cel puțin cât cel implicit: un bcrypt cu costul 12 (PHP 8.4) servit de PHP 8.3 (implicit
     * 10) rămâne cum e, nu coboară. O parolă salvată necriptat se criptează oricum (`Auth::attempt`), nu de aici.
     */
    public static function deRefacut(string $stocat): bool
    {
        $fel = self::fel($stocat);
        if ($fel === self::BCRYPT_VECHI) {
            return true;
        }
        if ($fel !== self::HASH || !password_needs_rehash($stocat, PASSWORD_DEFAULT)) {
            return false;
        }
        $info = password_get_info($stocat);
        if (PASSWORD_DEFAULT === PASSWORD_BCRYPT && $info['algo'] === PASSWORD_BCRYPT) {
            return (int) ($info['options']['cost'] ?? 0) < PASSWORD_BCRYPT_DEFAULT_COST;
        }
        return true;
    }
}
