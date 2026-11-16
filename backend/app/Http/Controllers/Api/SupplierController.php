<?php

namespace App\Http\Controllers\Api;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class SupplierController extends CrudController
{
    protected string $model = Supplier::class;

    protected string $viewPermission = 'inventory.view';

    protected string $managePermission = 'inventory.manage';

    protected string $orderBy = 'name';

    protected array $searchable = ['name', 'gstin'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['name' => [$req, 'string', 'max:160'], 'gstin' => ['nullable', 'string', 'size:15'], 'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'array']];
    }
}
