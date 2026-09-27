<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

/**
 * Reads the first sheet of an .xlsx workbook, or a .csv file, into rows of plain text.
 *
 * Every cell comes back as the string it holds, never a number, so an account number keeps its
 * leading zeros. For .xlsx that means: text cells as written; number cells from their stored
 * digits (not the displayed value), with scientific notation expanded back to digits and a
 * zero-padded number format ("0000000000") re-applied. Uses only PHP's zip and DOM extensions.
 */
class SpreadsheetReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Refuse to inflate any one part of a workbook past this — a guard against zip bombs. */
    private const MAX_PART_BYTES = 50 * 1024 * 1024;

    /**
     * @return list<array{row: int, cells: list<string>}> each non-blank row with its 1-based
     *                                                    row number as the sheet shows it
     *
     * @throws RuntimeException when the file cannot be read as the given type
     */
    public function read(string $path, string $extension): array
    {
        return strtolower($extension) === 'xlsx' ? $this->readXlsx($path) : $this->readCsv($path);
    }

    /** @return list<array{row: int, cells: list<string>}> */
    private function readCsv(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('The file could not be read.');
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        // Excel on Windows saves CSV as Windows-1252 unless told otherwise.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];
        $number = 0;
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $number++;
            $cells = array_map(fn ($c) => (string) $c, $cells);
            if ($this->isBlank($cells)) {
                continue;
            }
            $rows[] = ['row' => $number, 'cells' => $cells];
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<array{row: int, cells: list<string>}> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The file is not a valid Excel (.xlsx) workbook.');
        }

        try {
            $sheet = $this->xml($zip, $this->firstSheetPath($zip));
            if ($sheet === null) {
                throw new RuntimeException('The workbook has no worksheet.');
            }

            $strings = $this->sharedStrings($zip);
            $padding = $this->zeroPadding($zip);

            $rows = [];
            foreach ($sheet->getElementsByTagNameNS(self::MAIN_NS, 'row') as $index => $rowEl) {
                $number = (int) ($rowEl->getAttribute('r') ?: $index + 1);
                $cells = [];
                $next = 0;
                foreach ($rowEl->getElementsByTagNameNS(self::MAIN_NS, 'c') as $cell) {
                    $ref = $cell->getAttribute('r');
                    $col = $ref !== '' ? $this->columnIndex($ref) : $next;
                    $cells[$col] = $this->cellText($cell, $strings, $padding);
                    $next = $col + 1;
                }

                if ($cells === []) {
                    continue;
                }
                $filled = array_fill(0, max(array_keys($cells)) + 1, '');
                $cells = array_replace($filled, $cells);
                if ($this->isBlank($cells)) {
                    continue;
                }
                $rows[] = ['row' => $number, 'cells' => $cells];
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** The first sheet in workbook order, resolved through the workbook's relationships. */
    private function firstSheetPath(ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';
        $workbook = $this->xml($zip, 'xl/workbook.xml');
        $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        $sheet = $workbook?->getElementsByTagNameNS(self::MAIN_NS, 'sheet')->item(0);
        if ($sheet === null || $rels === null) {
            return $fallback;
        }

        $id = $sheet->getAttributeNS(self::REL_NS, 'id');
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            if ($rel->getAttribute('Id') === $id) {
                $target = $rel->getAttribute('Target');

                return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }
        }

        return $fallback;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $doc = $this->xml($zip, 'xl/sharedStrings.xml');
        if ($doc === null) {
            return [];
        }

        $strings = [];
        foreach ($doc->getElementsByTagNameNS(self::MAIN_NS, 'si') as $si) {
            $strings[] = $this->richText($si);
        }

        return $strings;
    }

    /**
     * Style index → width, for each cell style whose number format is all zeros ("00000"): the
     * usual way a sheet shows an account number's leading zeros on a number cell.
     *
     * @return array<int, int>
     */
    private function zeroPadding(ZipArchive $zip): array
    {
        $doc = $this->xml($zip, 'xl/styles.xml');
        if ($doc === null) {
            return [];
        }

        $widths = [];
        foreach ($doc->getElementsByTagNameNS(self::MAIN_NS, 'numFmt') as $fmt) {
            $code = str_replace(['"', '\\'], '', $fmt->getAttribute('formatCode'));
            if (preg_match('/^0+$/', $code)) {
                $widths[(int) $fmt->getAttribute('numFmtId')] = strlen($code);
            }
        }

        $padding = [];
        $cellXfs = $doc->getElementsByTagNameNS(self::MAIN_NS, 'cellXfs')->item(0);
        if ($cellXfs instanceof DOMElement) {
            $index = 0;
            foreach ($cellXfs->childNodes as $xf) {
                if (! $xf instanceof DOMElement || $xf->localName !== 'xf') {
                    continue;
                }
                $fmtId = (int) $xf->getAttribute('numFmtId');
                if (isset($widths[$fmtId])) {
                    $padding[$index] = $widths[$fmtId];
                }
                $index++;
            }
        }

        return $padding;
    }

    /**
     * @param  list<string>  $strings
     * @param  array<int, int>  $padding
     */
    private function cellText(DOMElement $cell, array $strings, array $padding): string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            $is = $cell->getElementsByTagNameNS(self::MAIN_NS, 'is')->item(0);

            return $is instanceof DOMElement ? $this->richText($is) : '';
        }

        $v = $cell->getElementsByTagNameNS(self::MAIN_NS, 'v')->item(0);
        $value = $v?->textContent ?? '';

        return match ($type) {
            's' => $strings[(int) $value] ?? '',
            'str', 'e' => $value,
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            default => $this->numberText($value, $padding[(int) $cell->getAttribute('s')] ?? 0),
        };
    }

    /** A number cell's stored value as digits: "1.2345E+11" → "123450000000", padded if styled so. */
    private function numberText(string $value, int $width): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?E\+?(\d+)$/i', $value, $m)) {
            $fraction = $m[3] ?? '';
            $exponent = (int) $m[4];
            if ($exponent >= strlen($fraction)) {
                $value = $m[1].(ltrim($m[2].$fraction.str_repeat('0', $exponent - strlen($fraction)), '0') ?: '0');
            }
        }

        if ($width > 0 && preg_match('/^\d+$/', $value)) {
            $value = str_pad($value, $width, '0', STR_PAD_LEFT);
        }

        return $value;
    }

    /** The text of a string item: its runs joined, leaving out phonetic guides. */
    private function richText(DOMElement $item): string
    {
        $text = '';
        foreach ($item->getElementsByTagNameNS(self::MAIN_NS, 't') as $t) {
            if ($t->parentNode instanceof DOMElement && $t->parentNode->localName === 'rPh') {
                continue;
            }
            $text .= $t->textContent;
        }

        return $text;
    }

    /** "AB12" → 27 (0-based). */
    private function columnIndex(string $ref): int
    {
        $letters = strtoupper(preg_replace('/\d+/', '', $ref) ?? '');
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function xml(ZipArchive $zip, string $name): ?DOMDocument
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new RuntimeException('The workbook is too large to read.');
        }

        $contents = $zip->getFromName($name);
        if ($contents === false) {
            return null;
        }

        $doc = new DOMDocument;
        if (! @$doc->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('The file is not a valid Excel (.xlsx) workbook.');
        }

        return $doc;
    }

    /** @param  list<string>  $cells */
    private function isBlank(array $cells): bool
    {
        return trim(implode('', $cells)) === '';
    }
}
