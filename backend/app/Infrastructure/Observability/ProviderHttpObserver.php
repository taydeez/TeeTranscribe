<?php

namespace App\Infrastructure\Observability;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use WeakMap;

final class ProviderHttpObserver
{
    private WeakMap $requests;

    public function __construct(private OperationalLogger $logger)
    {
        $this->requests = new WeakMap;
    }

    public function sending(RequestSending $event): void
    {
        if (($provider = $this->provider($event->request)) === null) {
            return;
        }
        $this->requests[$event->request] = ['start' => hrtime(true), 'context' => $this->logger->scope + ['provider' => $provider, 'method' => $event->request->method()]];
        $this->logger->record('provider', 'info', 'Provider HTTP request sent.', $this->requests[$event->request]['context']);
    }

    public function received(ResponseReceived $event): void
    {
        $state = $this->requests[$event->request] ?? null;
        if ($state === null) {
            return;
        }
        unset($this->requests[$event->request]);
        $summary = [];
        $json = str_contains(strtolower($event->response->header('Content-Type')), 'json') ? $event->response->json() : [];
        foreach (['message', 'status', 'code', 'error.message', 'error.code', 'error', 'detail', 'detail.message', 'data.processing_status', 'data.status', 'data.error.message', 'metadata.request_id', 'request_id', 'data.file_id'] as $field) {
            $value = data_get($json, $field);
            if (is_scalar($value)) {
                $summary[str_replace('.', '_', $field)] = $value;
            }
        }
        $requestId = data_get($json, 'request_id') ?? data_get($json, 'metadata.request_id') ?? data_get($json, 'data.file_id');
        $this->logger->record('provider', $event->response->failed() ? 'warning' : 'info', 'Provider HTTP response received.',
            array_replace($state['context'], ['http_status' => $event->response->status(), 'provider_request_id' => is_string($requestId) ? $requestId : null, 'provider_message' => $summary]),
            (int) ((hrtime(true) - $state['start']) / 1e6));
    }

    public function failed(ConnectionFailed $event): void
    {
        $state = $this->requests[$event->request] ?? null;
        if ($state === null) {
            $matches = [];
            foreach ($this->requests as $request => $candidate) {
                if ($request->url() === $event->request->url() && $request->method() === $event->request->method()) {
                    $matches[] = [$request, $candidate];
                }
            }
            if (count($matches) === 1) {
                [$request, $state] = $matches[0];
                unset($this->requests[$request]);
            }
        }
        if ($state === null) {
            if (($provider = $this->provider($event->request)) !== null) {
                $this->logger->record('provider', 'error', 'Provider HTTP connection failed.', ['provider' => $provider, 'exception' => $event->exception]);
            }

            return;
        }
        unset($this->requests[$event->request]);
        $this->logger->record('provider', 'error', 'Provider HTTP connection failed.', $state['context'] + ['exception' => $event->exception], (int) ((hrtime(true) - $state['start']) / 1e6));
    }

    private function provider(Request $request): ?string
    {
        $host = strtolower((string) parse_url($request->url(), PHP_URL_HOST));
        foreach (config('observability.provider_hosts', []) as $allowed => $provider) {
            if ($host === $allowed || ($provider === 'google' && str_ends_with($host, '-'.$allowed))) {
                return $provider;
            }
        }

        return null;
    }
}
