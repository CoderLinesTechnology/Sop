<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\Testimonial;
use Illuminate\Database\Seeder;

/**
 * Sample marketing data for local development and demos only (never run in
 * production): a limited-time promotion, example coupons and placeholder
 * testimonials. Replace testimonials with real customer feedback before
 * publishing the site.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Promotion::query()->firstOrCreate(['name' => 'Launch offer'], [
            'label' => 'Limited-time offer',
            'description' => 'Launch pricing on every service.',
            'percent_off' => 10,
            'applies_to_all_services' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3)->setTime(23, 59),
            'show_countdown' => true,
            'show_banner' => true,
            'banner_text' => 'Launch offer: 10% off every service for a limited time.',
            'priority' => 10,
            'is_active' => true,
        ]);

        foreach ([
            ['code' => 'WELCOME10', 'description' => '10% off your first order', 'percent_off' => 10, 'first_time_customers_only' => true, 'max_uses_per_email' => 1],
            ['code' => 'SCHOLAR20', 'description' => '20% off scholarship essays', 'percent_off' => 20, 'applies_to_all_services' => false, 'max_uses' => 200],
            ['code' => 'NEWYEAR15', 'description' => '15% off (seasonal)', 'percent_off' => 15, 'expires_at' => now()->addMonths(3), 'stackable_with_promotions' => true],
        ] as $coupon) {
            $model = Coupon::query()->firstOrCreate(['code' => $coupon['code']], $coupon + ['is_active' => true]);
            if ($coupon['code'] === 'SCHOLAR20' && $model->wasRecentlyCreated) {
                $model->services()->sync(\App\Models\Service::query()->where('slug', 'scholarship-essay')->pluck('id'));
            }
        }

        $samples = [
            ['I was impressed by how personal and well-researched my statement was. It felt like it truly reflected my story.', 'Sarah K.', 'MSc Computer Science'],
            ['Professional, reliable and fast. I received my documents in less than 30 minutes and they were exactly what I needed.', 'James T.', 'Canada'],
            ['Statementra made the whole process stress-free. The research and attention to detail really showed in my final document.', 'Amina O.', 'Australia'],
        ];

        foreach ($samples as $i => [$quote, $name, $detail]) {
            Testimonial::query()->firstOrCreate(['author_name' => $name], [
                'quote' => $quote,
                'author_detail' => $detail.' (sample)',
                'rating' => 5,
                'is_published' => true,
                'is_featured' => $i === 0,
                'display_order' => $i,
            ]);
        }
    }
}
