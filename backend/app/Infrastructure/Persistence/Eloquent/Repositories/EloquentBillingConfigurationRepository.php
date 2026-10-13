<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\Billing\Contracts\BillingConfigurationRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Infrastructure\Cache\ReadCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentBillingConfigurationRepository implements BillingConfigurationRepositoryInterface
{
    public function __construct(private readonly ReadCache $cache) {}

    public function find(): ?array
    {
        return $this->cache->remember('billing:configuration', config('read_cache.settings_seconds'), function (): ?array {
            $row = DB::table('billing_configurations')->where('key', 'global')->first();

            return $row === null ? null : ['configuration' => json_decode($row->configuration, true, 512, JSON_THROW_ON_ERROR),
                'version' => (int) $row->version, 'updatedAt' => $row->updated_at];
        }, committedOnly: true);
    }

    public function save(array $configuration, array $before, int $version, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($configuration, $before, $version, $actorId, $reason): void {
            DB::table('billing_configurations')->insertOrIgnore(['id' => (string) Str::ulid(), 'key' => 'global',
                'configuration' => '{}', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('billing_configurations')->where('key', 'global')->lockForUpdate()->first();
            if ((int) $row->version !== $version) {
                throw new BillingException('Billing settings changed. Refresh before saving.', 409);
            }
            $json = json_encode($configuration, JSON_THROW_ON_ERROR);
            DB::table('billing_configurations')->where('id', $row->id)->update(['configuration' => $json,
                'version' => $version + 1, 'updated_at' => now()]);
            DB::table('billing_configuration_changes')->insert(['id' => (string) Str::ulid(), 'configuration_id' => $row->id,
                'actor_id' => $actorId, 'before' => $version === 0 ? json_encode($before, JSON_THROW_ON_ERROR) : $row->configuration,
                'after' => $json, 'reason' => $reason, 'created_at' => now()]);
            DB::afterCommit(fn () => $this->cache->invalidate('billing:configuration'));
        });
    }

    public function history(): array
    {
        return DB::table('billing_configuration_changes as changes')->leftJoin('users', 'users.id', '=', 'changes.actor_id')
            ->orderByDesc('changes.id')->limit(20)->get(['changes.id', 'changes.actor_id as actorId', 'users.name as actorName',
                'changes.reason', 'changes.before', 'changes.after', 'changes.created_at as createdAt'])
            ->map(fn ($row): array => ['id' => $row->id, 'actorId' => $row->actorId, 'actorName' => $row->actorName,
                'reason' => $row->reason, 'before' => json_decode($row->before, true), 'after' => json_decode($row->after, true), 'createdAt' => $row->createdAt])->all();
    }

    public function report(string $from, string $until): array
    {
        $payments = DB::table('payments')->where('status', 'paid')->where('paid_at', '>=', $from)->where('paid_at', '<', $until)
            ->selectRaw('currency, COUNT(*) as purchases, SUM(amount_minor) as revenue_minor, SUM(provider_fee_minor) as fees_minor, SUM(credit_units) as credit_units')
            ->groupBy('currency')->orderBy('currency')->get()->map(fn ($row): array => ['currency' => $row->currency,
                'purchases' => (int) $row->purchases, 'revenue_minor' => (int) $row->revenue_minor,
                'known_fees_minor' => (int) $row->fees_minor, 'credit_units' => (int) $row->credit_units])->all();
        $usage = DB::table('usage_charges')->where('updated_at', '>=', $from)->where('updated_at', '<', $until)
            ->selectRaw('activity, provider, model, status, provider_cost_currency, COUNT(*) as jobs, SUM(credit_units) as credit_units, SUM(provider_cost_micros) as estimated_provider_cost_micros, COUNT(provider_cost_micros) as costed_jobs')
            ->groupBy('activity', 'provider', 'model', 'status', 'provider_cost_currency')->orderBy('activity')->orderBy('provider')->get()
            ->map(fn ($row): array => ['activity' => $row->activity, 'provider' => $row->provider, 'model' => $row->model,
                'status' => $row->status, 'jobs' => (int) $row->jobs, 'credit_units' => (int) $row->credit_units,
                'provider_currency' => $row->provider_cost_currency, 'costed_jobs' => (int) $row->costed_jobs,
                'estimated_provider_cost_micros' => $row->costed_jobs > 0 ? (int) $row->estimated_provider_cost_micros : null])->all();
        $ledger = DB::table('credit_transactions')->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->selectRaw('kind, COUNT(*) as entries, SUM(amount_units) as credit_units')->groupBy('kind')->orderBy('kind')->get()
            ->map(fn ($row): array => ['kind' => $row->kind, 'entries' => (int) $row->entries, 'credit_units' => (int) $row->credit_units])->all();

        return ['from' => $from, 'until_exclusive' => $until, 'payments' => $payments, 'usage' => $usage, 'ledger' => $ledger];
    }
}
