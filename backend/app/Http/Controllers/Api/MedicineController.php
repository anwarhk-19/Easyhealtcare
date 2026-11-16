<?php

namespace App\Http\Controllers\Api;

use App\Models\Medicine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class MedicineController extends CrudController
{
    protected string $model = Medicine::class;

    protected string $viewPermission = 'consultations.view';

    protected string $managePermission = 'prescriptions.write';

    protected string $orderBy = 'generic_name';

    protected array $searchable = ['generic_name', 'brand_name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['generic_name' => [$req, 'string', 'max:160'], 'brand_name' => ['nullable', 'string', 'max:160'], 'form' => ['nullable', 'string', 'max:32'], 'strength' => ['nullable', 'string', 'max:64'], 'manufacturer' => ['nullable', 'string'], 'schedule' => ['nullable', 'string', 'max:8'], 'drug_class' => ['nullable', 'string']];
    }
}
