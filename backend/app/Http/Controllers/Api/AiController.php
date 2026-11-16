<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Services\Ai\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiController extends Controller
{
    public function run(Request $request, AiService $ai): JsonResponse
    {
        $data = $request->validate([
            'feature' => ['required', Rule::in(array_keys(AiService::FEATURES))],
            'input' => ['nullable', 'array'],
            'input.purpose' => ['nullable', 'string', 'max:200'], 'input.facts' => ['nullable', 'string', 'max:2000'], 'input.question' => ['nullable', 'string', 'max:500'],
            'input.from' => ['nullable', 'date'], 'input.to' => ['nullable', 'date'], 'input.branch_id' => ['nullable', 'string'],
        ]);
        if ($data['feature'] === 'analytics_summary') {
            abort_unless($this->tenant()->can('reports.financial'), 403);
        }

        return $this->ok($ai->run($data['feature'], $data['input'] ?? []));
    }

    public function accept(string $id, AiService $ai): JsonResponse
    {
        $req = AiRequest::where('user_id', auth()->id())->findOrFail($id);

        return $this->ok($ai->accept($req)->only(['id', 'feature', 'accepted_at']));
    }

    public function history(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('ai.manage'), 403);

        return $this->paginated(AiRequest::latest()->paginate($this->perPage($request)));
    }
}
