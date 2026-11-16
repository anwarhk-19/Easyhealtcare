<?php

namespace App\Http\Controllers\Api;

use App\Models\ConsultationTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsultationTemplateController extends CrudController
{
    protected string $model = ConsultationTemplate::class;

    protected string $viewPermission = 'consultations.view';

    protected string $managePermission = 'organization.update';

    protected string $orderBy = 'name';

    protected array $searchable = ['name', 'key'];

    protected function rules(Request $request, ?Model $existing): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return ['key' => [$req, 'string', 'max:64'], 'name' => [$req, 'string', 'max:120'], 'speciality_id' => ['nullable', 'exists:specialities,id'], 'definition' => [$req, 'array'], 'definition.sections' => [$req, 'array', 'min:1'], 'definition.sections.*.key' => ['required', 'string'], 'is_active' => ['boolean']];
    }

    /** Org templates are versioned: an edit creates a new version so signed consultations still render as captured. */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize($this->managePermission);
        $current = ConsultationTemplate::findOrFail($id);
        $data = $request->validate($this->rules($request, $current));
        if ($current->organization_id === null) {
            abort(403, 'Platform templates cannot be edited. Clone it first.');
        }
        $current->update(['is_active' => false]);
        $new = ConsultationTemplate::create(array_merge($current->only(['key', 'name', 'speciality_id', 'definition']), $data, ['version' => $current->version + 1, 'parent_template_id' => $current->id, 'is_active' => true]));

        return $this->ok($new);
    }

    public function clone(string $id): JsonResponse
    {
        $this->authorize($this->managePermission);
        $source = ConsultationTemplate::findOrFail($id);
        $copy = ConsultationTemplate::create($source->only(['key', 'name', 'speciality_id', 'definition']) + ['version' => (int) ConsultationTemplate::where('key', $source->key)->whereNotNull('organization_id')->max('version') + 1, 'parent_template_id' => $source->id]);
        if ($source->organization_id !== null) {
            $source->update(['is_active' => false]);
        }

        return $this->created($copy);
    }
}
