<?php

namespace Modules\Import\Services;

use League\Csv\Reader;
use Modules\Import\Exceptions\ImportException;
use Throwable;

/**
 * Parses a CSV string into a header list + associative rows using league/csv
 * (never hand-rolled). Handles BOM, quoting, and a sniffed delimiter, and
 * repairs invalid byte sequences so a malformed/mis-encoded file degrades
 * gracefully into rows rather than crashing.
 */
class CsvReader
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public function parse(string $content): array
    {
        if (trim($content) === '') {
            throw new ImportException('The CSV file is empty.');
        }

        $delimiter = $this->sniffDelimiter($content);

        try {
            $csv = Reader::createFromString($content);
            $csv->setDelimiter($delimiter);
            $csv->skipInputBOM();

            // Positional records (no header offset) so duplicate/blank header
            // cells never raise a SyntaxError — we build the header map ourselves.
            $records = iterator_to_array($csv->getRecords(), false);
        } catch (Throwable $e) {
            throw new ImportException('The CSV file could not be parsed: '.$e->getMessage(), 0, $e);
        }

        if ($records === []) {
            throw new ImportException('The CSV file has no header row.');
        }

        $headers = $this->uniqueHeaders(array_map([$this, 'clean'], array_values((array) array_shift($records))));

        $rows = [];
        foreach ($records as $record) {
            $values = array_map([$this, 'clean'], array_values((array) $record));

            // Skip fully blank lines.
            if (implode('', $values) === '') {
                continue;
            }

            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = $values[$index] ?? '';
            }
            $rows[] = $assoc;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function sniffDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\r\n");
        if ($firstLine === false) {
            return ',';
        }

        $counts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
            '|' => substr_count($firstLine, '|'),
        ];
        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    private function clean(mixed $value): string
    {
        $value = (string) $value;

        // Repair invalid UTF-8 so downstream json_encode never fails.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return trim($value);
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function uniqueHeaders(array $headers): array
    {
        $seen = [];
        $result = [];
        foreach ($headers as $index => $header) {
            $header = $header === '' ? 'column_'.($index + 1) : $header;
            $candidate = $header;
            $suffix = 2;
            while (in_array($candidate, $seen, true)) {
                $candidate = $header.'_'.$suffix;
                $suffix++;
            }
            $seen[] = $candidate;
            $result[] = $candidate;
        }

        return $result;
    }
}
