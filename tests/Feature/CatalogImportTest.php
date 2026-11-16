<?php

namespace Tests\Feature;

use App\Actions\ImportParts;
use App\Models\CatalogSource;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_uploaded_parts_file_updates_matching_rows_and_leaves_the_rest(): void
    {
        $this->travelTo('2026-11-16 08:00:00');
        CatalogSource::query()->create([
            'store_name' => 'Previous store',
            'critical_name' => 'Previous critical',
            'imported_at' => '2026-01-01 00:00:00',
        ]);
        $kept = $this->part([
            'part_number' => 'KEEP-1',
            'description' => 'Kept sleeve',
            'sort_order' => 1,
        ], [[
            'location' => 'BIN-1',
            'quantity' => 5,
            'quantity_raw' => '5',
        ]]);
        $kept->adjustments()->create([
            'part_location_id' => $kept->locations()->value('id'),
            'location' => 'BIN-1',
            'action' => 'set',
            'quantity_before' => 1,
            'quantity_after' => 5,
        ]);
        $edit = $this->part([
            'item_code' => '100',
            'part_number' => 'PN-EDIT',
            'description' => 'Sleeve',
            'uom' => 'EA',
            'store_type' => 'Seal',
            'categories' => ['seal'],
            'priority' => 'LOW',
            'min_qty' => '4',
            'notes' => 'Shelf',
            'is_critical' => true,
            'sort_order' => 2,
        ], [
            ['location' => 'OLD', 'quantity' => 8, 'quantity_raw' => '8', 'updated_on' => '1/1/2026', 'condition' => 'New'],
            ['location' => 'G1', 'quantity' => 10, 'quantity_raw' => '10', 'condition' => 'New'],
        ]);
        $numberOnly = $this->part([
            'part_number' => 'PN-ONLY',
            'description' => 'Washer',
            'sort_order' => 3,
        ], [[
            'location' => 'B1',
            'quantity' => 2,
        ]]);
        $path = $this->workbook([
            ['Item Code', 'Part Number', 'Description', 'UOM', 'Location', 'Quantity', 'Condition', 'Last Update', 'Status', 'Reference', 'Type'],
            ['100', 'PN-EDIT', 'Sleeve revised', 'EA', 'G1', '27', 'Used', '', '', '', 'Pump'],
            ['100', 'PN-EDIT', 'Sleeve revised', 'EA', 'Z', '0', '', '', '', '', 'Pump'],
            ['200', 'PN-NEW', 'Impeller', 'EA', 'A1', '3', 'New', '', '', '', 'Pump'],
            ['', 'PN-ONLY', 'Washer updated', 'EA', 'B2', '4', '', '', '', '', ''],
        ]);

        try {
            $upload = new UploadedFile($path, 'parts.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

            $this->post('/api/catalog/import', [
                'files' => [$upload],
            ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('imported', 3);
        } finally {
            File::delete($path);
        }

        $this->assertSame(4, Part::query()->count());
        $this->assertModelExists($kept);
        $this->assertSame('5.00', $kept->locations()->value('quantity'));
        $this->getJson('/api/parts/'.$kept->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'set')
            ->assertJsonPath('adjustments.0.before', 1)
            ->assertJsonPath('adjustments.0.after', 5);

        $edit->refresh();
        $this->assertSame('Sleeve revised', $edit->description);
        $this->assertSame('Pump', $edit->store_type);
        $this->assertSame(['seal'], $edit->categories);
        $this->assertSame('LOW', $edit->priority);
        $this->assertSame('4', $edit->min_qty);
        $this->assertSame('Shelf', $edit->notes);
        $this->assertTrue($edit->is_critical);
        $this->assertSame(['OLD', 'G1', 'Z'], $edit->locations()->orderBy('position')->pluck('location')->all());
        $old = $edit->locations()->where('location', 'OLD')->first();
        $current = $edit->locations()->where('location', 'G1')->first();
        $added = $edit->locations()->where('location', 'Z')->first();
        $this->assertSame('8.00', $old->quantity);
        $this->assertSame('8', $old->quantity_raw);
        $this->assertSame('1/1/2026', $old->updated_on);
        $this->assertSame('New', $old->condition);
        $this->assertSame('27.00', $current->quantity);
        $this->assertNull($current->quantity_raw);
        $this->assertSame('Used', $current->condition);
        $this->assertSame('2026-11-16', $current->updated_on);
        $this->assertSame('0.00', $added->quantity);
        $this->getJson('/api/parts/'.$edit->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'set')
            ->assertJsonPath('adjustments.0.location', 'G1')
            ->assertJsonPath('adjustments.0.before', 10)
            ->assertJsonPath('adjustments.0.after', 27);

        $numberOnly->refresh();
        $this->assertNull($numberOnly->item_code);
        $this->assertSame('Washer updated', $numberOnly->description);
        $this->assertSame(['B1', 'B2'], $numberOnly->locations()->orderBy('position')->pluck('location')->all());
        $this->assertSame('2.00', $numberOnly->locations()->where('location', 'B1')->value('quantity'));
        $this->assertSame('4.00', $numberOnly->locations()->where('location', 'B2')->value('quantity'));
        $this->getJson('/api/parts/'.$numberOnly->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'open')
            ->assertJsonPath('adjustments.0.before', 0)
            ->assertJsonPath('adjustments.0.after', 4);

        $created = Part::query()->where('item_code', '200')->first();
        $this->assertSame('PN-NEW', $created->part_number);
        $this->assertSame('Impeller', $created->description);
        $this->assertSame('3.00', $created->locations()->value('quantity'));
        $this->getJson('/api/parts/'.$created->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'open')
            ->assertJsonPath('adjustments.0.before', 0)
            ->assertJsonPath('adjustments.0.after', 3);

        $this->assertSame(1, CatalogSource::query()->count());
        $source = CatalogSource::query()->first();
        $this->assertSame('Previous critical', $source->critical_name);
        $this->assertSame('2026-11-16', $source->imported_at->toDateString());
    }

    public function test_a_workbook_without_part_columns_leaves_the_catalog_unchanged(): void
    {
        $part = $this->part([
            'part_number' => 'KEEP-1',
            'description' => 'Kept sleeve',
        ], [[
            'location' => 'BIN-1',
            'quantity' => 5,
        ]]);
        $path = $this->workbook([
            ['Name', 'Value'],
            ['Note', 'Ignore'],
        ]);

        try {
            $upload = new UploadedFile($path, 'notes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

            $this->post('/api/catalog/import', [
                'files' => [$upload],
            ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('imported', 0);
        } finally {
            File::delete($path);
        }

        $this->assertSame(1, Part::query()->count());
        $this->assertSame('5.00', $part->locations()->value('quantity'));
    }

    public function test_uploading_the_same_quantity_does_not_record_another_change(): void
    {
        $this->travelTo('2026-11-16 08:00:00');
        $part = $this->part([
            'item_code' => '100',
            'part_number' => 'PN-EDIT',
            'description' => 'Sleeve',
        ], [[
            'location' => 'G1',
            'quantity' => 10,
            'quantity_raw' => '10',
        ]]);
        $payload = [
            'source' => ['store' => 'Sheet', 'critical' => null],
            'store' => [[
                'id' => '100',
                'pn' => 'PN-EDIT',
                'd' => 'Sleeve',
                'u' => 'EA',
                't' => '',
                'L' => [['G1', 27, '27', '', 'New', '', '']],
            ]],
            'critical' => [],
        ];

        $this->merge($payload);

        $this->merge($payload);

        $this->assertSame('27.00', $part->locations()->value('quantity'));
        $this->assertNull($part->locations()->value('quantity_raw'));
        $this->getJson('/api/parts/'.$part->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'set')
            ->assertJsonPath('adjustments.0.before', 10)
            ->assertJsonPath('adjustments.0.after', 27);
    }

    public function test_a_critical_list_updates_metadata_without_changing_quantities(): void
    {
        CatalogSource::query()->create([
            'store_name' => 'Previous store',
            'critical_name' => 'Old list',
            'imported_at' => '2026-01-01 00:00:00',
        ]);
        $part = $this->part([
            'part_number' => 'PN-CRIT',
            'description' => 'Sleeve',
            'categories' => ['seal'],
            'notes' => 'Shelf',
            'min_qty' => '4',
            'is_critical' => false,
        ], [[
            'location' => 'G1',
            'quantity' => 10,
            'quantity_raw' => '10',
        ]]);
        $untouched = $this->part([
            'part_number' => 'PN-STAY',
            'description' => 'Washer',
            'is_critical' => true,
            'priority' => 'LOW',
        ], [[
            'location' => 'B1',
            'quantity' => 2,
        ]]);

        $imported = $this->merge([
            'source' => ['store' => null, 'critical' => 'Critical list'],
            'store' => [],
            'critical' => [
                'PN-CRIT' => ['d' => 'Sleeve from the list', 'cats' => ['pump'], 'pr' => 'HIGH', 'mn' => '9'],
                'PN-ADDED' => ['d' => 'Bolt', 'cats' => ['seal'], 'pr' => 'LOW'],
            ],
        ]);

        $this->assertSame(2, $imported);
        $part->refresh();
        $this->assertSame('Sleeve', $part->description);
        $this->assertSame(['pump'], $part->categories);
        $this->assertSame('HIGH', $part->priority);
        $this->assertSame('9', $part->min_qty);
        $this->assertSame('Shelf', $part->notes);
        $this->assertTrue($part->is_critical);
        $this->assertSame('10.00', $part->locations()->value('quantity'));
        $this->assertSame('10', $part->locations()->value('quantity_raw'));
        $this->assertSame(0, $part->adjustments()->count());
        $untouched->refresh();
        $this->assertTrue($untouched->is_critical);
        $this->assertSame('LOW', $untouched->priority);
        $this->assertSame('2.00', $untouched->locations()->value('quantity'));
        $added = Part::query()->where('part_number', 'PN-ADDED')->first();
        $this->assertSame('Bolt', $added->description);
        $this->assertTrue($added->is_critical);
        $this->assertSame(['seal'], $added->categories);
        $this->assertSame(0, $added->locations()->count());
        $source = CatalogSource::query()->first();
        $this->assertSame('Previous store', $source->store_name);
        $this->assertSame('Critical list', $source->critical_name);
        $this->assertSame(1, CatalogSource::query()->count());
    }

    public function test_a_row_without_codes_updates_only_the_part_with_that_description(): void
    {
        $plain = $this->part([
            'description' => 'Gasket',
        ], [[
            'location' => 'Z',
            'quantity' => 1,
        ]]);
        $coded = $this->part([
            'part_number' => 'PN-G',
            'description' => 'Gasket',
        ], [[
            'location' => 'Z',
            'quantity' => 3,
        ]]);

        $this->merge([
            'source' => ['store' => 'Sheet', 'critical' => null],
            'store' => [[
                'id' => '',
                'pn' => '',
                'd' => 'Gasket',
                'u' => 'EA',
                't' => '',
                'L' => [['Z', 9, '9', '', '', '', '']],
            ]],
            'critical' => [],
        ]);

        $this->assertSame('9.00', $plain->locations()->value('quantity'));
        $this->assertNull($plain->fresh()->part_number);
        $this->assertSame('3.00', $coded->locations()->value('quantity'));
        $this->assertSame(0, $coded->adjustments()->count());
        $this->getJson('/api/parts/'.$plain->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'set')
            ->assertJsonPath('adjustments.0.before', 1)
            ->assertJsonPath('adjustments.0.after', 9);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $locations
     */
    private function part(array $attributes, array $locations): Part
    {
        $part = Part::query()->create($attributes + [
            'categories' => [],
            'is_critical' => false,
            'sort_order' => 1,
        ]);

        foreach ($locations as $position => $location) {
            $part->locations()->create(['position' => $position] + $location);
        }

        return $part;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function merge(array $payload): int
    {
        $path = storage_path('app/imports/split-'.uniqid('', true).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($payload, JSON_THROW_ON_ERROR));

        try {
            return app(ImportParts::class)->mergeFromSplit($path);
        } finally {
            File::delete($path);
        }
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function workbook(array $rows): string
    {
        $path = storage_path('framework/testing/'.uniqid('parts-', true).'.xlsx');
        File::ensureDirectoryExists(dirname($path));
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            $this->fail('The test workbook could not be created.');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Parts" sheetId="1" r:id="rId1"/></sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($rows));
        $zip->close();

        return $path;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function sheet(array $rows): string
    {
        $body = '';
        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $body .= '<row r="'.$number.'">';
            foreach (array_values($row) as $column => $value) {
                $cell = $this->column($column).$number;
                $text = htmlspecialchars((string) $value, ENT_XML1);
                $body .= '<c r="'.$cell.'" t="inlineStr"><is><t>'.$text.'</t></is></c>';
            }
            $body .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .$body
            .'</sheetData></worksheet>';
    }

    private function column(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }
}
