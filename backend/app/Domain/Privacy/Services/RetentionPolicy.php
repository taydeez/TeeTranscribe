<?php

namespace App\Domain\Privacy\Services;

use App\Domain\Billing\Exceptions\BillingException;

final class RetentionPolicy
{
    public const MAX_HOURS = 87600;

    public const CLEANUP_INTERVAL_MINUTES = 15;

    public static function categories(): array
    {
        return ['source_audio', 'source_video', 'recordings', 'dubbed_audio', 'dubbed_video',
            'pdf', 'txt', 'docx', 'subtitles', 'transcripts', 'translations'];
    }

    public static function defaults(): array
    {
        return array_fill_keys(self::categories(), null);
    }

    public static function merge(array $current, array $changes): array
    {
        foreach ($changes as $category => $hours) {
            if (! in_array($category, self::categories(), true)
                || ($hours !== null && (! is_int($hours) || $hours < 1 || $hours > self::MAX_HOURS))) {
                throw new BillingException('Retention must be a whole number of hours or null to keep the item.', 422);
            }
        }

        return array_replace(self::defaults(), array_intersect_key($current, self::defaults()), $changes);
    }
}
