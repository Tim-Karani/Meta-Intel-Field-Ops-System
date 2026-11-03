<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GET /places/search?q= — finds a shop, mall or address by name so the office can drop the outlet pin on it instead of
 * hunting across the map. Uses OpenStreetMap's public search from the server (the browser may only talk to us), one
 * search per button press, results cached for a day. Coverage of small shops in OpenStreetMap is partial; a search that
 * finds nothing falls back to clicking the map or pasting coordinates.
 */
class PlaceSearchController extends ApiController
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:3', 'max:120']])['q']);
        $countries = (string) config('field.place_search_countries', 'ke');
        $key = 'place-search:'.md5(mb_strtolower($q).'|'.$countries);

        $rows = Cache::remember($key, now()->addDay(), function () use ($q, $countries) {
            $res = Http::timeout(8)->acceptJson()
                ->withHeaders(['User-Agent' => config('app.name', 'MetaIntel').' field-operations (place search)'])
                ->get('https://nominatim.openstreetmap.org/search', array_filter(['q' => $q, 'format' => 'jsonv2', 'limit' => 8, 'countrycodes' => $countries ?: null, 'addressdetails' => 0]));
            if (!$res->ok()) {
                return null;
            }

            return collect($res->json())->map(fn ($r) => [
                'name' => $r['name'] ?? explode(',', (string) ($r['display_name'] ?? ''))[0],
                'address' => $r['display_name'] ?? '',
                'type' => $r['type'] ?? null,
                'lat' => round((float) $r['lat'], 6),
                'lng' => round((float) $r['lon'], 6),
            ])->filter(fn ($r) => abs($r['lat']) <= 90 && abs($r['lng']) <= 180)->values()->all();
        });

        if ($rows === null) {
            Cache::forget($key);
            return response()->json(['success' => false, 'message' => 'Place search is not available right now. Click the map or paste coordinates instead.', 'code' => 'place_search_unavailable'], 503);
        }

        return $this->ok($rows);
    }
}
