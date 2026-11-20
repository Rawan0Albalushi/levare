<?php

namespace App\Actions;

use App\CouplingEnd;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ReadCouplingWorkbook
{
    /**
     * @return array{pumps: list<array<string, mixed>>, rules: list<array<string, mixed>>}
     */
    public function read(string $path): array
    {
        $grid = $this->grid($path);
        $header = $this->headerRow($grid);

        return [
            'pumps' => $this->pumps($grid, $header),
            'rules' => $this->rules($grid, $header),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function grid(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The coupling file is not in the expected format.');
        }

        $shared = $zip->getFromName('xl/sharedStrings.xml');
        $sheet = $this->firstSheet($zip);
        $zip->close();

        if ($sheet === null) {
            throw new RuntimeException('The coupling file is not in the expected format.');
        }

        $strings = is_string($shared) ? $this->sharedStrings($shared) : [];
        $xml = $this->xml($sheet);
        $grid = [];

        foreach ($this->nodes($xml, '//m:sheetData/m:row/m:c') as $cell) {
            $reference = (string) $cell['r'];
            if (! preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches)) {
                continue;
            }

            $value = $this->cellValue($cell, $strings);
            if ($value === '') {
                continue;
            }

            $grid[(int) $matches[2]][$matches[1]] = $value;
        }

        foreach ($this->nodes($xml, '//m:mergeCell') as $merge) {
            $this->fillMerge($grid, (string) $merge['ref']);
        }

        return $grid;
    }

    private function firstSheet(ZipArchive $zip): ?string
    {
        $sheet = $this->sheetFromWorkbook($zip) ?? $zip->getFromName('xl/worksheets/sheet1.xml');

        return is_string($sheet) && $sheet !== '' ? $sheet : null;
    }

    private function sheetFromWorkbook(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! is_string($workbook) || ! is_string($rels) || $workbook === '' || $rels === '') {
            return null;
        }

        $sheets = $this->nodes($this->xml($workbook), '//m:sheets/m:sheet');
        $sheet = $sheets[0] ?? null;
        if (! $sheet instanceof SimpleXMLElement) {
            return null;
        }

        $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        if (preg_match('/^rId\d+$/', $id) !== 1) {
            return null;
        }

        $relationships = simplexml_load_string($rels);
        if (! $relationships instanceof SimpleXMLElement) {
            return null;
        }

        $relationships->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $matches = $relationships->xpath('//r:Relationship[@Id="'.$id.'"]');
        $target = is_array($matches) ? (string) ($matches[0]['Target'] ?? '') : '';
        if ($target === '' || str_contains($target, '..')) {
            return null;
        }

        $target = ltrim($target, '/');
        $path = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        $contents = $zip->getFromName($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(string $xml): array
    {
        $strings = [];

        foreach ($this->nodes($this->xml($xml), '//m:si') as $item) {
            $text = '';
            foreach ($this->nodes($item, './/m:t') as $node) {
                $text .= (string) $node;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $strings
     */
    private function cellValue(SimpleXMLElement $cell, array $strings): string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            $value = $strings[(int) (string) $cell->v] ?? '';
        } elseif ($type === 'inlineStr') {
            $value = '';
            foreach ($this->nodes($cell, './/m:t') as $node) {
                $value .= (string) $node;
            }
        } else {
            $value = (string) $cell->v;
        }

        $value = trim($value);
        if (preg_match('/^\d+\.0+$/', $value) === 1) {
            return substr($value, 0, (int) strpos($value, '.'));
        }

        return $value;
    }

    /**
     * @param  array<int, array<string, string>>  $grid
     */
    private function fillMerge(array &$grid, string $reference): void
    {
        if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $reference, $matches)) {
            return;
        }

        $value = $grid[(int) $matches[2]][$matches[1]] ?? '';
        if ($value === '') {
            return;
        }

        for ($row = (int) $matches[2]; $row <= (int) $matches[4]; $row++) {
            for ($column = $matches[1]; $column <= $matches[3]; $column++) {
                $grid[$row][$column] ??= $value;
                if ($column === $matches[3]) {
                    break;
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, string>>  $grid
     */
    private function headerRow(array $grid): int
    {
        foreach ($grid as $row => $columns) {
            if (trim($columns['A'] ?? '') === 'Equipment' && trim($columns['B'] ?? '') === 'Series') {
                return $row;
            }
        }

        throw new RuntimeException('The coupling file is not in the expected format.');
    }

    /**
     * @param  array<int, array<string, string>>  $grid
     * @return list<array<string, mixed>>
     */
    private function pumps(array $grid, int $header): array
    {
        $pumps = [];
        $position = 1;

        for ($row = 1; $row < $header; $row++) {
            $name = trim($grid[$row]['B'] ?? '');
            if (! str_starts_with(strtoupper($name), 'ESP')) {
                continue;
            }

            $shaft = $this->shaftToken($grid[$row]['F'] ?? '');
            $series = $this->series($name);
            if ($shaft === '' || $series === null) {
                throw new RuntimeException('The coupling file is not in the expected format (row '.$row.').');
            }

            $pumps[] = [
                'name' => $name,
                'series' => $series,
                'shaft' => $shaft,
                'kind' => str_contains(strtoupper($name), 'VAPRO') ? CouplingEnd::Vapro->value : CouplingEnd::Pump->value,
                'position' => $position,
            ];
            $position++;
        }

        if ($pumps === []) {
            throw new RuntimeException('The coupling file is not in the expected format.');
        }

        return $pumps;
    }

    /**
     * @param  array<int, array<string, string>>  $grid
     * @return list<array<string, mixed>>
     */
    private function rules(array $grid, int $header): array
    {
        $rules = [];
        $position = 1;
        $lastRow = max(array_keys($grid));

        for ($row = $header + 1; $row <= $lastRow; $row++) {
            $columns = $grid[$row] ?? [];
            $partNumber = $this->cellText($columns['F'] ?? '');
            $description = trim($columns['D'] ?? '');
            if ($partNumber === '' && $description === '') {
                continue;
            }

            $ends = $this->ends(trim($columns['A'] ?? ''), $row);
            $series = $this->series(trim($columns['B'] ?? ''));
            [$left, $right] = $this->shaftPair(trim($columns['C'] ?? ''), $row);
            [$describedLeft, $describedRight] = $this->descriptionShafts($description);

            if ($partNumber === '' || $description === '' || $series === null) {
                throw new RuntimeException('The coupling file is not in the expected format (row '.$row.').');
            }

            $alternates = [];
            foreach (['H', 'J'] as $column) {
                $alternate = $this->cellText($columns[$column] ?? '');
                if ($alternate !== '' && $alternate !== $partNumber && ! in_array($alternate, $alternates, true)) {
                    $alternates[] = $alternate;
                }
            }

            $old = $this->cellText($columns['G'] ?? '');
            $wells = trim($columns['I'] ?? '');

            $rules[] = [
                'from_end' => $ends[0]->value,
                'to_end' => $ends[1]->value,
                'series' => $series,
                'left_shaft' => $left,
                'right_shaft' => $right,
                'shaft_label' => trim($columns['C'] ?? ''),
                'description' => $description,
                'part_number' => $partNumber,
                'old_part_number' => $old !== '' ? $old : null,
                'alternate_part_numbers' => $alternates,
                'description_left_shaft' => $describedLeft,
                'description_right_shaft' => $describedRight,
                'wells' => $wells !== '' ? $wells : null,
                'position' => $position,
            ];
            $position++;
        }

        if ($rules === []) {
            throw new RuntimeException('The coupling file is not in the expected format.');
        }

        return $rules;
    }

    /**
     * @return array{0: CouplingEnd, 1: CouplingEnd}
     */
    private function ends(string $label, int $row): array
    {
        $key = str_replace([' ', '-', '–', '—', '_'], '', strtolower($label));

        $ends = match ($key) {
            'pumppump' => [CouplingEnd::Pump, CouplingEnd::Pump],
            'pumpvapro' => [CouplingEnd::Pump, CouplingEnd::Vapro],
            'pumpgs' => [CouplingEnd::Pump, CouplingEnd::Gs],
            'pumpintake' => [CouplingEnd::Pump, CouplingEnd::Intake],
            'vaprogs' => [CouplingEnd::Vapro, CouplingEnd::Gs],
            'vaprointake' => [CouplingEnd::Vapro, CouplingEnd::Intake],
            'gsgs' => [CouplingEnd::Gs, CouplingEnd::Gs],
            'gsintake' => [CouplingEnd::Gs, CouplingEnd::Intake],
            'gsseal' => [CouplingEnd::Gs, CouplingEnd::Seal],
            'intakeseal' => [CouplingEnd::Intake, CouplingEnd::Seal],
            'sealseal' => [CouplingEnd::Seal, CouplingEnd::Seal],
            'sealmotor' => [CouplingEnd::Seal, CouplingEnd::Motor],
            'motormotor' => [CouplingEnd::Motor, CouplingEnd::Motor],
            default => null,
        };

        if ($ends === null) {
            throw new RuntimeException('The coupling file is not in the expected format (row '.$row.').');
        }

        return $ends;
    }

    private function series(string $value): ?string
    {
        if (preg_match('/(^|[^0-9])(400|538)([^0-9]|$)/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[2];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function shaftPair(string $label, int $row): array
    {
        $parts = preg_split('/\s*[×xX*]\s*/u', trim($label)) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $token = $this->shaftToken($part);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        if (count($tokens) !== 2) {
            throw new RuntimeException('The coupling file is not in the expected format (row '.$row.').');
        }

        return [$tokens[0], $tokens[1]];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function descriptionShafts(string $description): array
    {
        $head = preg_split('/\bLG\b/i', $description)[0] ?? $description;
        preg_match_all('/(\d+\s*-\s*\d+\/\d+|\d+\s+\d+\/\d+|\d+(?:\.\d+)?)\s*(?:mm\s*)?INV/iu', $head, $matches);
        $tokens = [];

        foreach ($matches[1] as $token) {
            $tokens[] = $this->shaftToken($token);
        }

        if (count($tokens) < 2) {
            return [null, null];
        }

        return [$tokens[0], $tokens[1]];
    }

    private function shaftToken(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s*-\s*/', '-', $value) ?? $value;
        $value = preg_replace('/(\d)\s+(\d+\/\d+)/', '$1-$2', $value) ?? $value;

        return $value;
    }

    private function cellText(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d+\.0+$/', $value) === 1) {
            return substr($value, 0, (int) strpos($value, '.'));
        }

        return $value;
    }

    private function xml(string $xml): SimpleXMLElement
    {
        $parsed = simplexml_load_string($xml);
        if (! $parsed instanceof SimpleXMLElement) {
            throw new RuntimeException('The coupling file is not in the expected format.');
        }

        $parsed->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return $parsed;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function nodes(SimpleXMLElement $xml, string $query): array
    {
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $nodes = $xml->xpath($query);

        return $nodes === false ? [] : $nodes;
    }
}
