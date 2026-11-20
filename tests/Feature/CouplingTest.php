<?php

namespace Tests\Feature;

use App\Actions\ImportCouplings;
use App\Models\CouplingPump;
use App\Models\CouplingRule;
use App\Models\Part;
use App\Models\PartLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CouplingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_coupling_page_is_served(): void
    {
        $this->get('/couplings')->assertOk()->assertSee('id="app"', false);
    }

    public function test_importing_the_workbook_stores_the_table_and_leaves_part_quantity_unchanged(): void
    {
        $part = Part::query()->create([
            'part_number' => 'KEEP-QTY',
            'description' => 'Keep me',
            'categories' => [],
            'sort_order' => 1,
        ]);
        PartLocation::query()->create([
            'part_id' => $part->id,
            'position' => 1,
            'location' => 'A1',
            'quantity' => 7,
        ]);

        $this->importCouplings();
        $this->importCouplings();

        $this->assertSame(27, CouplingPump::query()->count());
        $this->assertSame(47, CouplingRule::query()->count());
        $this->assertSame(7, Part::quantityNumber($part->locations()->value('quantity')));

        $pump = CouplingPump::query()->where('name', 'ESP B 538-5000')->first();
        $this->assertNotNull($pump);
        $this->assertSame('538', $pump->series);
        $this->assertSame('25', $pump->shaft);
        $this->assertSame('pump', $pump->kind);

        $sealMotor = CouplingRule::query()
            ->where('series', '400')
            ->where('from_end', 'seal')
            ->where('to_end', 'motor')
            ->first();
        $this->assertNotNull($sealMotor);
        $this->assertSame('25', $sealMotor->left_shaft);
        $this->assertSame('35', $sealMotor->right_shaft);
        $this->assertSame('25', $sealMotor->description_left_shaft);
        $this->assertSame('30', $sealMotor->description_right_shaft);

        $motor = CouplingRule::query()
            ->where('series', '538')
            ->where('from_end', 'motor')
            ->where('to_end', 'motor')
            ->first();
        $this->assertNotNull($motor);
        $this->assertSame('3/8', $motor->left_shaft);
        $this->assertSame('1-3/8', $motor->right_shaft);
        $seal = CouplingRule::query()
            ->where('series', '538')
            ->where('from_end', 'seal')
            ->where('to_end', 'seal')
            ->first();
        $this->assertNotNull($seal);
        $this->assertSame(['20018602', '20042853'], $seal->alternate_part_numbers);

        $this->getJson('/api/couplings')
            ->assertOk()
            ->assertJsonPath('pumps.21.name', 'ESP B 538-5000')
            ->assertJsonCount(27, 'pumps')
            ->assertJsonPath('ends.0', 'pump');
    }

    public function test_pump_538_5000_to_gs_suggests_part_20017647(): void
    {
        $this->importCouplings();
        $part = Part::query()->create([
            'item_code' => '517033',
            'part_number' => '20017647',
            'description' => 'Coupling from the store',
            'categories' => [],
            'sort_order' => 1,
        ]);
        PartLocation::query()->create([
            'part_id' => $part->id,
            'position' => 1,
            'location' => 'G1',
            'quantity' => 3,
        ]);

        $this->postJson('/api/couplings/suggestions', [
            'from' => ['end' => 'pump', 'pump' => 'ESP B 538-5000'],
            'to' => ['end' => 'gs'],
        ])->assertOk()
            ->assertJsonPath('series', '538')
            ->assertJsonPath('from.shaft', '25')
            ->assertJsonPath('to.shaft', '28')
            ->assertJsonPath('matches.0.part_number', '20017647')
            ->assertJsonPath('matches.0.old_part_number', '2029159')
            ->assertJsonPath('matches.0.numbers.0.parts.0.quantity', 3)
            ->assertJsonPath('matches.0.numbers.0.parts.0.locations.0.location', 'G1')
            ->assertJsonCount(1, 'matches');
    }

    public function test_pump_400_180_to_gs_suggests_part_20019771(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/suggestions', [
            'from' => ['end' => 'pump', 'pump' => 'ESP B 400-180'],
            'to' => ['end' => 'gs'],
        ])->assertOk()
            ->assertJsonPath('from.shaft', '17')
            ->assertJsonPath('to.shaft', '22')
            ->assertJsonPath('matches.0.part_number', '20019771')
            ->assertJsonCount(1, 'matches');
    }

    public function test_a_vapro_shaft_picks_the_matching_pump_to_vapro_part(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/suggestions', [
            'from' => ['end' => 'pump', 'pump' => 'ESP B 400-180'],
            'to' => ['end' => 'vapro', 'pump' => 'ESP VAPRO 400-750'],
        ])->assertOk()
            ->assertJsonPath('matches.0.part_number', '20017641')
            ->assertJsonPath('matches.0.shaft', '17×20');

        $this->postJson('/api/couplings/suggestions', [
            'from' => ['end' => 'pump', 'pump' => 'ESP B 400-180'],
            'to' => ['end' => 'vapro', 'pump' => 'ESP VAPRO B 400-2000'],
        ])->assertOk()
            ->assertJsonPath('matches.0.part_number', '20019771')
            ->assertJsonPath('matches.0.shaft', '17×22');
    }

    public function test_the_five_piece_string_suggests_the_four_couplings(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/strings', [
            'components' => [
                ['end' => 'pump', 'pump' => 'ESP B 538-5000'],
                ['end' => 'intake'],
                ['end' => 'seal'],
                ['end' => 'seal'],
                ['end' => 'motor'],
            ],
        ])->assertOk()
            ->assertJsonPath('series', '538')
            ->assertJsonPath('joints.0.matches.0.part_number', '20017647')
            ->assertJsonPath('joints.1.matches.0.part_number', '20017631')
            ->assertJsonPath('joints.2.matches.0.part_number', '20020134')
            ->assertJsonPath('joints.2.matches.0.numbers.2.part_number', '20018602')
            ->assertJsonPath('joints.2.matches.0.numbers.3.part_number', '20042853')
            ->assertJsonPath('joints.3.matches.0.part_number', '20014523')
            ->assertJsonCount(4, 'joints');
    }

    public function test_a_pump_with_no_matching_row_returns_no_part_number(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/suggestions', [
            'from' => ['end' => 'pump', 'pump' => 'ESP B 400-180'],
            'to' => ['end' => 'motor'],
        ])->assertOk()
            ->assertJsonPath('matches', []);
    }

    public function test_motor_to_motor_on_series_538_returns_the_only_row_with_the_shaft_difference(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/suggestions', [
            'series' => '538',
            'from' => ['end' => 'motor'],
            'to' => ['end' => 'motor'],
        ])->assertOk()
            ->assertJsonPath('matches.0.part_number', '20017626')
            ->assertJsonPath('matches.0.shaft', '3/8×1-3/8')
            ->assertJsonPath('matches.0.description_shaft', '1-3/8×1-3/8')
            ->assertJsonPath('matches.0.used_only_row', true);
    }

    public function test_seal_to_motor_on_series_400_keeps_the_sheet_shaft_and_shows_the_description(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/suggestions', [
            'series' => '400',
            'from' => ['end' => 'seal'],
            'to' => ['end' => 'motor'],
        ])->assertOk()
            ->assertJsonPath('matches.0.part_number', '20014519')
            ->assertJsonPath('matches.0.shaft', '25×35')
            ->assertJsonPath('matches.0.description_shaft', '25×30')
            ->assertJsonPath('matches.0.used_only_row', false);
    }

    public function test_an_unknown_pump_returns_422(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/suggestions', [
            'from' => ['end' => 'pump', 'pump' => 'ESP B 999'],
            'to' => ['end' => 'gs'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['from.pump' => 'Choose a pump from the coupling table.']);
    }

    public function test_a_vapro_model_chosen_as_a_pump_returns_422(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/suggestions', [
            'from' => ['end' => 'pump', 'pump' => 'ESP VAPRO 400-750'],
            'to' => ['end' => 'gs'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['from.pump' => 'Choose a pump from the coupling table.']);
    }

    public function test_a_pair_without_a_pump_or_series_returns_422(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/suggestions', [
            'from' => ['end' => 'seal'],
            'to' => ['end' => 'motor'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['series' => 'Choose series 400 or 538.']);

        $this->assertSame(0, Part::query()->count());
    }

    public function test_an_uploaded_coupling_workbook_replaces_the_table_and_leaves_part_quantity_unchanged(): void
    {
        $part = Part::query()->create([
            'part_number' => 'KEEP-QTY',
            'description' => 'Keep me',
            'categories' => [],
            'sort_order' => 1,
        ]);
        PartLocation::query()->create([
            'part_id' => $part->id,
            'position' => 1,
            'location' => 'A1',
            'quantity' => 7,
        ]);

        $upload = new UploadedFile(
            database_path('data/coupling.xlsx'),
            'coupling.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $this->post('/api/couplings/import', ['file' => $upload], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('pumps', 27)
            ->assertJsonPath('rules', 47);

        $this->assertSame(7, Part::quantityNumber($part->locations()->value('quantity')));
        $this->assertSame('25', CouplingPump::query()->where('name', 'ESP B 538-5000')->value('shaft'));

        $detectedAsZip = new UploadedFile(
            database_path('data/coupling.xlsx'),
            'coupling.xlsx',
            'application/zip',
            null,
            true,
        );

        $this->post('/api/couplings/import', ['file' => $detectedAsZip], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('pumps', 27)
            ->assertJsonPath('rules', 47);

        $this->post('/api/couplings/import', [
            'file' => UploadedFile::fake()->create('notes.xlsx', 12, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(27, CouplingPump::query()->count());
        $this->assertSame(47, CouplingRule::query()->count());
    }

    public function test_the_included_coupling_workbook_loads_without_an_upload(): void
    {
        $this->postJson('/api/couplings/included')
            ->assertOk()
            ->assertJsonPath('pumps', 27)
            ->assertJsonPath('rules', 47);

        $this->assertSame('25', CouplingPump::query()->where('name', 'ESP B 538-5000')->value('shaft'));
    }

    public function test_a_coupling_workbook_in_the_wrong_shape_returns_422_and_keeps_the_current_table(): void
    {
        $this->importCouplings();

        $upload = new UploadedFile(
            $this->plainWorkbook(),
            'other.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $this->post('/api/couplings/import', ['file' => $upload], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The coupling file is not in the expected format.')
            ->assertJsonPath('code', 'format');

        $this->assertSame(27, CouplingPump::query()->count());
        $this->assertSame(47, CouplingRule::query()->count());
    }

    public function test_a_string_shorter_than_two_pieces_returns_422(): void
    {
        $this->importCouplings();

        $this->postJson('/api/couplings/strings', [
            'components' => [
                ['end' => 'pump', 'pump' => 'ESP B 538-5000'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['components']);
    }

    private function importCouplings(): void
    {
        app(ImportCouplings::class)->fromWorkbook(database_path('data/coupling.xlsx'));
    }

    private function plainWorkbook(): string
    {
        $path = storage_path('framework/testing/plain-coupling.xlsx');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Hello</t></is></c></row></sheetData></worksheet>');
        $zip->close();

        return $path;
    }
}
