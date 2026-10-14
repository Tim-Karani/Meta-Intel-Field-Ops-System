<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, string $message = 'Operation completed successfully.', array $meta = []): JsonResponse
    {
        return ApiResponse::ok($data, $message, $meta);
    }

    protected function created(mixed $data, string $message = 'Created successfully.'): JsonResponse
    {
        return ApiResponse::created($data, $message);
    }

    /** IDs are UUIDs; anything else is a 404 (this also avoids a PostgreSQL cast error). */
    protected function assertUuid(string $id): void
    {
        abort_unless(Str::isUuid($id), 404);
    }

    /** A query-string filter value; UUID-typed filters are validated so a bad value is a 422, never a database error. */
    protected function filterValue(Request $request, string $key, bool $uuid = false): ?string
    {
        $value = $request->query($key);
        if (!is_string($value) || $value === '') {
            return null;
        }
        if ($uuid && !Str::isUuid($value)) {
            throw ValidationException::withMessages([$key => ['The '.str_replace('_', ' ', $key).' must be a valid UUID.']]);
        }

        return $value;
    }

    protected function searchTerm(Request $request): string
    {
        $term = $request->query('search');

        return is_string($term) ? trim(mb_substr($term, 0, 100)) : '';
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 20)));
    }
}
