<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class InventoryCategoryController extends CrudController
{
    protected string $model = InventoryCategory::class;

    protected string $viewPermission = 'inventory.view';

    protected string $managePermission = 'inventory.manage';

    protected string $orderBy = 'name';

    protected array $searchable = ['name'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['name' => [$req, 'string', 'max:120']];
    }
}
