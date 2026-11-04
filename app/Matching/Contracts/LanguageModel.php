<?php

declare(strict_types=1);

namespace App\Matching\Contracts;

use App\Matching\MatchingException;

/**
 * One request to a language model that answers with JSON following a
 * schema. Tests bind a fake; the app uses Claude.
 */
interface LanguageModel
{
    /**
     * @param  list<array<string, mixed>>  $content  The user message's content blocks, as the Anthropic SDK takes them.
     * @param  array<string, mixed>  $schema  JSON schema for the answer.
     * @return array<mixed> The decoded answer.
     *
     * @throws MatchingException
     */
    public function json(string $system, array $content, array $schema, int $maxTokens): array;
}
