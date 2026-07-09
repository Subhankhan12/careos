<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Nursing\Services\VisitService;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('planned_visit_id')->nullable();
            $table->ulid('patient_id');
            $table->ulid('resource_id');
            $table->ulid('branch_id');
            $table->dateTime('scheduled_start_at');
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->string('status')->default('scheduled');
            $table->string('client_visit_uuid');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('planned_visit_id')->references('id')->on('planned_visits')->nullOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('resource_id')->references('id')->on('resources')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->unique(['tenant_id', 'client_visit_uuid']);
            $table->index(['tenant_id', 'resource_id', 'scheduled_start_at']);
            $table->index(['tenant_id', 'patient_id', 'scheduled_start_at']);
        });

        $now = now()->toDateTimeString();
        $tenantIds = DB::table('tenants')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            $query = DB::table('settings')
                ->where('tenant_id', $tenantId)
                ->where('key', VisitService::PRIVACY_NOTICE_SETTING_KEY);

            if ($query->exists()) {
                $query->update([
                    'value' => json_encode(VisitService::PRIVACY_NOTICE_TEXT, JSON_THROW_ON_ERROR),
                    'type' => 'string',
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('settings')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'key' => VisitService::PRIVACY_NOTICE_SETTING_KEY,
                'value' => json_encode(VisitService::PRIVACY_NOTICE_TEXT, JSON_THROW_ON_ERROR),
                'type' => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', VisitService::PRIVACY_NOTICE_SETTING_KEY)->delete();
        Schema::dropIfExists('visits');
    }
};
