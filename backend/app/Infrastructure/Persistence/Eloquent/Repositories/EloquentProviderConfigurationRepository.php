<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\AI\Contracts\ProviderCatalogInterface;
use App\Domain\AI\Contracts\ProviderConfigurationRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentProviderConfigurationRepository implements ProviderConfigurationRepositoryInterface
{
    public function __construct(private readonly ProviderCatalogInterface $catalog) {}

    public function find(string $activity): ?array
    {
        $row = DB::table('ai_provider_configurations')->where('activity', $activity)->first();

        return $row === null ? null : ['activity' => $activity, 'configuration' => json_decode($row->configuration, true, 512, JSON_THROW_ON_ERROR),
            'version' => (int) $row->version, 'updatedAt' => $row->updated_at];
    }

    public function save(string $activity, array $configuration, int $version, int $actorId, string $reason): array
    {
        return DB::transaction(function () use ($activity, $configuration, $version, $actorId, $reason): array {
            DB::table('ai_provider_configurations')->insertOrIgnore(['id' => (string) Str::ulid(), 'activity' => $activity,
                'configuration' => '{}', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('ai_provider_configurations')->where('activity', $activity)->lockForUpdate()->first();
            if ((int) $row->version !== $version) {
                throw new BillingException('These settings were changed by another administrator. Refresh before saving.', 409);
            }
            $json = json_encode($configuration, JSON_THROW_ON_ERROR);
            DB::table('ai_provider_configurations')->where('id', $row->id)->update(['configuration' => $json,
                'version' => $version + 1, 'updated_at' => now()]);
            DB::table('ai_provider_configuration_changes')->insert(['id' => (string) Str::ulid(), 'configuration_id' => $row->id,
                'actor_id' => $actorId, 'before' => $version === 0 ? json_encode($this->catalog->defaults($activity), JSON_THROW_ON_ERROR) : $row->configuration,
                'after' => $json, 'reason' => $reason, 'created_at' => now()]);

            return $this->find($activity);
        });
    }

    public function history(string $activity): array
    {
        return DB::table('ai_provider_configuration_changes as changes')->join('ai_provider_configurations as configurations', 'configurations.id', '=', 'changes.configuration_id')
            ->leftJoin('users', 'users.id', '=', 'changes.actor_id')->where('configurations.activity', $activity)
            ->orderByDesc('changes.created_at')->orderByDesc('changes.id')->limit(20)
            ->get(['changes.id', 'changes.actor_id as actorId', 'users.name as actorName', 'changes.reason', 'changes.before', 'changes.after', 'changes.created_at as createdAt'])
            ->map(fn ($row): array => ['id' => $row->id, 'actorId' => $row->actorId, 'actorName' => $row->actorName, 'reason' => $row->reason,
                'before' => json_decode($row->before, true), 'after' => json_decode($row->after, true), 'createdAt' => $row->createdAt])->all();
    }
}
