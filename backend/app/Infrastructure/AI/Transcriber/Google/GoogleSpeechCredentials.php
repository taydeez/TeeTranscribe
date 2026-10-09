<?php

namespace App\Infrastructure\AI\Transcriber\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSpeechCredentials
{
    public function token(): string
    {
        $path = config('transcriber.google.credentials');
        if (! is_string($path) || ! is_readable($path)) {
            throw new RuntimeException('Google Speech service account credentials are not configured.');
        }
        $credentials = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($credentials['type'] ?? null) !== 'service_account' || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            throw new RuntimeException('Google Speech requires service account credentials.');
        }

        return Cache::remember('google-speech-token:'.hash('sha256', $credentials['client_email'].$credentials['private_key']), 3300, function () use ($credentials): string {
            $header = $this->encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->encode(json_encode([
                'iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600,
            ], JSON_THROW_ON_ERROR));
            if (! openssl_sign($header.'.'.$claims, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Google Speech authentication could not be signed.');
            }
            $response = Http::asForm()->connectTimeout(10)->timeout(30)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $header.'.'.$claims.'.'.$this->encode($signature),
            ])->throw();
            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Google Speech authentication did not return a token.');
            }

            return $token;
        });
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
