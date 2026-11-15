<?php

namespace Tests\Feature;

use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_part_is_stored_and_listed_in_the_catalog(): void
    {
        $response = $this->postJson('/api/parts', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('part.pn', 'PN-14')
            ->assertJsonPath('part.id', '485561')
            ->assertJsonPath('part.d', 'Impeller')
            ->assertJsonPath('part.cats.0', 'pump')
            ->assertJsonPath('part.pr', 'HIGH')
            ->assertJsonPath('part.cr', 1)
            ->assertJsonPath('part.L.0.0', 'G1A1')
            ->assertJsonPath('part.L.0.1', 5)
            ->assertJsonPath('part.L.0.4', 'New');

        $id = Part::query()->value('id');

        $this->getJson('/api/catalog')
            ->assertOk()
            ->assertJsonPath('parts.0.pk', $id)
            ->assertJsonPath('parts.0.pn', 'PN-14');
    }

    public function test_a_part_can_be_stored_with_only_a_part_number(): void
    {
        $this->postJson('/api/parts', [
            'part_number' => 'ONLY-PN',
            'description' => 'Washer',
            'locations' => [],
        ])->assertCreated()
            ->assertJsonPath('part.pn', 'ONLY-PN')
            ->assertJsonPath('part.id', '')
            ->assertJsonPath('part.L', []);

        $part = Part::query()->first();

        $this->assertSame('ONLY-PN', $part->part_number);
        $this->assertNull($part->item_code);
        $this->assertFalse($part->is_critical);
    }

    public function test_missing_description_and_codes_are_rejected(): void
    {
        $this->postJson('/api/parts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description', 'item_code', 'part_number']);

        $this->assertSame(0, Part::query()->count());
    }

    public function test_invalid_category_and_quantity_are_rejected(): void
    {
        $this->postJson('/api/parts', $this->payload(['categories' => ['bolt']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['categories.0']);

        $this->postJson('/api/parts', $this->payload([
            'locations' => [['location' => 'A1', 'quantity' => -1]],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['locations.0.quantity']);

        $this->assertSame(0, Part::query()->count());
    }

    public function test_update_replaces_locations_and_keeps_fields_the_form_does_not_edit(): void
    {
        $part = Part::query()->create([
            'item_code' => '10',
            'part_number' => 'PN-1',
            'description' => 'Sleeve',
            'categories' => ['seal'],
            'is_critical' => true,
            'min_qty' => '4',
            'alternatives' => ['PN-2'],
            'sort_order' => 1,
        ]);
        $part->locations()->create([
            'position' => 0,
            'location' => 'OLD',
            'quantity' => 3,
        ]);

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'item_code' => '10',
            'part_number' => 'PN-1',
            'description' => 'Sleeve updated',
            'categories' => ['motor'],
            'locations' => [[
                'location' => 'A1',
                'quantity' => 2,
                'condition' => 'New',
            ]],
        ]))->assertOk()
            ->assertJsonPath('part.d', 'Sleeve updated')
            ->assertJsonPath('part.cats.0', 'motor')
            ->assertJsonPath('part.L.0.0', 'A1')
            ->assertJsonPath('part.L.0.1', 2)
            ->assertJsonPath('part.mn', '4')
            ->assertJsonPath('part.alt.0', 'PN-2')
            ->assertJsonMissingPath('part.L.1');

        $this->assertDatabaseMissing('part_locations', ['location' => 'OLD']);
        $this->assertSame(1, $part->locations()->count());
        $this->assertSame('4', $part->fresh()->min_qty);
    }

    public function test_added_quantity_increases_the_stored_quantity_and_a_later_addition_uses_that_total(): void
    {
        $part = Part::query()->create([
            'part_number' => 'PN-ADD',
            'description' => 'Impeller',
            'categories' => [],
            'sort_order' => 1,
        ]);
        $location = $part->locations()->create([
            'position' => 0,
            'location' => 'G1A1',
            'quantity' => 100,
            'quantity_raw' => '100',
            'condition' => 'New',
        ]);

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'part_number' => 'PN-ADD',
            'locations' => [[
                'id' => $location->id,
                'location' => 'G1A1',
                'quantity' => 25,
                'condition' => 'New',
            ]],
        ]))->assertOk()
            ->assertJsonPath('part.L.0.1', 125)
            ->assertJsonPath('part.L.0.2', '')
            ->assertJsonPath('part.L.0.7', $location->id);

        $this->assertSame('125.00', $location->fresh()->quantity);

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'part_number' => 'PN-ADD',
            'locations' => [[
                'id' => $location->id,
                'location' => 'G1A1',
                'quantity' => 10,
            ]],
        ]))->assertOk()
            ->assertJsonPath('part.L.0.1', 135);

        $this->assertSame('New', $location->fresh()->condition);
    }

    public function test_a_new_location_starts_at_its_quantity_and_an_untouched_location_stays(): void
    {
        $part = Part::query()->create([
            'part_number' => 'PN-KEEP',
            'description' => 'Sleeve',
            'categories' => [],
            'sort_order' => 1,
        ]);
        $location = $part->locations()->create([
            'position' => 0,
            'location' => 'OLD',
            'quantity' => 8,
            'quantity_raw' => '8',
        ]);

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'part_number' => 'PN-KEEP',
            'locations' => [
                ['id' => $location->id, 'location' => 'OLD', 'quantity' => 0],
                ['location' => 'NEW', 'quantity' => 4],
            ],
        ]))->assertOk()
            ->assertJsonPath('part.L.0.0', 'OLD')
            ->assertJsonPath('part.L.0.1', 8)
            ->assertJsonPath('part.L.0.2', '8')
            ->assertJsonPath('part.L.1.0', 'NEW')
            ->assertJsonPath('part.L.1.1', 4);

        $this->assertSame(2, $part->locations()->count());
    }

    public function test_a_location_from_another_part_cannot_be_increased(): void
    {
        $part = Part::query()->create([
            'part_number' => 'PN-A',
            'description' => 'Sleeve',
            'categories' => [],
            'sort_order' => 1,
        ]);
        $other = Part::query()->create([
            'part_number' => 'PN-B',
            'description' => 'Washer',
            'categories' => [],
            'sort_order' => 2,
        ]);
        $foreign = $other->locations()->create([
            'position' => 0,
            'location' => 'B1',
            'quantity' => 3,
        ]);

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'part_number' => 'PN-A',
            'locations' => [[
                'id' => $foreign->id,
                'location' => 'B1',
                'quantity' => 9,
            ]],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['locations.0.id']);

        $this->assertSame('3.00', $foreign->fresh()->quantity);
        $this->assertSame(0, $part->locations()->count());
    }

    public function test_replacing_the_current_quantity_sets_it_and_records_the_change(): void
    {
        $part = Part::query()->create([
            'part_number' => 'PN-SET',
            'description' => 'Impeller',
            'categories' => [],
            'sort_order' => 1,
        ]);
        $location = $part->locations()->create([
            'position' => 0,
            'location' => 'G1A1',
            'quantity' => 100,
            'quantity_raw' => '100',
        ]);
        $other = Part::query()->create([
            'part_number' => 'PN-OTHER',
            'description' => 'Sleeve',
            'categories' => [],
            'sort_order' => 2,
        ]);

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'part_number' => 'PN-SET',
            'locations' => [[
                'id' => $location->id,
                'location' => 'G1A1',
                'quantity' => 100,
                'replace' => true,
            ]],
        ]))->assertOk()
            ->assertJsonPath('part.L.0.1', 100);

        $this->assertSame(0, $part->adjustments()->count());

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'part_number' => 'PN-SET',
            'locations' => [[
                'id' => $location->id,
                'location' => 'G1A1',
                'quantity' => 27,
                'replace' => true,
            ]],
        ]))->assertOk()
            ->assertJsonPath('part.L.0.1', 27)
            ->assertJsonPath('part.L.0.2', '');

        $this->assertSame('27.00', $location->fresh()->quantity);

        $this->getJson('/api/parts/'.$part->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'set')
            ->assertJsonPath('adjustments.0.location', 'G1A1')
            ->assertJsonPath('adjustments.0.before', 100)
            ->assertJsonPath('adjustments.0.after', 27);

        $this->getJson('/api/parts/'.$other->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(0, 'adjustments');
    }

    public function test_an_added_quantity_is_recorded_and_an_unchanged_save_is_not(): void
    {
        $part = Part::query()->create([
            'part_number' => 'PN-LOG',
            'description' => 'Impeller',
            'categories' => [],
            'sort_order' => 1,
        ]);
        $location = $part->locations()->create([
            'position' => 0,
            'location' => 'G1A1',
            'quantity' => 100,
        ]);

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'part_number' => 'PN-LOG',
            'locations' => [[
                'id' => $location->id,
                'location' => 'G1A1',
                'quantity' => 25,
            ]],
        ]))->assertOk()
            ->assertJsonPath('part.L.0.1', 125);

        $this->putJson('/api/parts/'.$part->id, $this->payload([
            'part_number' => 'PN-LOG',
            'locations' => [[
                'id' => $location->id,
                'location' => 'G1A1',
                'quantity' => 0,
            ]],
        ]))->assertOk()
            ->assertJsonPath('part.L.0.1', 125);

        $this->getJson('/api/parts/'.$part->id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(1, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'add')
            ->assertJsonPath('adjustments.0.before', 100)
            ->assertJsonPath('adjustments.0.after', 125);
    }

    public function test_opening_and_removing_a_stocked_location_are_recorded(): void
    {
        $created = $this->postJson('/api/parts', $this->payload([
            'locations' => [['location' => 'A1', 'quantity' => 8]],
        ]))->assertCreated();
        $id = $created->json('part.pk');

        $this->putJson('/api/parts/'.$id, $this->payload([
            'locations' => [],
        ]))->assertOk()
            ->assertJsonPath('part.L', []);

        $this->getJson('/api/parts/'.$id.'/adjustments')
            ->assertOk()
            ->assertJsonCount(2, 'adjustments')
            ->assertJsonPath('adjustments.0.action', 'remove')
            ->assertJsonPath('adjustments.0.location', 'A1')
            ->assertJsonPath('adjustments.0.before', 8)
            ->assertJsonPath('adjustments.0.after', 0)
            ->assertJsonPath('adjustments.1.action', 'open')
            ->assertJsonPath('adjustments.1.before', 0)
            ->assertJsonPath('adjustments.1.after', 8);
    }

    public function test_a_zero_quantity_location_is_not_recorded(): void
    {
        $created = $this->postJson('/api/parts', $this->payload([
            'locations' => [['location' => 'A1', 'quantity' => 0]],
        ]))->assertCreated();

        $this->getJson('/api/parts/'.$created->json('part.pk').'/adjustments')
            ->assertOk()
            ->assertJsonCount(0, 'adjustments');
    }

    public function test_a_part_and_its_locations_can_be_deleted(): void
    {
        $created = $this->postJson('/api/parts', $this->payload())->assertCreated();
        $id = $created->json('part.pk');

        $this->deleteJson('/api/parts/'.$id)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('parts', ['id' => $id]);
        $this->assertDatabaseMissing('part_locations', ['part_id' => $id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'item_code' => '485561',
            'part_number' => 'PN-14',
            'description' => 'Impeller',
            'uom' => 'EA',
            'store_type' => 'Pump',
            'categories' => ['pump'],
            'priority' => 'HIGH',
            'critical' => true,
            'notes' => 'Shelf note',
            'locations' => [[
                'location' => 'G1A1',
                'quantity' => 5,
                'condition' => 'New',
            ]],
        ], $overrides);
    }
}
