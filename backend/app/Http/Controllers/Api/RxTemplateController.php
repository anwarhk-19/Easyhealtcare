<?php

namespace App\Http\Controllers\Api;

use App\Models\RxTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class RxTemplateController extends CrudController
{
    protected string $model = RxTemplate::class;

    protected string $viewPermission = 'consultations.view';

    protected string $managePermission = 'prescriptions.write';

    protected string $orderBy = 'name';

    protected array $searchable = ['name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['name' => [$req, 'string', 'max:120'], 'items' => [$req, 'array', 'min:1'], 'items.*.medicine_name' => ['required', 'string'], 'items.*.frequency' => ['required', 'string'], 'advice' => ['nullable', 'string'], 'doctor_profile_id' => ['nullable', 'exists:doctor_profiles,id']];
    }
}
