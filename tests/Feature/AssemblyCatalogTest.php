<?php

namespace Tests\Feature;

use App\Actions\ImportAssemblies;
use App\Models\Assembly;
use App\Models\AssemblyLine;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AssemblyCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_returns_the_motor_seal_lists_linked_to_existing_parts(): void
    {
        $head = Part::query()->create([
            'item_code' => '368863',
            'part_number' => '10011674',
            'description' => 'Head from catalog',
            'uom' => 'EA',
            'categories' => [],
            'sort_order' => 1,
        ]);
        Part::query()->create([
            'item_code' => '320298',
            'part_number' => '10011547',
            'description' => 'Housing from catalog',
            'uom' => 'EA',
            'categories' => [],
            'sort_order' => 2,
        ]);

        app(ImportAssemblies::class)->fromJson(database_path('data/assemblies.json'));

        $response = $this->getJson('/api/catalog');

        $response->assertOk()
            ->assertJsonPath('assemblies.0.pn', '10071317')
            ->assertJsonPath('assemblies.0.d', 'Motor Seal, ESP B 538 PB136M BSBSL HL MTSC HT')
            ->assertJsonPath('assemblies.0.lines.0.section', 'un')
            ->assertJsonPath('assemblies.0.lines.0.n', 1)
            ->assertJsonPath('assemblies.0.lines.0.pn', '10011674')
            ->assertJsonPath('assemblies.0.lines.0.d', 'Head from catalog')
            ->assertJsonPath('assemblies.0.lines.0.pk', $head->id)
            ->assertJsonPath('assemblies.0.lines.2.id', '320298')
            ->assertJsonPath('assemblies.0.lines.2.pn', '10011547')
            ->assertJsonPath('assemblies.0.lines.2.d', 'Housing from catalog')
            ->assertJsonPath('assemblies.0.lines.11.pk', null)
            ->assertJsonPath('assemblies.0.lines.11.id', '320272')
            ->assertJsonPath('assemblies.0.lines.11.pn', '2036717')
            ->assertJsonPath('assemblies.0.lines.34.section', 'consumables')
            ->assertJsonPath('assemblies.0.lines.34.id', '6944')
            ->assertJsonPath('assemblies.0.lines.34.qty', 14)
            ->assertJsonPath('assemblies.0.lines.50.id', '537851')
            ->assertJsonPath('assemblies.0.lines.50.pn', '')
            ->assertJsonCount(2, 'parts');

        $lines = $response->json('assemblies.0.lines');

        $this->assertCount(62, $lines);
        $this->assertCount(34, array_values(array_filter($lines, fn (array $line): bool => $line['section'] === 'un')));
        $this->assertCount(28, array_values(array_filter($lines, fn (array $line): bool => $line['section'] === 'consumables')));
    }

    public function test_importing_the_assembly_file_again_replaces_the_previous_lines(): void
    {
        $import = app(ImportAssemblies::class);
        $import->fromJson(database_path('data/assemblies.json'));

        $import->fromJson(database_path('data/assemblies.json'));

        $this->assertSame(1, Assembly::query()->count());
        $this->assertSame(62, AssemblyLine::query()->count());
    }

    public function test_a_malformed_assembly_file_keeps_the_stored_lists(): void
    {
        app(ImportAssemblies::class)->fromJson(database_path('data/assemblies.json'));
        $path = tempnam(sys_get_temp_dir(), 'assembly');
        file_put_contents($path, '{"nope":true}');

        try {
            app(ImportAssemblies::class)->fromJson($path);
            $this->fail('The malformed assembly file was imported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The assembly file is not in the expected format.', $exception->getMessage());
            $this->assertSame(1, Assembly::query()->count());
            $this->assertSame(62, AssemblyLine::query()->count());
        } finally {
            unlink($path);
        }
    }
}
