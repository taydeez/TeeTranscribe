<?php

namespace App\Console\Commands;

use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('r2:configure-cors {origin* : Frontend origins allowed to upload} {--check : Inspect without changing the bucket}')]
#[Description('Allow direct browser uploads while preserving existing R2 CORS rules')]
class ConfigureR2Cors extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $origins = $this->argument('origin');
        foreach ($origins as $origin) {
            if (! preg_match('#^https?://[^/]+$#', $origin) || ! filter_var($origin, FILTER_VALIDATE_URL)) {
                $this->error('Provide a full origin such as http://localhost:3000, without a trailing slash.');

                return self::FAILURE;
            }
        }

        $client = Storage::disk('r2')->getClient();
        $bucket = config('filesystems.disks.r2.bucket');
        try {
            try {
                $rules = $client->getBucketCors(['Bucket' => $bucket])->get('CORSRules') ?? [];
            } catch (S3Exception $exception) {
                if ($exception->getAwsErrorCode() !== 'NoSuchCORSConfiguration') {
                    throw $exception;
                }
                $rules = [];
            }

            $missing = array_values(array_filter($origins, function (string $origin) use ($rules): bool {
                foreach ($rules as $rule) {
                    $allowed = $rule['AllowedOrigins'] ?? [];
                    $headers = array_map('strtolower', $rule['AllowedHeaders'] ?? []);
                    if ((in_array($origin, $allowed, true) || in_array('*', $allowed, true))
                        && in_array('PUT', $rule['AllowedMethods'] ?? [], true)
                        && in_array('etag', array_map('strtolower', $rule['ExposeHeaders'] ?? []), true)
                        && (in_array('*', $headers, true) || in_array('content-type', $headers, true))) {
                        return false;
                    }
                }

                return true;
            }));

            if ($missing === []) {
                $this->info('R2 already allows browser uploads from these origins.');

                return self::SUCCESS;
            }
            if ($this->option('check')) {
                $this->warn('R2 upload CORS is missing for: '.implode(', ', $missing));

                return self::FAILURE;
            }

            $rules[] = [
                'AllowedOrigins' => $missing,
                'AllowedMethods' => ['PUT'],
                'AllowedHeaders' => ['*'],
                'ExposeHeaders' => ['ETag'],
                'MaxAgeSeconds' => 3600,
            ];
            $client->putBucketCors(['Bucket' => $bucket, 'CORSConfiguration' => ['CORSRules' => $rules]]);
            $this->info('R2 upload CORS configured. Existing rules were preserved.');

            return self::SUCCESS;
        } catch (S3Exception $exception) {
            $this->error('R2 CORS could not be configured ('.($exception->getAwsErrorCode() ?: 'connection error').'). Check bucket permissions.');

            return self::FAILURE;
        }
    }
}
