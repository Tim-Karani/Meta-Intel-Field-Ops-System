<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function ok(mixed $data = null, string $message = 'Operation completed successfully.', array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data ?? new \stdClass, 'meta' => (object) $meta], $status);
    }

    public static function created(mixed $data, string $message = 'Created successfully.'): JsonResponse
    {
        return self::ok($data, $message, [], 201);
    }

    public static function paginated(LengthAwarePaginator $page, array $items, array $extraMeta = []): JsonResponse
    {
        return self::ok($items, 'OK', array_merge([
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ], $extraMeta));
    }

    public static function error(string $message, int $status, array $errors = [], ?string $code = null): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false, 'message' => $message, 'code' => $code, 'errors' => (object) $errors,
            'request_id' => request()->attributes->get('request_id'),
        ], fn ($v) => $v !== null), $status);
    }
}
