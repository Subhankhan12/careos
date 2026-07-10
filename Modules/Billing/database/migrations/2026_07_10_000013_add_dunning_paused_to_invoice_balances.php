<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dispute pause lives on the mutable balance projection, never on the
        // frozen legal invoice row.
        Schema::table('invoice_balances', function (Blueprint $table): void {
            $table->boolean('dunning_paused')->default(false)->after('open_balance_minor');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_balances', function (Blueprint $table): void {
            $table->dropColumn('dunning_paused');
        });
    }
};
