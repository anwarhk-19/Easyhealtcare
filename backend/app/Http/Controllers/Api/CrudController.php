<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic tenant-scoped CRUD for master data. Subclasses declare the model, permissions and validation rules.
 * Lookups go through the model's tenant scope, so another tenant's ids resolve to 404.
 */
abstract class CrudController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    protected string $viewPermission;

    protected string $managePermission;

    protected array $searchable = ['name'];

    protected array $with = [];

    protected string $orderBy = 'name';

    abstract protected function rules(Request $request, ?Model $existing): array;

    protected function query(Request $request): Builder
    {
        return $this->model::query()->with($this->with);
    }

    /** Platform rows (organization_id NULL) are read-only for tenants. */
    protected function assertWritable(Model $m): void
    {
        if (array_key_exists('organization_id', $m->getAttributes()) && $m->getAttributes()['organization_id'] === null) {
            abort(403, 'Platform records cannot be modified by a clinic.');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize($this->viewPermission);
        $q = $this->query($request);
        if ($term = $request->query('q')) {
            $q->where(fn ($w) => collect($this->searchable)->each(fn ($c) => $w->orWhere($c, 'like', '%'.$term.'%')));
        }
        foreach ((array) $request->query('filter', []) as $key => $value) {
            if (in_array($key, $this->filterable(), true)) {
                $q->where($key, $value);
            }
        }

        return $this->paginated($q->orderBy($this->orderBy)->paginate($this->perPage($request)));
    }

    protected function filterable(): array
    {
        return [];
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($this->managePermission);
        $data = $request->validate($this->rules($request, null));
        $model = $this->model::create($this->prepare($data, null));

        return $this->created($model->load($this->with));
    }

    public function show(string $id): JsonResponse
    {
        $this->authorize($this->viewPermission);

        return $this->ok($this->model::with($this->with)->findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize($this->managePermission);
        $model = $this->model::findOrFail($id);
        $this->assertWritable($model);
        $data = $request->validate($this->rules($request, $model));
        $model->update($this->prepare($data, $model));

        return $this->ok($model->fresh($this->with));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->authorize($this->managePermission);
        $model = $this->model::findOrFail($id);
        $this->assertWritable($model);
        $model->delete();

        return response()->json(null, 204);
    }

    protected function prepare(array $data, ?Model $existing): array
    {
        return $data;
    }

    protected function authorize(string $ability): void
    {
        abort_unless(app(TenantContext::class)->can($ability), 403, 'This action is not allowed.');
    }
}
