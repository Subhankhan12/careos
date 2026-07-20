<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable practice-profile fields for a tenant (contact + postal address). The
 * clinic display `name` already exists; these are the identity/contact details a
 * clinic sets on day one. All nullable — a tenant provisions without them and an
 * admin fills them in on the Settings page. (region/slug/status/plan stay untouched:
 * region is immutable, slug is the public booking key, status/plan are platform-controlled.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('contact_email')->nullable()->after('name');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->string('address_line1')->nullable()->after('contact_phone');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city')->nullable()->after('address_line2');
            $table->string('postal_code')->nullable()->after('city');
            $table->char('country', 2)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'contact_email',
                'contact_phone',
                'address_line1',
                'address_line2',
                'city',
                'postal_code',
                'country',
            ]);
        });
    }
};
