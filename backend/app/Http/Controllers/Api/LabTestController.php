<?php

namespace App\Http\Controllers\Api;

use App\Models\LabTest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class LabTestController extends CrudController
{
    protected string $model = LabTest::class;

    protected string $viewPermission = 'lab.view';

    protected string $managePermission = 'lab.catalogue.manage';

    protected string $orderBy = 'name';

    protected array $searchable = ['name', 'code'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['code' => [$req, 'string', 'max:32'], 'name' => [$req, 'string', 'max:160'], 'loinc_code' => ['nullable', 'string', 'max:16'], 'sample_type' => ['nullable', 'string', 'max:32'], 'turnaround_hours' => ['nullable', 'integer', 'min:1'], 'price' => [$req, 'integer', 'min:0'], 'parameters' => ['nullable', 'array'], 'parameters.*.key' => ['required', 'string'], 'parameters.*.name' => ['required', 'string'], 'is_active' => ['boolean']];
    }
}
