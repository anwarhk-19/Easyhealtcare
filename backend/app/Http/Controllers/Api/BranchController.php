<?php

namespace App\Http\Controllers\Api;

use App\Models\Branch;
use App\Models\OperatingHour;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchController extends CrudController
{
    protected string $model = Branch::class;

    protected string $viewPermission = 'branches.view';

    protected string $managePermission = 'branches.manage';

    protected array $searchable = ['name', 'code'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'code' => [$req, 'string', 'alpha_dash', 'max:16', Rule::unique('branches')->where('organization_id', $this->tenant()->id())->ignore($existing?->id)],
            'address' => ['nullable', 'array'], 'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email'],
            'timezone' => ['nullable', 'timezone'], 'gstin' => ['nullable', 'string', 'size:15'], 'state_code' => ['nullable', 'string', 'max:4'],
            'invoice_prefix' => ['nullable', 'string', 'alpha_dash', 'max:16'], 'is_active' => ['boolean'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($this->managePermission);
        app(SubscriptionService::class)->assertWithinLimit($this->tenant()->organization(), 'branches');

        return parent::store($request);
    }

    public function hours(string $id): JsonResponse
    {
        $this->authorize('branches.view');

        return $this->ok(OperatingHour::where('branch_id', Branch::findOrFail($id)->id)->orderBy('weekday')->orderBy('opens_at')->get());
    }

    public function setHours(Request $request, string $id): JsonResponse
    {
        $this->authorize('schedules.manage');
        $branch = Branch::findOrFail($id);
        $data = $request->validate(['hours' => ['present', 'array'], 'hours.*.weekday' => ['required', 'integer', 'between:0,6'], 'hours.*.opens_at' => ['required', 'date_format:H:i'], 'hours.*.closes_at' => ['required', 'date_format:H:i', 'after:hours.*.opens_at']]);
        DB::transaction(function () use ($branch, $data) {
            OperatingHour::where('branch_id', $branch->id)->delete();
            foreach ($data['hours'] as $h) {
                OperatingHour::create($h + ['branch_id' => $branch->id]);
            }
        });

        return $this->hours($id);
    }
}
