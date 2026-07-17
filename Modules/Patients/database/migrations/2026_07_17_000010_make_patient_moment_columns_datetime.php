<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0P.G15 engine-parity fix: MariaDB 10.4 gives the FIRST non-nullable TIMESTAMP
 * column of a table an implicit ON UPDATE CURRENT_TIMESTAMP; MySQL 8 does not.
 * Both columns below sit on UPDATE-able tables, so on MariaDB the recorded moment
 * was silently rewritten by later updates (consent withdrawal rewrote granted_at;
 * token consumption rewrote expires_at) while MySQL 8 preserved it. Mutable moment
 * columns must be DATETIME (the standing D-E4/Comms rule); append-only ledgers may
 * keep TIMESTAMP because their UPDATE is trigger-blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_consents', function (Blueprint $table): void {
            $table->dateTime('granted_at')->change();
        });

        Schema::table('portal_login_tokens', function (Blueprint $table): void {
            $table->dateTime('expires_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('patient_consents', function (Blueprint $table): void {
            $table->timestamp('granted_at')->change();
        });

        Schema::table('portal_login_tokens', function (Blueprint $table): void {
            $table->timestamp('expires_at')->change();
        });
    }
};
