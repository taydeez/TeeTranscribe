<?php

namespace App\Domain\Billing\Contracts;

interface ActivityConcurrencyInterface
{
    /** Called inside the admission transaction, after locking the user's wallet. */
    public function assertAvailable(int $userId, string $activity, string $subjectId): void;
}
