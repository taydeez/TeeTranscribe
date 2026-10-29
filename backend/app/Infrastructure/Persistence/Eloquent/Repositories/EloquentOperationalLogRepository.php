<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\Log\Contracts\OperationalLogRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentOperationalLogRepository implements OperationalLogRepositoryInterface
{
    public function paginate(array $filters): array
    {
        $query = DB::table('operational_logs');
        foreach (['type', 'level', 'provider', 'operation_type', 'operation_id', 'provider_request_id', 'job_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['search'])) {
            $query->whereLike('message', '%'.addcslashes($filters['search'], '%_\\').'%');
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'UTC')->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay()->startOfDay());
        }
        $sort = ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';
        $page = $query->orderBy('created_at', $sort)->orderBy('id', $sort)->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => array_map(fn (object $row): array => $this->map($row, false), $page->items()),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()]];
    }

    public function find(string $id): ?array
    {
        $row = DB::table('operational_logs')->where('id', $id)->first();

        return $row === null ? null : $this->map($row, true);
    }

    private function map(object $row, bool $detail): array
    {
        $data = (array) $row;
        $data['created_at'] = CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String();
        $data['elapsed_ms'] = $row->elapsed_ms === null ? null : (int) $row->elapsed_ms;
        if ($detail) {
            $data['context'] = json_decode($row->context, true);
        } else {
            unset($data['context']);
        }

        return $data;
    }
}
