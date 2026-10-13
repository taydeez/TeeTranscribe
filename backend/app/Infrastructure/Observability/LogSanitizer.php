<?php

namespace App\Infrastructure\Observability;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Database\QueryException;
use Throwable;

final class LogSanitizer
{
    public function text(string $value): string
    {
        $value = preg_replace('~\(Connection:.*$~s', '[database details removed]', $value);
        $value = preg_replace('~https?://[^\s"<>]+~i', '[url removed]', $value);
        $value = preg_replace('~(?:Bearer|Token)\s+\S+|\b(?:sk[_-]|FLWSECK)[a-zA-Z0-9_-]+~i', '[secret removed]', $value);
        $value = preg_replace('~\b[\w.+-]+@[\w.-]+\.[a-zA-Z]{2,}\b~', '[email removed]', $value);
        $value = preg_replace('~((?:password|secret|api[_-]?key|access[_-]?token|authorization|signature)["\x27]?\s*[=:]\s*)["\x27]?[^\s,;}]+~i', '$1[redacted]', $value);

        return mb_substr($value ?? '', 0, 4000);
    }

    public function context(array $values, int $depth = 0, ?int &$budget = null): array
    {
        $budget ??= 100;
        if ($depth > 4) {
            return ['truncated' => true];
        }
        $result = [];
        foreach (array_slice($values, 0, 40, true) as $key => $value) {
            if (--$budget < 0) {
                $result['truncated'] = true;
                break;
            }
            if (preg_match('/password|secret|api[_-]?key|token|authorization|cookie|signature|credential|email|transcript(?!ion_id)|source_text|translated_text|^text$|content|prompt|segments|utterances|headers|body|payload|response|url|path/i', (string) $key)) {
                $result[$key] = '[redacted]';
            } elseif ($value instanceof Throwable) {
                $result[$key] = $this->exception($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->context($value, $depth + 1, $budget);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = is_string($value) ? $this->text($value) : $value;
            }
        }

        return $result;
    }

    public function exception(Throwable $exception): array
    {
        $causes = [];
        $databaseFailure = $exception instanceof QueryException;
        do {
            $message = $databaseFailure ? 'Database query failed; SQL values omitted.' : $this->text($exception->getMessage());
            if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                $message = 'Provider HTTP request failed with status '.$exception->response->status().'; response body omitted.';
            } elseif ($exception instanceof RequestException) {
                $message = 'HTTP request failed; response body omitted.';
            }
            $causes[] = ['class' => $exception::class, 'message' => $message, 'code' => $exception->getCode(),
                'file' => basename($exception->getFile()), 'line' => $exception->getLine(),
                'trace' => array_map(fn (array $frame): array => array_intersect_key($frame, array_flip(['class', 'function', 'line'])), array_slice($exception->getTrace(), 0, 15))];
            $exception = $exception->getPrevious();
        } while ($exception !== null && count($causes) < 3);

        return $causes;
    }
}
