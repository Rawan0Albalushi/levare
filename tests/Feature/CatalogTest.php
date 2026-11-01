<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\Stocktake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_storefront_shell_is_served(): void
    {
        $this->get('/')->assertOk()->assertSee('id="app"', false);
        $this->get('/stock')->assertOk();
    }

    public function test_catalog_returns_imported_parts(): void
    {
        $this->seed();

        $response = $this->getJson('/api/catalog');

        $response->assertOk()
            ->assertJsonPath('source.store', 'STORE SPARE PARTS 4.10.26')
            ->assertJsonPath('parts.0.id', '485560')
            ->assertJsonPath('parts.0.L.0.0', 'G3C4')
            ->assertJsonPath('parts.0.L.0.1', 100);

        $this->assertGreaterThan(1500, Part::query()->count());
        $response->assertJsonFragment(['pn' => '10011674']);
    }

    public function test_a_stocktake_can_be_saved_and_updated(): void
    {
        $document = [
            'v' => 2,
            'id' => 'STK-TEST1234',
            'name' => 'November count',
            'days' => 15,
            'start' => '2026-11-01',
            'cat' => 'all',
            'created' => '2026-11-01T10:00:00Z',
            'status' => 'prog',
            'open' => null,
            'finished' => null,
            'shifts' => [],
            'sur' => [],
            'locs' => [[
                'n' => 'S1D5',
                'e' => false,
                'done' => false,
                'res' => null,
                'lines' => [[
                    'pn' => '10013099',
                    'id' => '1',
                    'd' => 'Sleeve',
                    'u' => 'EA',
                    'exp' => 4,
                    'r' => null,
                    'c' => null,
                ]],
            ]],
        ];

        $this->postJson('/api/stocktakes', $document)
            ->assertCreated()
            ->assertJsonPath('stocktake.id', 'STK-TEST1234')
            ->assertJsonPath('stocktake.locs.0.lines.0.pn', '10013099');

        $document['locs'][0]['lines'][0]['r'] = 'match';
        $document['locs'][0]['done'] = true;

        $this->putJson('/api/stocktakes/STK-TEST1234', $document)
            ->assertOk()
            ->assertJsonPath('stocktake.locs.0.lines.0.r', 'match');

        $this->assertSame('prog', Stocktake::query()->first()->status);
    }
}
