<?php

namespace App\Domain\Upload\Enums;

enum UploadStatus: string
{
    case Uploading = 'uploading';
    case Completed = 'completed';
    case Aborted = 'aborted';
}
