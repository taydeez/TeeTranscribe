<?php

namespace App\Infrastructure\AI\OpenAI;

use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final readonly class OpenAIClient
{
    public function __construct(private ?int $requestTimeout = null) {}

    public function withTimeout(int $seconds): self
    {
        return new self(max(1, $seconds));
    }

    public function structured(string $instructions, string $input, array $schema, string $schemaName, ?string $model = null): array
    {
        $key = config('openai.key');
        $model ??= config('openai.text_model', 'gpt-4.1-mini');
        $endpoint = rtrim((string) config('openai.endpoint', 'https://api.openai.com/v1/'), '/');
        $parts = parse_url($endpoint);
        if (! is_string($key) || trim($key) === '' || ! is_string($model) || trim($model) === ''
            || $parts === false || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'api.openai.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? null) !== '/v1' || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new BillingException('This AI feature is not configured yet.', 503);
        }

        try {
            $response = Http::acceptJson()->withToken($key)->connectTimeout(10)
                ->timeout($this->requestTimeout ?? (int) config('openai.timeout', 180))->withOptions(['allow_redirects' => false])
                ->post($endpoint.'/responses', [
                    'model' => $model, 'instructions' => $instructions, 'input' => $input, 'store' => false,
                    'max_output_tokens' => (int) config('openai.max_output_tokens', 16000),
                    'text' => ['format' => ['type' => 'json_schema', 'name' => $schemaName, 'strict' => true, 'schema' => $schema]],
                ]);
        } catch (\Throwable) {
            throw new BillingException('The AI service is temporarily unavailable. Please try again.', 503);
        }

        if (! $response->successful()) {
            Log::warning('OpenAI text request failed.', ['http_status' => $response->status()]);
            throw new BillingException('The AI service is temporarily unavailable. Please try again.', 503);
        }

        $result = $response->json();
        if (! is_array($result) || ($result['status'] ?? null) !== 'completed' || ! is_array($result['output'] ?? null)) {
            throw new BillingException('The AI service returned an incomplete response.', 502);
        }

        $text = '';
        foreach ($result['output'] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            if (($item['status'] ?? null) !== 'completed' || ! is_array($item['content'] ?? null)) {
                throw new BillingException('The AI service returned an incomplete response.', 502);
            }
            foreach ($item['content'] as $content) {
                if (! is_array($content)) {
                    throw new BillingException('The AI service returned an invalid response.', 502);
                }
                if (($content['type'] ?? null) === 'refusal') {
                    throw new BillingException('The AI service could not process this text.', 422);
                }
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $text .= $content['text'];
                }
            }
        }

        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BillingException('The AI service returned an invalid response.', 502);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new BillingException('The AI service returned an invalid response.', 502);
        }

        return $decoded;
    }
}
