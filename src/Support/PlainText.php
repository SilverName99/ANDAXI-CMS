<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Text curat dintr-un conținut HTML.
 *
 * Editorul de articole scrie `&nbsp;` pentru spațiul dublu sau pentru cel de la
 * capăt de rând — e HTML corect, dar `strip_tags` îl lasă neatins, iar dacă
 * textul trece apoi prin `htmlspecialchars` cititorul vede chiar „&nbsp;”.
 * De aceea decodăm entitățile înainte de a normaliza spațiile.
 */
final class PlainText
{
    public static function fromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Fără asta, sfârșitul unui paragraf lipește ultimul cuvânt de primul din următorul.
        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/blockquote)\b[^>]*>#i', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // `&nbsp;` a devenit U+00A0, pe care `\s` nu îl prinde; îl aducem la spațiu normal.
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** Textul de mai sus, tăiat la `$limit` caractere, cu „…” dacă a rămas ceva pe dinafară. */
    public static function excerpt(string $html, int $limit): string
    {
        $text = self::fromHtml($html);
        if ($limit <= 0 || $text === '') {
            return $text;
        }

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
}
