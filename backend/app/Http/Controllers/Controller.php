<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Consistent envelope: {"data": ...}. */
    protected function ok(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['data' => $data] + ($meta ? ['meta' => $meta] : []), $status);
    }

    protected function created(mixed $data): JsonResponse
    {
        return $this->ok($data, 201);
    }

    protected function paginated(LengthAwarePaginator $p): JsonResponse
    {
        return response()->json(['data' => $p->items(), 'meta' => ['pagination' => [
            'page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage(),
        ]]]);
    }

    protected function perPage(Request $r): int
    {
        return max(1, min(100, (int) $r->query('per_page', 25)));
    }

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Branch from request (validated against membership scope) or the user's only/first branch. */
    protected function branchId(Request $r, bool $required = true): ?string
    {
        $id = $r->input('branch_id') ?? $r->query('branch_id') ?? $r->header('X-Branch-Id');
        if ($id && ! $this->tenant()->canAccessBranch($id)) {
            abort(403, 'You do not have access to this branch.');
        }
        if (! $id && $required) {
            $id = $this->tenant()->branchIds()[0] ?? Branch::query()->orderBy('created_at')->value('id');
        }

        return $id;
    }
}
