<?php

namespace Modules\Patients\Services;

use Illuminate\Support\Facades\DB;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Services\TenantContext;

class MrnGenerator
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function generate(): string
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            throw TenantContextMissingException::forCreating(new Patient);
        }

        return DB::transaction(function () use ($tenantId): string {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

            $last = Patient::withTrashed()
                ->where('mrn', 'like', 'MRN-%')
                ->orderByDesc('mrn')
                ->value('mrn');

            $next = $last !== null ? ((int) substr((string) $last, 4)) + 1 : 1;

            do {
                $mrn = sprintf('MRN-%06d', $next);
                $next++;
            } while (Patient::withTrashed()->where('mrn', $mrn)->exists());

            return $mrn;
        });
    }
}
