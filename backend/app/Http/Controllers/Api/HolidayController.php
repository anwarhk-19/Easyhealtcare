<?php

namespace App\Http\Controllers\Api;

use App\Models\Holiday;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends CrudController
{
    protected string $model = Holiday::class;

    protected string $viewPermission = 'branches.view';

    protected string $managePermission = 'holidays.manage';

    protected string $orderBy = 'date';

    protected array $searchable = ['name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['date' => [$req, 'date'], 'name' => [$req, 'string', 'max:120'], 'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('organization_id', app(TenantContext::class)->id())]];
    }
}
