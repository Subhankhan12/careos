<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Platform\Services\RbacProvisioner;

class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        app(RbacProvisioner::class)->syncPermissionCatalog();
    }
}
