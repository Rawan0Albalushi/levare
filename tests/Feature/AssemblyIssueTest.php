<?php

namespace Tests\Feature;

use App\AssemblySection;
use App\Models\Assembly;
use App\Models\Part;
use App\Models\PartIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssemblyIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_a_line_deducts_locations_in_order_and_records_the_history(): void
    {
        $part = $this->part('368863', '10011674', 'Head');
        $first = $part->locations()->create([
            'position' => 0,
            'location' => 'A1',
            'quantity' => 2,
            'quantity_raw' => '2 EA',
        ]);
        $second = $part->locations()->create([
            'position' => 1,
            'location' => 'B2',
            'quantity' => 5,
        ]);
        $assembly = $this->assembly($part, 3);

        $response = $this->postJson('/api/assemblies/10071317/issues', [
            'positions' => [1],
            'quantity' => 99,
        ]);

        $response->assertCreated()
            ->assertJsonPath('issue.lines.0.n', 1)
            ->assertJsonPath('issue.lines.0.pn', '10011674')
            ->assertJsonPath('issue.lines.0.qty', 3)
            ->assertJsonPath('issue.lines.0.locations.0.loc', 'A1')
            ->assertJsonPath('issue.lines.0.locations.0.qty', 2)
            ->assertJsonPath('issue.lines.0.locations.1.loc', 'B2')
            ->assertJsonPath('issue.lines.0.locations.1.qty', 1);

        $this->assertSame('0.00', $first->refresh()->quantity);
        $this->assertNull($first->quantity_raw);
        $this->assertSame(now()->toDateString(), $first->updated_on);
        $this->assertSame('4.00', $second->refresh()->quantity);
        $this->assertSame(1, PartIssue::query()->count());

        $this->getJson('/api/assemblies/10071317/issues')
            ->assertOk()
            ->assertJsonCount(1, 'issues')
            ->assertJsonPath('issues.0.lines.0.qty', 3);
    }

    public function test_issuing_again_deducts_another_assembly_and_adds_history(): void
    {
        $part = $this->part('368863', '10011674', 'Head');
        $location = $part->locations()->create(['position' => 0, 'location' => 'A1', 'quantity' => 4]);
        $this->assembly($part, 1);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [1]])->assertCreated();
        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [1]])->assertCreated();

        $this->assertSame('2.00', $location->refresh()->quantity);
        $this->assertSame(2, PartIssue::query()->count());
    }

    public function test_a_short_line_returns_422_and_changes_nothing(): void
    {
        $part = $this->part('368863', '10011674', 'Head');
        $location = $part->locations()->create(['position' => 0, 'location' => 'A1', 'quantity' => 2]);
        $this->assembly($part, 3);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [1]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Some lines do not have enough stock.')
            ->assertJsonPath('short.0.n', 1)
            ->assertJsonPath('short.0.need', 3)
            ->assertJsonPath('short.0.have', 2);

        $this->assertSame('2.00', $location->refresh()->quantity);
        $this->assertSame(0, PartIssue::query()->count());
    }

    public function test_one_short_line_blocks_the_whole_group(): void
    {
        $head = $this->part('368863', '10011674', 'Head');
        $shaft = $this->part('438479', '10031586', 'Shaft');
        $headLocation = $head->locations()->create(['position' => 0, 'location' => 'A1', 'quantity' => 5]);
        $shaftLocation = $shaft->locations()->create(['position' => 0, 'location' => 'WS', 'quantity' => 1]);
        $assembly = $this->assembly($head, 10);
        $this->line($assembly, $shaft, 2, 1);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [1, 2]])
            ->assertUnprocessable()
            ->assertJsonPath('short.0.n', 1)
            ->assertJsonPath('short.0.have', 5)
            ->assertJsonMissingPath('short.1');

        $this->assertSame('5.00', $headLocation->refresh()->quantity);
        $this->assertSame('1.00', $shaftLocation->refresh()->quantity);
        $this->assertSame(0, PartIssue::query()->count());
    }

    public function test_two_lines_for_the_same_part_stop_before_the_stock_runs_out(): void
    {
        $part = $this->part('320304', '10010593', 'Tube');
        $location = $part->locations()->create(['position' => 0, 'location' => 'S1', 'quantity' => 5]);
        $assembly = $this->assembly($part, 3);
        $this->line($assembly, $part, 2, 3);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [1, 2]])
            ->assertUnprocessable()
            ->assertJsonPath('short.0.n', 2)
            ->assertJsonPath('short.0.have', 2);

        $this->assertSame('5.00', $location->refresh()->quantity);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [1, 2]])
            ->assertUnprocessable();

        $part->locations()->update(['quantity' => 6]);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [2, 1]])
            ->assertCreated()
            ->assertJsonCount(2, 'issue.lines')
            ->assertJsonPath('issue.lines.0.n', 1)
            ->assertJsonPath('issue.lines.1.n', 2);

        $this->assertSame('0.00', $location->refresh()->quantity);
    }

    public function test_an_unknown_line_or_a_line_missing_from_the_catalog_returns_422(): void
    {
        $part = $this->part('368863', '10011674', 'Head');
        $location = $part->locations()->create(['position' => 0, 'location' => 'A1', 'quantity' => 4]);
        $assembly = $this->assembly($part, 1);
        $assembly->lines()->create([
            'section' => AssemblySection::Un,
            'position' => 2,
            'item_code' => '999',
            'part_number' => 'MISSING',
            'description' => 'Not stocked',
            'uom' => 'EA',
            'quantity' => 1,
        ]);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [8]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['positions']);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [2]])
            ->assertUnprocessable()
            ->assertJsonPath('short.0.n', 2)
            ->assertJsonPath('short.0.have', 0);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['positions']);

        $this->assertSame('4.00', $location->refresh()->quantity);
        $this->assertSame(0, PartIssue::query()->count());
    }

    public function test_history_lists_only_the_requested_assembly(): void
    {
        $part = $this->part('368863', '10011674', 'Head');
        $part->locations()->create(['position' => 0, 'location' => 'A1', 'quantity' => 3]);
        $this->assembly($part, 1);
        $other = Assembly::query()->create(['part_number' => '200', 'description' => 'Other']);
        $this->line($other, $part, 1, 1);

        $this->postJson('/api/assemblies/10071317/issues', ['positions' => [1]])->assertCreated();

        $this->getJson('/api/assemblies/200/issues')
            ->assertOk()
            ->assertJsonCount(0, 'issues');

        $this->getJson('/api/assemblies/missing/issues')->assertNotFound();
    }

    private function part(string $code, string $number, string $description): Part
    {
        return Part::query()->create([
            'item_code' => $code,
            'part_number' => $number,
            'description' => $description,
            'uom' => 'EA',
            'categories' => [],
            'sort_order' => 1,
        ]);
    }

    private function assembly(Part $part, int|float $quantity): Assembly
    {
        $assembly = Assembly::query()->create([
            'part_number' => '10071317',
            'description' => 'Motor Seal',
        ]);
        $this->line($assembly, $part, 1, $quantity);

        return $assembly;
    }

    private function line(Assembly $assembly, Part $part, int $position, int|float $quantity): void
    {
        $assembly->lines()->create([
            'section' => AssemblySection::Un,
            'position' => $position,
            'item_code' => $part->item_code,
            'part_number' => $part->part_number,
            'description' => $part->description,
            'uom' => 'EA',
            'quantity' => $quantity,
        ]);
    }
}
