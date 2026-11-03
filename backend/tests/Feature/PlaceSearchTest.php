<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

class PlaceSearchTest extends DatabaseTestCase
{
    public function test_search_returns_places_and_is_permissioned(): void
    {
        Cache::flush();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['name' => 'Example Shop', 'display_name' => 'Example Shop, Example Road, Town', 'lat' => '-1.2900', 'lon' => '36.8000', 'type' => 'supermarket']])]);
        $s = Scenario::build();

        $s->actingAs($s->admin);
        $rows = $this->api('GET', 'places/search', ['q' => 'Example Shop'])->assertOk()->json('data');
        $this->assertSame('Example Shop', $rows[0]['name']);
        $this->assertSame(-1.29, $rows[0]['lat']);
        $this->api('GET', 'places/search', ['q' => 'ab'])->assertStatus(422);

        $s->actingAs($s->agent);
        $this->api('GET', 'places/search', ['q' => 'Example Shop'])->assertStatus(403);
    }

    public function test_a_failed_lookup_is_reported_not_cached(): void
    {
        Cache::flush();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);
        $s = Scenario::build();
        $s->actingAs($s->admin);
        $this->api('GET', 'places/search', ['q' => 'Example Shop'])->assertStatus(503)->assertJsonPath('code', 'place_search_unavailable');
    }
}
