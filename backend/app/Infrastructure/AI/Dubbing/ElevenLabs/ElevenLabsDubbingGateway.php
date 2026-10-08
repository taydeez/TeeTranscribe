<?php

namespace App\Infrastructure\AI\Dubbing\ElevenLabs;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingGatewayInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use Illuminate\Support\Facades\Log;

final readonly class ElevenLabsDubbingGateway implements DubbingGatewayInterface
{
    public function __construct(private ElevenLabsClient $client) {}

    public function create(Dubbing $record, string $sourceUrl): array
    {
        $data = ['source_url' => $sourceUrl, 'reference' => $record->id, 'target_language' => $record->targetLanguage, 'model_id' => 'dubbing_v2'];
        if ($record->sourceLanguage !== null) {
            $data['source_language'] = $record->sourceLanguage;
        }
        $result = $this->client->request('POST', '', $data);
        $this->id($result['project_id'] ?? '');

        $this->logFailure($result);

        return $result;
    }

    public function project(string $id): array
    {
        $this->id($id);
        $result = $this->client->request('GET', '/'.rawurlencode($id));
        if (($result['project_id'] ?? null) !== $id || ! in_array($result['status'] ?? '', ['queued', 'preparing', 'ready', 'processing', 'failed'], true)) {
            throw new BillingException('The dubbing provider returned an invalid project.', 502);
        }

        $this->logFailure($result);

        return $result;
    }

    public function recover(string $reference): ?array
    {
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $result = $this->client->request('GET', '', ['page_size' => 100] + ($cursor !== null ? ['cursor' => $cursor] : []));
            if (! is_array($result['projects'] ?? null)) {
                throw new BillingException('The dubbing submission could not be reconciled.', 502);
            }
            foreach ($result['projects'] as $project) {
                if (($project['reference'] ?? null) === $reference) {
                    $this->id($project['project_id'] ?? '');

                    return $project;
                }
            }
            $cursor = $result['next_cursor'] ?? null;
            if ($cursor === null) {
                return null;
            }
        }

        return null;
    }

    public function language(string $projectId, string $languageId): array
    {
        $this->id($projectId);
        $this->id($languageId);
        $result = $this->client->request('GET', '/'.rawurlencode($projectId).'/language/'.rawurlencode($languageId));
        if (($result['project_id'] ?? null) !== $projectId || ($result['language_id'] ?? null) !== $languageId
            || ! in_array($result['status'] ?? '', ['queued', 'processing', 'completed', 'stale', 'failed'], true)) {
            throw new BillingException('The dubbing provider returned an invalid language result.', 502);
        }

        $this->logFailure($result);

        return $result;
    }

    private function logFailure(array $result): void
    {
        if (($result['status'] ?? null) !== 'failed') {
            return;
        }
        $details = [];
        foreach (['error', 'error_code', 'error_message', 'failure_reason', 'message', 'detail'] as $field) {
            if (isset($result[$field])) {
                $value = is_string($result[$field]) ? $result[$field] : json_encode($result[$field]);
                $details[$field] = substr(preg_replace('~https?://[^\s"<>]+~i', '[redacted URL]', (string) $value), 0, 2000);
            }
        }
        Log::warning('Dubbing provider reported failure.', [
            'provider_project_id' => $result['project_id'] ?? null,
            'provider_language_id' => $result['language_id'] ?? null,
            'status' => $result['status'],
            'details' => $details,
        ]);
    }

    private function id(mixed $id): void
    {
        if (! is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,200}$/D', $id) !== 1) {
            throw new BillingException('The dubbing provider returned an invalid reference.', 502);
        }
    }
}
