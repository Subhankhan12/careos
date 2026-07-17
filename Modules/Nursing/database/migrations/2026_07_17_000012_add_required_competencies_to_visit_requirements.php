<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visit's required competencies reuse the existing requirement path: they are
 * documented on the agreement_service (the template) and copied onto each generated
 * planned_visit (the per-occurrence authoritative list, like required_qualification).
 * Both hold a JSON array of tenant competency CODES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreement_services', function (Blueprint $table): void {
            $table->json('required_competencies')->nullable()->after('required_qualification');
        });

        Schema::table('planned_visits', function (Blueprint $table): void {
            $table->json('required_competencies')->nullable()->after('required_qualification');
        });
    }

    public function down(): void
    {
        Schema::table('agreement_services', function (Blueprint $table): void {
            $table->dropColumn('required_competencies');
        });

        Schema::table('planned_visits', function (Blueprint $table): void {
            $table->dropColumn('required_competencies');
        });
    }
};
