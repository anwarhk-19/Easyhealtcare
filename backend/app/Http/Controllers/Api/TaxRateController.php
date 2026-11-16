<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class TaxRateController extends CrudController
{
    protected string $model = TaxRate::class;

    protected string $viewPermission = 'services.view';

    protected string $managePermission = 'services.manage';

    protected string $orderBy = 'name';

    protected array $searchable = ['name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['name' => [$req, 'string', 'max:60'], 'rate' => [$req, 'numeric', 'min:0', 'max:28'], 'is_default' => ['boolean']];
    }
}
