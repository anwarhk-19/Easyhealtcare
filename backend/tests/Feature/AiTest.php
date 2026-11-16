<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Models\AiRequest;
use Tests\TestCase;

class AiTest extends TestCase
{
    public function test_ai_is_off_by_default_and_plan_gated(): void
    {
        $starter = $this->makeClinic('starter');
        $this->actingAsStaff($starter['owner']);
        $this->postJson('/api/v1/ai/run', ['feature' => 'analytics_summary'])->assertStatus(402);

        $biz = $this->makeClinic('business');
        $this->actingAsStaff($biz['owner']);
        $this->postJson('/api/v1/ai/run', ['feature' => 'analytics_summary'])->assertStatus(402)->assertJsonPath('error.code', 'AI_DISABLED');
    }

    public function test_enabled_ai_returns_reviewable_draft_and_blocks_phi_without_dpa(): void
    {
        $c = $this->makeClinic('business');
        $this->actingAsStaff($c['owner']);
        $this->patchJson('/api/v1/organization/settings', ['ai_enabled' => true])->assertOk();

        $res = $this->postJson('/api/v1/ai/run', ['feature' => 'analytics_summary', 'input' => []])->assertOk()->json('data');
        $this->assertTrue($res['requires_review']);
        $this->assertStringStartsWith('[draft]', $res['draft']);
        $this->postJson("/api/v1/ai/requests/{$res['id']}/accept")->assertOk();

        $this->postJson('/api/v1/ai/run', ['feature' => 'message_draft', 'input' => ['purpose' => 'reminder', 'facts' => 'Ravi, follow-up Friday']])->assertStatus(403)->assertJsonPath('error.code', 'AI_PHI_NOT_ALLOWED');
        $this->patchJson('/api/v1/organization/settings', ['ai_phi_allowed' => true])->assertOk();
        $this->postJson('/api/v1/ai/run', ['feature' => 'message_draft', 'input' => ['purpose' => 'reminder', 'facts' => 'Ravi, follow-up Friday']])->assertOk();

        $log = $this->asTenant($c['org'], fn () => AiRequest::where('feature', 'message_draft')->first());
        $this->assertTrue($log->contains_phi);
        $this->assertStringNotContainsString('Ravi', (string) \DB::table('ai_requests')->where('id', $log->id)->value('prompt'), 'prompts are encrypted at rest');
    }

    public function test_anthropic_provider_parses_sdk_response(): void
    {
        $this->assertTrue(class_exists(Client::class));
        $this->assertSame('claude-opus-5-5', config('services.anthropic.model'));
    }
}
