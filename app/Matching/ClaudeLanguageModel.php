<?php

declare(strict_types=1);

namespace App\Matching;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Matching\Contracts\LanguageModel;
use Illuminate\Support\Facades\Log;

/**
 * The Claude API with structured output. If the model declines a request
 * on policy grounds, the API retries it on its default fallback model,
 * where there is one: Haiku has none.
 */
final readonly class ClaudeLanguageModel implements LanguageModel
{
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private Client $client,
        private string $model,
        private string $effort,
    ) {
    }

    public function json(string $system, array $content, array $schema, int $maxTokens): array
    {
        $fallback = ! str_starts_with($this->model, 'claude-haiku-');

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $content]],
                model: $this->model,
                fallbacks: $fallback ? 'default' : null,
                outputConfig: [
                    'effort' => $this->effort,
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                system: $system,
                betas: $fallback ? [self::FALLBACK_BETA] : null,
            );
        } catch (APIException $e) {
            throw new MatchingException('Claude API request failed: '.$e->getMessage(), previous: $e);
        }

        Log::info('Claude request', [
            'model' => $message->model,
            'stop_reason' => $message->stopReason,
            'input_tokens' => $message->usage->inputTokens,
            'output_tokens' => $message->usage->outputTokens,
        ]);

        if ($message->stopReason === 'refusal') {
            throw new MatchingException('Claude declined the request ('.($message->stopDetails->category ?? 'no category').').');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new MatchingException("Claude's answer was cut off at {$maxTokens} tokens.");
        }

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $answer = json_decode($block->text, true);
                if (! is_array($answer)) {
                    throw new MatchingException('Claude answered with something other than a JSON object.');
                }

                return $answer;
            }
        }

        throw new MatchingException('Claude answered without text.');
    }
}
