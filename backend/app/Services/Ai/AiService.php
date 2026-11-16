<?php

namespace App\Services\Ai;

use App\Exceptions\BusinessRuleException;
use App\Models\AiRequest;
use App\Services\ReportService;
use App\Support\Tenancy\TenantContext;

/**
 * Optional, opt-in AI assistance for administrative work only.
 *
 * Guardrails (product rule, enforced here):
 *  - Disabled unless the plan includes `ai` AND the organisation has switched `ai_enabled` on.
 *  - Never diagnoses, prescribes or edits clinical records; output is a draft a human must accept.
 *  - analytics_summary only receives aggregated, non-identifying numbers.
 *  - Features that may contain patient-identifiable text require the org's `ai_phi_allowed` (DPA accepted).
 *  - Every call is logged (prompt/response encrypted at rest).
 */
class AiService
{
    public const FEATURES = [
        'analytics_summary' => ['phi' => false, 'max_tokens' => 1500],
        'message_draft' => ['phi' => true, 'max_tokens' => 800],
        'patient_faq' => ['phi' => false, 'max_tokens' => 600],
    ];

    private const GUARDRAIL = 'You assist staff of a medical clinic with administrative work only. '
        .'Never provide a diagnosis, interpret clinical findings, recommend or change medicines or doses, or give treatment advice. '
        .'If asked for clinical judgement, say that a doctor must decide. Be concise and factual; do not invent numbers.';

    public function __construct(private TenantContext $context, private AiProvider $provider, private ReportService $reports) {}

    public function run(string $feature, array $input): array
    {
        $org = $this->context->organization();
        if (! isset(self::FEATURES[$feature])) {
            throw new BusinessRuleException('UNKNOWN_AI_FEATURE', 'Unknown AI feature.');
        }
        if (! $org->hasFeature('ai') || ! $org->setting('ai_enabled')) {
            throw new BusinessRuleException('AI_DISABLED', 'AI features are not enabled for this clinic.', 402);
        }
        $spec = self::FEATURES[$feature];
        if ($spec['phi'] && ! $org->setting('ai_phi_allowed')) {
            throw new BusinessRuleException('AI_PHI_NOT_ALLOWED', 'This feature may process patient information. The clinic owner must accept the AI data-processing terms first.', 403);
        }

        [$system, $prompt] = match ($feature) {
            'analytics_summary' => [
                self::GUARDRAIL.' Summarise clinic business performance from aggregated metrics for the clinic owner: 3–6 bullet insights and 2–3 operational suggestions. Amounts are in paise (divide by 100 for rupees).',
                "Clinic metrics (aggregated, no patient data):\n".json_encode($this->reports->dashboard($input['from'] ?? null, $input['to'] ?? null, $input['branch_id'] ?? null), JSON_PRETTY_PRINT),
            ],
            'message_draft' => [
                self::GUARDRAIL.' Draft a short, polite message from the clinic to a patient (WhatsApp/SMS length). Use only facts given. No medical advice.',
                'Purpose: '.($input['purpose'] ?? 'general update')."\nFacts:\n".($input['facts'] ?? ''),
            ],
            'patient_faq' => [
                self::GUARDRAIL.' Answer a patient\'s general question about the clinic (timings, services, booking, location) using only the clinic information provided. For anything medical, advise booking a consultation.',
                'Clinic info: '.json_encode(['name' => $org->name, 'address' => $org->address, 'phone' => $org->phone])."\nQuestion: ".($input['question'] ?? ''),
            ],
        };

        $result = $this->provider->complete($system, $prompt, $spec['max_tokens']);

        $log = AiRequest::create([
            'user_id' => auth()->id(),
            'feature' => $feature,
            'provider' => $result->provider,
            'model' => $result->model,
            'contains_phi' => $spec['phi'],
            'prompt' => $prompt,
            'response' => $result->text,
            'tokens_in' => $result->tokensIn,
            'tokens_out' => $result->tokensOut,
            'status' => $result->refused ? 'refused' : 'completed',
        ]);

        return ['id' => $log->id, 'feature' => $feature, 'draft' => $result->text, 'refused' => $result->refused, 'requires_review' => true, 'notice' => 'AI draft — review before use.'];
    }

    public function accept(AiRequest $request): AiRequest
    {
        $request->update(['accepted_at' => now()]);

        return $request;
    }
}
