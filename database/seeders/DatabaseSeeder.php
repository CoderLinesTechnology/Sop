<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds everything a fresh installation needs. Every seeder is idempotent and
 * never overwrites data administrators have edited.
 *
 * Demo data (sample promotion, coupons, sample testimonials) is only added
 * outside production; see DemoSeeder.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            EmailTemplateSeeder::class,
            ServiceCatalogSeeder::class,
            ContentSeeder::class,
        ]);

        foreach (['AiConfigurationSeeder', 'DocumentConfigurationSeeder', 'ArticleSeeder'] as $optional) {
            if (class_exists($class = 'Database\\Seeders\\'.$optional)) {
                $this->call($class);
            }
        }

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
