<?php
/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\Upload\R2;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class R2Client {


    public function Presignupload($data){
        foreach (['key', 'secret', 'bucket', 'endpoint'] as $setting) {
            abort_unless(config('filesystems.disks.r2.'.$setting), 503, 'Audio storage is not configured.');
        }

        $extension = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION));

        $path = 'audio/'.Str::ulid().'.'.$extension;
        $disk = Storage::disk('r2');
        $upload = $disk->temporaryUploadUrl($path, now()->addMinutes(20), [
            'ContentType' => $data['content_type'],
            'ContentLength' => $data['size'],
        ]);

        $headers = [];
        foreach ($upload['headers'] as $name => $values) {
            if (! in_array(strtolower($name), ['host', 'content-length'], true)) {
                $headers[$name] = is_array($values) ? implode(', ', $values) : $values;
            }
        }
        $headers['Content-Type'] = $data['content_type'];

        return [
            'upload_url' => $upload['url'],
            'audio_url' => $disk->temporaryUrl($path, now()->addHours(6)),
            'headers' => $headers,
        ];

    }
}
