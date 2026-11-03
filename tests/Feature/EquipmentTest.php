<?php

namespace Tests\Feature;

use App\Models\Equipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_equipment_page_is_served(): void
    {
        $this->get('/equipment')->assertOk()->assertSee('id="app"', false);
    }

    public function test_valid_equipment_is_stored_and_listed(): void
    {
        $response = $this->postJson('/api/equipment', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('equipment.port_number', 'P-14')
            ->assertJsonPath('equipment.condition', 'new')
            ->assertJsonPath('equipment.tested_at', null);

        $this->assertDatabaseHas('equipment', [
            'port_number' => 'P-14',
            'serial_number' => 'SN-9',
            'assigned_well' => 'Lekhwair-7',
        ]);

        $this->getJson('/api/equipment')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.new', 1)
            ->assertJsonPath('summary.untested', 1)
            ->assertJsonPath('equipment.0.location', 'Rack A');
    }

    public function test_missing_port_number_returns_422(): void
    {
        $this->postJson('/api/equipment', ['condition' => 'new', 'recorded_on' => '2026-11-03'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['port_number']);
    }

    public function test_invalid_condition_returns_422(): void
    {
        $this->postJson('/api/equipment', $this->payload(['condition' => 'used']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['condition']);

        $this->assertSame(0, Equipment::query()->count());
    }

    public function test_filters_match_each_field_and_treat_percent_as_text(): void
    {
        Equipment::factory()->create([
            'port_number' => '100%A',
            'serial_number' => 'SER-1',
            'description' => 'Motor lead',
            'assigned_well' => 'Lekhwair-7',
            'recorded_on' => '2026-10-01',
            'location' => 'Rack A',
            'condition' => 'new',
            'tested_at' => null,
        ]);
        Equipment::factory()->create([
            'port_number' => '100ZA',
            'serial_number' => 'SER-2',
            'description' => 'Seal housing',
            'assigned_well' => 'Yibal-3',
            'recorded_on' => '2026-11-02',
            'location' => 'Rack B',
            'condition' => 'repaired',
            'tested_at' => '2026-11-01',
        ]);

        $this->getJson('/api/equipment?'.http_build_query(['port_number' => '100%']))
            ->assertOk()
            ->assertJsonCount(1, 'equipment')
            ->assertJsonPath('equipment.0.port_number', '100%A');

        $this->getJson('/api/equipment?serial_number=SER-2')->assertJsonPath('equipment.0.serial_number', 'SER-2');
        $this->getJson('/api/equipment?description=Seal')->assertJsonPath('equipment.0.description', 'Seal housing');
        $this->getJson('/api/equipment?assigned_well=Yibal')->assertJsonPath('equipment.0.assigned_well', 'Yibal-3');
        $this->getJson('/api/equipment?'.http_build_query(['location' => 'Rack A']))->assertJsonPath('equipment.0.location', 'Rack A');
        $this->getJson('/api/equipment?condition=repaired')->assertJsonPath('summary.repaired', 1)->assertJsonCount(1, 'equipment');
        $this->getJson('/api/equipment?date_from=2026-11-01&date_to=2026-11-30')->assertJsonPath('equipment.0.port_number', '100ZA');
        $this->getJson('/api/equipment?tested=no')->assertJsonPath('equipment.0.serial_number', 'SER-1');
        $this->getJson('/api/equipment?tested=yes&tested_from=2026-11-01&tested_to=2026-11-01')
            ->assertJsonPath('equipment.0.tested_at', '2026-11-01');
    }

    public function test_bulk_import_inserts_valid_rows_and_rejects_the_whole_file_when_one_row_is_invalid(): void
    {
        $this->postJson('/api/equipment/import', [
            'rows' => [
                $this->payload(),
                $this->payload(['port_number' => 'P-15', 'recorded_on' => 'not-a-date']),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['rows.1.recorded_on']);

        $this->assertSame(0, Equipment::query()->count());

        $this->postJson('/api/equipment/import', [
            'rows' => [
                $this->payload(),
                $this->payload([
                    'port_number' => 'P-15',
                    'serial_number' => 'SN-15',
                    'condition' => 'Needs inspection',
                    'tested_at' => '2026-10-20',
                ]),
            ],
        ])->assertCreated()->assertJsonPath('imported', 2);

        $this->assertSame(2, Equipment::query()->count());
        $this->assertDatabaseHas('equipment', [
            'port_number' => 'P-15',
            'condition' => 'Needs inspection',
            'tested_at' => '2026-10-20',
        ]);
    }

    public function test_import_stores_blank_cells_as_empty(): void
    {
        $this->postJson('/api/equipment/import', [
            'rows' => [
                [
                    'port_number' => 'P-20',
                    'serial_number' => null,
                    'description' => null,
                    'assigned_well' => null,
                    'recorded_on' => null,
                    'location' => null,
                    'condition' => null,
                    'tested_at' => null,
                ],
                [
                    'port_number' => null,
                    'serial_number' => 'SN-ONLY',
                    'description' => '',
                    'assigned_well' => null,
                    'recorded_on' => '',
                    'location' => null,
                    'condition' => '',
                    'tested_at' => null,
                ],
            ],
        ])->assertCreated()->assertJsonPath('imported', 2);

        $this->assertDatabaseHas('equipment', [
            'port_number' => 'P-20',
            'serial_number' => null,
            'recorded_on' => null,
            'condition' => null,
            'tested_at' => null,
        ]);
        $this->assertDatabaseHas('equipment', [
            'port_number' => null,
            'serial_number' => 'SN-ONLY',
            'recorded_on' => null,
            'condition' => null,
        ]);
    }

    public function test_import_skips_equipment_that_was_already_saved(): void
    {
        $this->postJson('/api/equipment/import', [
            'rows' => [
                $this->payload(['serial_number' => 'SN-1']),
                $this->payload(['port_number' => 'P-15', 'serial_number' => 'SN-1']),
                $this->payload(['port_number' => 'P-20', 'serial_number' => null, 'description' => 'Loose lead']),
            ],
        ])->assertCreated()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('skipped', 1);

        $this->assertSame(2, Equipment::query()->count());

        $this->postJson('/api/equipment/import', [
            'rows' => [
                $this->payload(['serial_number' => 'sn-1', 'location' => 'Rack Z']),
                $this->payload(['port_number' => 'P-20', 'serial_number' => null, 'description' => 'Loose lead']),
                $this->payload(['port_number' => 'P-21', 'serial_number' => 'SN-2']),
                $this->payload(['port_number' => 'P-22', 'serial_number' => null, 'description' => 'Seal kit']),
            ],
        ])->assertCreated()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('skipped', 2);

        $this->assertSame(4, Equipment::query()->count());
        $this->assertSame(1, Equipment::query()->where('serial_number', 'SN-1')->count());
        $this->assertDatabaseHas('equipment', ['serial_number' => 'SN-1', 'location' => 'Rack A']);
        $this->assertDatabaseHas('equipment', ['serial_number' => 'SN-2', 'port_number' => 'P-21']);
    }

    public function test_sending_to_test_writes_today_once(): void
    {
        $this->travelTo('2026-11-03 09:00:00');
        $equipment = Equipment::factory()->create(['tested_at' => null]);

        $this->postJson("/api/equipment/{$equipment->id}/test")
            ->assertOk()
            ->assertJsonPath('equipment.tested_at', '2026-11-03');

        $this->travelTo('2026-11-04 09:00:00');

        $this->postJson("/api/equipment/{$equipment->id}/test")
            ->assertOk()
            ->assertJsonPath('equipment.tested_at', '2026-11-03');
    }

    public function test_update_keeps_the_test_date_and_delete_removes_the_row(): void
    {
        $equipment = Equipment::factory()->tested('2026-01-02')->create([
            'port_number' => 'P-1',
            'recorded_on' => '2026-01-01',
            'condition' => 'new',
        ]);

        $this->putJson("/api/equipment/{$equipment->id}", $this->payload([
            'port_number' => 'P-2',
            'tested_at' => '2026-05-05',
        ]))->assertOk()
            ->assertJsonPath('equipment.port_number', 'P-2')
            ->assertJsonPath('equipment.tested_at', '2026-01-02');

        $this->deleteJson("/api/equipment/{$equipment->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, Equipment::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'port_number' => 'P-14',
            'serial_number' => 'SN-9',
            'description' => 'Pump stage',
            'assigned_well' => 'Lekhwair-7',
            'recorded_on' => '2026-11-03',
            'location' => 'Rack A',
            'condition' => 'new',
        ], $overrides);
    }
}
