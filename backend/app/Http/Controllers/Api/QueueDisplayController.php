<?php

namespace App\Http\Controllers\Api;

use App\Models\QueueDisplay;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class QueueDisplayController extends CrudController
{
    protected string $model = QueueDisplay::class;

    protected string $viewPermission = 'queue.view';

    protected string $managePermission = 'queue.manage';

    protected string $orderBy = 'name';

    protected array $searchable = ['name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['name' => [$req, 'string', 'max:80'], 'branch_id' => [$req, Rule::exists('branches', 'id')->where('organization_id', app(TenantContext::class)->id())], 'doctor_ids' => ['nullable', 'array']];
    }

    protected function prepare(array $data, ?Model $existing): array
    {
        return $existing ? $data : $data + ['access_token' => Str::random(40)];
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($this->managePermission);
        $display = QueueDisplay::create($this->prepare($request->validate($this->rules($request, null)), null));

        return $this->created($display->only(['id', 'name', 'branch_id', 'doctor_ids']) + ['display_url' => url('/api/public/v1/display/'.$display->access_token)]);
    }
}
