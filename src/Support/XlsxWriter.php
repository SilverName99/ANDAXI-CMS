<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;
use ZipArchive;

/**
 * Scriitor minimal de fișiere .xlsx, fără biblioteci externe (WP-21: C13, din Biovitality 2ebc0cb și 7ee1a86, în nucleu:
 * exportul produselor promoționale îl folosește acum, analiza concurenței la portarea ei).
 *
 * Un .xlsx e o arhivă ZIP cu câteva fișiere XML. Aici scriem exact atât cât
 * trebuie pentru un export tabelar: mai multe foi, fiecare cu un rând de antet
 * îngroșat și rânduri de date.
 *
 * Textul se scrie ca `inlineStr`, nu prin tabela de șiruri partajate: e mai
 * mult ca volum, dar nu se poate strica la diacritice și nu cere o a doua
 * trecere peste date.
 */
final class XlsxWriter
{
    /** @var list<array{name: string, columns: list<array{label: string, width?: float}>, rows: list<list<string|int|float|null>>}> */
    private array $sheets = [];

    /**
     * @param list<array{label: string, width?: float}> $columns
     * @param list<list<string|int|float|null>> $rows
     */
    public function addSheet(string $name, array $columns, array $rows): void
    {
        $this->sheets[] = [
            'name' => self::numeFoaie($name, count($this->sheets) + 1),
            'columns' => array_values($columns),
            'rows' => array_values($rows),
        ];
    }

    /** De ce n-a mers ultima construcție, în cuvinte de spus operatorului. */
    private string $ultimaEroare = '';

    public function ultimaEroare(): string
    {
        return $this->ultimaEroare;
    }

    /**
     * Scrie fișierul pe disc și întoarce calea, sau null dacă n-a mers.
     *
     * Se lucrează pe fișier, nu în memorie: un export de câteva sute de produse
     * ori zece parteneri are zeci de mii de celule, iar ținerea arhivei întregi
     * într-un șir de caractere e exact felul în care exportul cade cu „memoria
     * epuizată" pe cataloage mari, fără să spună de ce.
     */
    public function scrieFisier(): ?string
    {
        $this->ultimaEroare = '';

        if ($this->sheets === []) {
            $this->ultimaEroare = 'Nu era nimic de pus în fișier.';
            return null;
        }
        if (!class_exists(ZipArchive::class)) {
            $this->ultimaEroare = 'Extensia PHP „zip" nu este activă pe server. Fără ea nu se poate scrie un fișier Excel.';
            return null;
        }

        $tempPath = self::caleTemporara();
        if ($tempPath === null) {
            $this->ultimaEroare = 'Serverul nu are unde scrie fișierul temporar. '
                . 'Verifică drepturile pe folderul temporar (' . sys_get_temp_dir() . ').';
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempPath);
            $this->ultimaEroare = 'Nu am putut deschide arhiva temporară pentru scriere.';
            return null;
        }

        try {
            $ok = $zip->addFromString('[Content_Types].xml', $this->contentTypesXml())
                && $zip->addFromString('_rels/.rels', self::rootRelsXml())
                && $zip->addFromString('xl/workbook.xml', $this->workbookXml())
                && $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml())
                && $zip->addFromString('xl/styles.xml', self::stylesXml());

            foreach ($this->sheets as $index => $sheet) {
                // Foaia se construiește și se scrie una câte una, apoi se uită:
                // altfel toate ar sta deodată în memorie.
                $xml = self::sheetXml($sheet['columns'], $sheet['rows']);
                $ok = $ok && $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $xml);
                unset($xml);
            }
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($tempPath);
            $this->ultimaEroare = 'Scrierea foilor a eșuat: ' . $e->getMessage();
            return null;
        }

        if (!$zip->close() || !$ok) {
            @unlink($tempPath);
            $this->ultimaEroare = 'Nu am putut închide arhiva. Verifică spațiul liber pe disc.';
            return null;
        }

        if (!is_file($tempPath) || filesize($tempPath) === 0) {
            @unlink($tempPath);
            $this->ultimaEroare = 'Fișierul a ieșit gol.';
            return null;
        }

        return $tempPath;
    }

    /** Conținutul binar al fișierului, sau null dacă nu poate fi construit. */
    public function build(): ?string
    {
        $tempPath = $this->scrieFisier();
        if ($tempPath === null) {
            return null;
        }

        $binary = @file_get_contents($tempPath);
        @unlink($tempPath);

        if (!is_string($binary) || $binary === '') {
            $this->ultimaEroare = 'Nu am putut citi înapoi fișierul scris.';
            return null;
        }

        return $binary;
    }

    /**
     * Trimite fișierul către browser ca descărcare.
     *
     * Două lucruri, învățate pe pielea noastră:
     *
     * 1. Orice s-a scris până acum — un avertisment PHP, un antet de pagină, un
     *    buffer de cache rămas deschis — ajunge lipit în fața arhivei, iar
     *    Excel refuză fișierul spunând doar „format nevalid". De aceea se
     *    golește tot ce e deschis înainte de primul octet.
     * 2. `Content-Disposition` se trimite o singură dată. Două antete cu același
     *    nume se unesc cu virgulă într-o valoare pe care browserele n-o mai
     *    înțeleg, iar descărcarea fie primește un nume aiurea, fie e afișată în
     *    pagină ca o grămadă de simboluri.
     */
    public function trimite(string $filename): bool
    {
        $tempPath = $this->scrieFisier();
        if ($tempPath === null) {
            return false;
        }

        $marime = (int) filesize($tempPath);

        if (headers_sent()) {
            @unlink($tempPath);
            $this->ultimaEroare = 'Serverul trimisese deja conținut către browser, '
                . 'așa că descărcarea ar fi ieșit stricată.';
            return false;
        }

        while (ob_get_level() > 0) {
            if (@ob_end_clean() === false) {
                break;
            }
        }
        // Compresia pornită automat ar face ca `Content-Length` să nu mai
        // corespundă, iar descărcarea s-ar tăia la jumătate.
        @ini_set('zlib.output_compression', '0');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; ' . self::antetNumeFisier($filename));
        header('Content-Length: ' . (string) $marime);
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Expires: 0');
        header('Pragma: no-cache');

        $trimis = @readfile($tempPath);
        @unlink($tempPath);

        if ($trimis === false) {
            $this->ultimaEroare = 'Fișierul s-a scris, dar nu a putut fi trimis către browser.';
            return false;
        }

        return true;
    }

    /**
     * Numele fișierului în forma cerută de RFC 6266: o variantă simplă, pe care
     * o înțelege orice browser, plus una cu diacritice pentru cele moderne.
     */
    private static function antetNumeFisier(string $filename): string
    {
        $simplu = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        $simplu = trim($simplu, '-');
        if ($simplu === '') {
            $simplu = 'export.xlsx';
        }

        return 'filename="' . $simplu . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    /**
     * Un fișier temporar de lucru, cu rezervă (stocarea site-ului) dacă folderul de sistem e blocat. Niciodată sub
     * public/: codul Biovitality încerca și public/uploads, de unde un export rămas din greșeală s-ar fi putut descărca.
     */
    private static function caleTemporara(): ?string
    {
        foreach ([sys_get_temp_dir(), Paths::stocare()] as $folder) {
            if (!is_dir($folder) || !is_writable($folder)) {
                continue;
            }
            $cale = @tempnam($folder, 'xlsx-');
            if (is_string($cale) && $cale !== '') {
                return $cale;
            }
        }

        return null;
    }

    // ───────────────────────────────────────────────────────────
    // Intern
    // ───────────────────────────────────────────────────────────

    /**
     * Excel refuză numele de foaie mai lungi de 31 de caractere sau care conțin
     * `: \ / ? * [ ]`, și nu deschide deloc fișierul dacă le găsește.
     */
    private static function numeFoaie(string $name, int $pozitie): string
    {
        $curat = str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', trim($name));
        $curat = trim((string) preg_replace('/\s+/u', ' ', $curat));
        if ($curat === '') {
            return 'Foaie' . $pozitie;
        }
        return function_exists('mb_substr') ? mb_substr($curat, 0, 31) : substr($curat, 0, 31);
    }

    /** @param list<array{label: string, width?: float}> $columns */
    /** @param list<list<string|int|float|null>> $rows */
    private static function sheetXml(array $columns, array $rows): string
    {
        $lastColumn = self::numeColoana(max(1, count($columns)));
        $lastRow = max(1, count($rows) + 1);

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml[] = '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>';
        // Antetul rămâne vizibil la derulare — exporturile astea au sute de rânduri.
        $xml[] = '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>';
        $xml[] = '<sheetFormatPr defaultRowHeight="16"/>';

        if ($columns !== []) {
            $xml[] = '<cols>';
            foreach ($columns as $index => $column) {
                $col = $index + 1;
                $width = number_format((float) ($column['width'] ?? 18.0), 2, '.', '');
                $xml[] = '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml[] = '</cols>';
        }

        $xml[] = '<sheetData>';
        $xml[] = '<row r="1" ht="20" customHeight="1">';
        foreach ($columns as $index => $column) {
            $xml[] = self::celula($index + 1, 1, (string) ($column['label'] ?? ''), 1);
        }
        $xml[] = '</row>';

        foreach ($rows as $rowIndex => $values) {
            $sheetRow = $rowIndex + 2;
            $xml[] = '<row r="' . $sheetRow . '">';
            foreach (array_keys($columns) as $colIndex) {
                $xml[] = self::celula($colIndex + 1, $sheetRow, $values[$colIndex] ?? null, 0);
            }
            $xml[] = '</row>';
        }

        $xml[] = '</sheetData>';
        $xml[] = '</worksheet>';

        return implode('', $xml);
    }

    /** O celulă: numerele intră ca numere, ca să se poată aduna în Excel. */
    private static function celula(int $col, int $row, string|int|float|null $value, int $styleId): string
    {
        $ref = self::numeColoana($col) . $row;
        $stil = $styleId > 0 ? ' s="' . $styleId . '"' : '';

        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '"' . $stil . '><v>' . rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') . '</v></c>';
        }

        $text = (string) ($value ?? '');
        if ($text === '') {
            return '<c r="' . $ref . '"' . $stil . '/>';
        }

        return '<c r="' . $ref . '"' . $stil . ' t="inlineStr"><is><t xml:space="preserve">'
            . self::escapeXml($text) . '</t></is></c>';
    }

    private static function numeColoana(int $index): string
    {
        $nume = '';
        while ($index > 0) {
            $rest = ($index - 1) % 26;
            $nume = chr(65 + $rest) . $nume;
            $index = (int) (($index - $rest - 1) / 26);
        }
        return $nume !== '' ? $nume : 'A';
    }

    private static function escapeXml(string $value): string
    {
        // Caracterele de control nu sunt valide în XML și strică fișierul.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml[] = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml[] = '<Default Extension="xml" ContentType="application/xml"/>';
        $xml[] = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        foreach (array_keys($this->sheets) as $index) {
            $xml[] = '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml[] = '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml[] = '</Types>';

        return implode('', $xml);
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
            . 'Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml[] = '<sheets>';
        foreach ($this->sheets as $index => $sheet) {
            $xml[] = '<sheet name="' . self::escapeXml($sheet['name']) . '" '
                . 'sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }
        $xml[] = '</sheets>';
        $xml[] = '</workbook>';

        return implode('', $xml);
    }

    private function workbookRelsXml(): string
    {
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach (array_keys($this->sheets) as $index) {
            $xml[] = '<Relationship Id="rId' . ($index + 1) . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }
        // Stilurile primesc un id după toate foile, ca să nu se suprapună.
        $xml[] = '<Relationship Id="rId' . (count($this->sheets) + 1) . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/>';
        $xml[] = '</Relationships>';

        return implode('', $xml);
    }

    /** Două stiluri: 0 = normal, 1 = antet îngroșat pe fundal gri. */
    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF2F5"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
