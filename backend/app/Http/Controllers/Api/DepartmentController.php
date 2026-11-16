<?php

namespace App\Http\Controllers\Api;

use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class DepartmentController extends CrudController
{
    protected string $model = Department::class;

    protected string $viewPermission = 'services.view';

    protected string $managePermission = 'services.manage';

    protected string $orderBy = 'name';

    protected array $searchable = ['name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['name' => [$req, 'string', 'max:120'], 'speciality_id' => ['nullable', 'exists:specialities,id']];
    }
}
