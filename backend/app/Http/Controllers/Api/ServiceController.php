<?php

namespace App\Http\Controllers\Api;

use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceBranchPrice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends CrudController
{
    protected string $model = Service::class;

    protected string $viewPermission = 'services.view';

    protected string $managePermission = 'services.manage';

    protected array $searchable = ['name', 'code'];

    protected array $with = ['taxRate', 'branchPrices'];

    protected function filterable(): array
    {
        return ['type', 'speciality_id', 'is_active'];
    }

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';
        $org = $this->tenant()->id();

        return [
            'code' => [$req, 'string', 'max:32', Rule::unique('services')->where('organization_id', $org)->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:160'],
            'type' => [$req, Rule::in(['consultation', 'procedure', 'diagnostic', 'package', 'other'])],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $org)],
            'speciality_id' => ['nullable', 'exists:specialities,id'],
            'price' => [$req, 'integer', 'min:0'],
            'tax_rate_id' => ['nullable', Rule::exists('tax_rates', 'id')->where('organization_id', $org)],
            'hsn_sac' => ['nullable', 'string', 'max:16'], 'duration_min' => ['nullable', 'integer', 'min:1'], 'is_active' => ['boolean'],
        ];
    }

    public function setBranchPrices(Request $request, string $id): JsonResponse
    {
        $this->authorize($this->managePermission);
        $service = Service::findOrFail($id);
        $data = $request->validate(['prices' => ['present', 'array'], 'prices.*.branch_id' => ['required', 'string'], 'prices.*.price' => ['required', 'integer', 'min:0']]);
        foreach ($data['prices'] as $p) {
            Branch::findOrFail($p['branch_id']);
            ServiceBranchPrice::updateOrCreate(['service_id' => $service->id, 'branch_id' => $p['branch_id']], ['price' => $p['price']]);
        }

        return $this->ok($service->fresh('branchPrices'));
    }
}
