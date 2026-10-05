<?php

namespace App\Console\Commands;

use App\Domain\Upload\Services\MultipartUploadService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('uploads:cleanup')]
#[Description('Abort expired incomplete R2 multipart uploads')]
class CleanupExpiredUploads extends Command
{
    public function handle(MultipartUploadService $service): int
    {
        $count = $service->cleanup();
        $this->info("Cleaned up {$count} expired uploads.");

        return self::SUCCESS;
    }
}
