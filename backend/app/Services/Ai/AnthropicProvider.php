<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Exceptions\BusinessRuleException;

/** Claude via the official Anthropic PHP SDK. Reads ANTHROPIC_API_KEY from config. */
class AnthropicProvider implements AiProvider
{
    public function __construct(private ?Client $client = null) {}

    public function complete(string $system, string $user, int $maxTokens = 2000): AiResult
    {
        $client = $this->client ??= new Client(apiKey: config('services.anthropic.key'));
        $model = config('services.anthropic.model', 'claude-opus-5-5');

        try {
            $message = $client->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                system: $system,
                outputConfig: ['effort' => config('services.anthropic.effort', 'medium')],
            );
        } catch (RateLimitException) {
            throw new BusinessRuleException('AI_RATE_LIMITED', 'The AI service is busy. Please try again shortly.', 503);
        } catch (APIStatusException $e) {
            report($e);
            throw new BusinessRuleException('AI_UNAVAILABLE', 'The AI service returned an error.', 502);
        } catch (APIConnectionException $e) {
            report($e);
            throw new BusinessRuleException('AI_UNAVAILABLE', 'The AI service could not be reached.', 503);
        }

        if ($message->stopReason === 'refusal') {
            return new AiResult('The AI assistant declined this request.', 'anthropic', $message->model, $message->usage->inputTokens, $message->usage->outputTokens, true);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new AiResult(trim($text), 'anthropic', $message->model, $message->usage->inputTokens, $message->usage->outputTokens);
    }
}
