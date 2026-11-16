<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryItemController extends CrudController
{
    protected string $model = InventoryItem::class;

    protected string $viewPermission = 'inventory.view';

    protected string $managePermission = 'inventory.manage';

    protected string $orderBy = 'name';

    protected array $searchable = ['name', 'sku'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['name' => [$req, 'string', 'max:160'], 'sku' => [$req, 'string', 'max:32', Rule::unique('inventory_items')->where('organization_id', app(TenantContext::class)->id())->ignore($existing?->id)], 'category_id' => ['nullable', Rule::exists('inventory_categories', 'id')->where('organization_id', app(TenantContext::class)->id())], 'medicine_id' => ['nullable', 'exists:medicines,id'], 'unit' => ['nullable', 'string', 'max:16'], 'hsn' => ['nullable', 'string', 'max:16'], 'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:28'], 'selling_price' => ['nullable', 'integer', 'min:0'], 'reorder_level' => ['nullable', 'integer', 'min:0'], 'is_active' => ['boolean']];
    }
}
