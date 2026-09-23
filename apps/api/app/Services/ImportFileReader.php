<?php

namespace App\Services;

use App\Support\ApiProblem;
use Generator;
use XMLReader;
use ZipArchive;

final class ImportFileReader
{
    /** @return Generator<int, list<string>> Physical source row => cells. */
    public function rows(string $path, string $format): Generator
    {
        yield from $format === 'csv' ? $this->csv($path) : $this->xlsx($path);
    }

    /** @return array{headers: list<string>, sample_rows: list<array{source_row: int, values: list<string>}>} */
    public function inspect(string $path, string $format): array
    {
        $headers = [];
        $sample = [];
        foreach ($this->rows($path, $format) as $row => $cells) {
            if ($row === 1) {
                $headers = $cells;
                if (count(array_unique($headers)) !== count($headers) || in_array('', array_map('trim', $headers), true)) {
                    $this->invalid();
                }
            } else {
                $sample[] = ['source_row' => $row, 'values' => $cells];
                if (count($sample) >= config('imports.sample_rows')) {
                    break;
                }
            }
        }
        if ($headers === [] || $sample === []) {
            $this->invalid();
        }

        return ['headers' => $headers, 'sample_rows' => $sample];
    }

    /** @return Generator<int, list<string>> */
    private function csv(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->invalid();
        }
        try {
            $row = 0;
            $delimiter = ',';
            while (($line = fgets($handle, 65538)) !== false) {
                $row++;
                if (strlen($line) > 65536 || $row > config('imports.max_rows') + 1 || str_contains($line, "\0") || ! mb_check_encoding($line, 'UTF-8')) {
                    $this->invalid();
                }
                $line = rtrim($line, "\r\n");
                if ($row === 1) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                    foreach ([",", ";", "\t"] as $candidate) {
                        if (count(str_getcsv($line, $candidate, '"', '')) > count(str_getcsv($line, $delimiter, '"', ''))) {
                            $delimiter = $candidate;
                        }
                    }
                }
                $d = preg_quote($delimiter, '~');
                $field = '(?:"(?:[^"]|"")*"|[^"'.$d.']*)';
                if (! preg_match('~^'.$field.'(?:'.$d.$field.')*$~D', $line)) {
                    $this->invalid(); // Multiline quoted records are deliberately unsupported.
                }
                $cells = array_map(static fn (?string $value): string => $value ?? '', str_getcsv($line, $delimiter, '"', ''));
                $this->checkCells($cells);
                yield $row => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return Generator<int, list<string>> */
    private function xlsx(string $path): Generator
    {
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            libxml_use_internal_errors($previousErrors);
            $this->invalid();
        }
        try {
            $total = 0;
            $names = [];
            if ($zip->numFiles > 100) {
                $this->invalid();
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || $stat['size'] > 8388608 || isset($names[$stat['name']]) || preg_match('~(^/|\.\.|\\\\|externalLinks|vbaProject)~i', $stat['name'])) {
                    $this->invalid();
                }
                $names[$stat['name']] = true;
                $total += $stat['size'];
            }
            if ($total > 33554432 || ! isset($names['[Content_Types].xml'], $names['xl/workbook.xml'], $names['xl/worksheets/sheet1.xml'])) {
                $this->invalid();
            }
            // Single-sheet pilot format: no silent selection of another worksheet.
            if (count(preg_grep('~^xl/worksheets/sheet\d+\.xml$~', array_keys($names)) ?: []) !== 1) {
                $this->invalid();
            }
            foreach (array_keys($names) as $name) {
                if (str_ends_with($name, '.rels') || in_array($name, ['[Content_Types].xml', 'xl/workbook.xml'], true)) {
                    $metadata = $this->xml($zip, $name, 65536);
                    while ($metadata->read()) {
                        if (strtolower($metadata->getAttribute('TargetMode') ?? '') === 'external') { $this->invalid(); }
                    }
                    $metadata->close();
                    if (libxml_get_errors() !== []) { $this->invalid(); }
                }
            }
            $strings = [];
            if (isset($names['xl/sharedStrings.xml'])) {
                $xml = $this->xml($zip, 'xl/sharedStrings.xml', 1048576);
                try {
                    while ($xml->read()) {
                        if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'si') {
                            $strings[] = $xml->readString();
                            if (count($strings) > 10000) {
                                $this->invalid();
                            }
                        }
                    }
                } finally {
                    $xml->close();
                }
                if (libxml_get_errors() !== []) { $this->invalid(); }
            }
            $xml = $this->xml($zip, 'xl/worksheets/sheet1.xml', 8388608);
            $previous = 0;
            try {
                while ($xml->read()) {
                    if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'row') {
                        continue;
                    }
                    $row = (int) $xml->getAttribute('r');
                    if ($row <= $previous || ($previous === 0 && $row !== 1) || $row > config('imports.max_rows') + 1) {
                        $this->invalid();
                    }
                    $previous = $row;
                    $fragment = $xml->readOuterXml();
                    if (strlen($fragment) > 65536) {
                        $this->invalid();
                    }
                    $node = simplexml_load_string($fragment, options: LIBXML_NONET);
                    if ($node === false) {
                        $this->invalid();
                    }
                    $cells = [];
                    foreach ($node->children() as $cell) {
                        if ($cell->getName() !== 'c' || isset($cell->f)) {
                            $this->invalid();
                        }
                        if (! preg_match('/^([A-Z]{1,2})'.$row.'$/D', (string) $cell['r'], $match)) {
                            $this->invalid();
                        }
                        $column = 0;
                        foreach (str_split($match[1]) as $letter) {
                            $column = $column * 26 + ord($letter) - 64;
                        }
                        if ($column > 100 || $column <= count($cells)) {
                            $this->invalid();
                        }
                        while (count($cells) < $column - 1) {
                            $cells[] = '';
                        }
                        $type = (string) $cell['t'];
                        $value = (string) $cell->v;
                        if ($type === 's') {
                            if (! ctype_digit($value) || ! isset($strings[(int) $value])) {
                                $this->invalid();
                            }
                            $value = $strings[(int) $value];
                        } elseif ($type === 'inlineStr') {
                            $value = implode('', array_map(static fn (\SimpleXMLElement $text): string => (string) $text, $cell->xpath('.//*[local-name()="t"]') ?: []));
                        } elseif (! in_array($type, ['', 'n', 'str'], true)) {
                            $this->invalid();
                        }
                        $cells[] = $value;
                    }
                    $this->checkCells($cells);
                    yield $row => $cells;
                }
                if (libxml_get_errors() !== []) { $this->invalid(); }
            } finally {
                $xml->close();
            }
        } finally {
            $zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function xml(ZipArchive $zip, string $name, int $max): XMLReader
    {
        $text = $zip->getFromName($name);
        if ($text === false || strlen($text) > $max || preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
            $this->invalid();
        }
        $reader = new XMLReader;
        if (! $reader->XML($text, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            $this->invalid();
        }

        return $reader;
    }

    /** @param list<string> $cells */
    private function checkCells(array $cells): void
    {
        if ($cells === [] || count($cells) > 100) {
            $this->invalid();
        }
        foreach ($cells as $cell) {
            if (strlen($cell) > 4096) {
                $this->invalid();
            }
        }
    }

    private function invalid(): never
    {
        throw new ApiProblem('invalid_import_file', 'The file is malformed or exceeds the supported structural limits.', 422);
    }
}
