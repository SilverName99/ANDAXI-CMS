<?php

declare(strict_types=1);

namespace App\Support;

final class View
{
    /** Vederea randată acum (de exemplu „admin/page-editor”), pentru aspectul paginii. */
    private static string $vedereCurenta = '';

    public static function render(string $template, array $data = [], string $layout = 'layout'): void
    {
        $templateFile = __DIR__ . '/../../views/' . $template . '.php';
        $layoutFile = __DIR__ . '/../../views/' . $layout . '.php';

        if (!is_file($templateFile) || !is_file($layoutFile)) {
            http_response_code(500);
            echo 'Template not found.';
            return;
        }

        self::$vedereCurenta = $template;

        extract($data, EXTR_SKIP);

        ob_start();
        require $templateFile;
        $content = ob_get_clean();

        require $layoutFile;
    }

    /**
     * Un text ca șir JavaScript între apostrofuri, sigur într-un <script> („'Supliment natural'”): \, ', rândurile noi
     * și separatorii de rând Unicode scăpați, iar „<” scris \x3C (nimic nu poate închide scriptul).
     */
    public static function sirJs(string $text): string
    {
        return "'" . strtr($text, [
            '\\' => '\\\\', "'" => "\\'", "\n" => '\\n', "\r" => '\\r', '<' => '\\x3C', "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029',
        ]) . "'";
    }

    /**
     * Vederea pe care o îmbracă aspectul (`views/admin/layout.php` încarcă editoarele de cod doar pe
     * paginile care le folosesc, WP-12 C6). Nu se citește din variabila `$template` a aspectului: o
     * vedere o poate refolosi (de exemplu `foreach ($templates as $template)` în editorul postării).
     */
    public static function vedereaCurenta(): string
    {
        return self::$vedereCurenta;
    }
}
