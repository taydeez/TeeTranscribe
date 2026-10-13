<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\Dashboard\Contracts\DashboardRepositoryInterface;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentDashboardRepository implements DashboardRepositoryInterface
{
    public function report(string $from, string $until, string $environment): array
    {
        $payments = $this->customers(DB::table('payments'), 'payments.user_id')->where('status', 'paid')
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $until);
        if ($environment !== 'all') {
            $payments->where('environment', $environment);
        }
        $paymentDays = $payments->selectRaw('DATE(paid_at) as day, currency, SUM(amount_minor) as revenue_minor, SUM(credit_units) as credit_units, COUNT(*) as purchases')
            ->groupByRaw('DATE(paid_at), currency')->get();
        $usage = $this->customers(DB::table('credit_transactions'), 'credit_transactions.user_id')->where('kind', 'consume')
            ->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->selectRaw('DATE(created_at) as day, SUM(amount_units) as credit_units')->groupByRaw('DATE(created_at)')->get();
        $accounts = $this->customers(DB::table('users'), 'users.id')->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupByRaw('DATE(created_at)')->get();
        $signIns = $this->customers(DB::table('customer_sign_ins'), 'customer_sign_ins.user_id')->where('created_at', '>=', $from)->where('created_at', '<', $until);
        $signInDays = (clone $signIns)->selectRaw('DATE(created_at) as day, COUNT(*) as total')->groupByRaw('DATE(created_at)')->get();
        $locations = (clone $signIns)->selectRaw("COALESCE(country, 'Unknown') as country, country_code, COUNT(*) as sign_ins")
            ->groupBy('country', 'country_code')->orderByDesc('sign_ins')->orderBy('country')->get()
            ->map(fn ($row): array => ['country' => $row->country, 'country_code' => $row->country_code, 'sign_ins' => (int) $row->sign_ins])->all();
        $feature = "CASE WHEN charges.activity = 'transcript_tool' THEN quotes.activity ELSE charges.activity END";
        $features = $this->customers(DB::table('usage_charges as charges'), 'charges.user_id')->join('billing_quotes as quotes', 'quotes.id', '=', 'charges.quote_id')
            ->where('charges.created_at', '>=', $from)->where('charges.created_at', '<', $until)
            ->selectRaw($feature.' as feature, COUNT(*) as requests')->groupByRaw($feature)->orderByDesc('requests')->orderBy('feature')->get()
            ->map(fn ($row): array => ['feature' => $row->feature, 'requests' => (int) $row->requests])->all();
        $daily = [];
        for ($date = CarbonImmutable::parse($from); $date->lt(CarbonImmutable::parse($until)); $date = $date->addDay()) {
            $daily[$date->toDateString()] = ['date' => $date->toDateString(), 'revenue_ngn_minor' => 0, 'revenue_usd_minor' => 0,
                'credits_bought_units' => 0, 'credits_used_units' => 0, 'sign_ins' => 0, 'new_accounts' => 0];
        }
        $revenue = [];
        foreach ($paymentDays as $row) {
            $revenue[$row->currency] ??= ['currency' => $row->currency, 'amount_minor' => 0, 'purchases' => 0];
            $revenue[$row->currency]['amount_minor'] += (int) $row->revenue_minor;
            $revenue[$row->currency]['purchases'] += (int) $row->purchases;
            if (in_array($row->currency, ['NGN', 'USD'], true)) {
                $daily[$row->day]['revenue_'.strtolower($row->currency).'_minor'] += (int) $row->revenue_minor;
            }
            $daily[$row->day]['credits_bought_units'] += (int) $row->credit_units;
        }
        foreach ($usage as $row) {
            $daily[$row->day]['credits_used_units'] = (int) $row->credit_units;
        }
        foreach ($accounts as $row) {
            $daily[$row->day]['new_accounts'] = (int) $row->total;
        }
        foreach ($signInDays as $row) {
            $daily[$row->day]['sign_ins'] = (int) $row->total;
        }

        return ['from' => substr($from, 0, 10), 'to' => CarbonImmutable::parse($until)->subDay()->toDateString(), 'timezone' => 'UTC',
            'environment' => $environment, 'generated_at' => now()->toIso8601String(),
            'summary' => ['revenue' => array_values($revenue), 'credits_bought_units' => array_sum(array_column($daily, 'credits_bought_units')),
                'credits_used_units' => array_sum(array_column($daily, 'credits_used_units')), 'sign_ins' => array_sum(array_column($daily, 'sign_ins')),
                'new_accounts' => array_sum(array_column($daily, 'new_accounts')), 'unique_signing_users' => (clone $signIns)->distinct()->count('user_id'),
                'feature_requests' => array_sum(array_column($features, 'requests'))],
            'daily' => array_values($daily), 'features' => $features, 'locations' => $locations];
    }

    private function customers(Builder $query, string $column): Builder
    {
        return $query->whereNotExists(function (Builder $roles) use ($column): void {
            $roles->selectRaw('1')->from('model_has_roles as assignments')->join('roles as roles', 'roles.id', '=', 'assignments.role_id')
                ->whereColumn('assignments.model_id', $column)->where('assignments.model_type', (new User)->getMorphClass())->whereIn('roles.name', ['admin', 'super_admin']);
        });
    }
}
