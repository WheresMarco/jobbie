<?php

declare(strict_types=1);

use Anthropic\Client;
use App\Matching\ClaudeLanguageModel;
use App\Matching\MatchingException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The real SDK against a PSR-18 client that records the request and
 * answers like the Messages API.
 */
/**
 * @return array{0: ClaudeLanguageModel, 1: object{request: RequestInterface|null}}
 */
function claudeWith(array $response, int $status = 200, string $model = 'claude-opus-5-5'): array
{
    $transporter = new class ($response, $status) implements ClientInterface {
        public ?RequestInterface $request = null;

        public function __construct(private array $response, private int $status)
        {
        }

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->request = $request;

            return new Response($this->status, ['Content-Type' => 'application/json'], json_encode($this->response, JSON_THROW_ON_ERROR));
        }
    };

    $client = new Client(apiKey: 'sk-ant-test', requestOptions: ['transporter' => $transporter, 'maxRetries' => 0]);

    return [new ClaudeLanguageModel($client, $model, 'medium'), $transporter];
}

function apiMessage(array $content, string $stopReason = 'end_turn', ?array $stopDetails = null): array
{
    return [
        'id' => 'msg_01',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => $content,
        'stop_reason' => $stopReason,
        'stop_details' => $stopDetails,
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 1200, 'output_tokens' => 80],
    ];
}

it('sends a structured output request with the PDF and fallbacks on, and decodes the answer', function (): void {
    [$model, $transporter] = claudeWith(apiMessage([
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
        ['type' => 'text', 'text' => '{"matches":[{"id":1,"score":80,"reason":"Bra."}]}'],
    ]));

    $answer = $model->json('System', [
        ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => 'JVBERg==']],
        ['type' => 'text', 'text' => 'Hej'],
    ], ['type' => 'object'], 4000);

    expect($answer)->toBe(['matches' => [['id' => 1, 'score' => 80, 'reason' => 'Bra.']]]);

    $sent = $transporter->request;
    $body = json_decode((string) $sent->getBody(), true);
    expect((string) $sent->getUri())->toBe('https://api.anthropic.com/v1/messages')
        ->and($sent->getHeaderLine('anthropic-beta'))->toBe('server-side-fallback-2026-07-01')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(4000)
        ->and($body['system'])->toBe('System')
        ->and($body['fallbacks'])->toBe('default')
        ->and($body['output_config'])->toBe(['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => ['type' => 'object']]])
        ->and($body['messages'][0]['content'][0])->toBe(['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERg==']]);
});

it('leaves fallbacks out for Haiku, which has none', function (): void {
    [$model, $transporter] = claudeWith(apiMessage([['type' => 'text', 'text' => '{}']]), model: 'claude-haiku-5-5');

    $model->json('S', [['type' => 'text', 'text' => 'x']], ['type' => 'object'], 100);

    $body = json_decode((string) $transporter->request->getBody(), true);
    expect($body['model'])->toBe('claude-haiku-5-5')
        ->and($body)->not->toHaveKey('fallbacks')
        ->and($transporter->request->hasHeader('anthropic-beta'))->toBeFalse();
});

it('turns a refusal into a matching error', function (): void {
    [$model] = claudeWith(apiMessage([], 'refusal', ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null]));

    expect(fn () => $model->json('S', [['type' => 'text', 'text' => 'x']], ['type' => 'object'], 100))
        ->toThrow(MatchingException::class, 'declined the request (cyber)');
});

it('turns a cut-off answer into a matching error', function (): void {
    [$model] = claudeWith(apiMessage([['type' => 'text', 'text' => '{"matc']], 'max_tokens'));

    expect(fn () => $model->json('S', [['type' => 'text', 'text' => 'x']], ['type' => 'object'], 100))
        ->toThrow(MatchingException::class, 'cut off');
});

it('turns API errors into matching errors', function (): void {
    [$model] = claudeWith(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], status: 529);

    expect(fn () => $model->json('S', [['type' => 'text', 'text' => 'x']], ['type' => 'object'], 100))
        ->toThrow(MatchingException::class, 'Claude API request failed');
});
