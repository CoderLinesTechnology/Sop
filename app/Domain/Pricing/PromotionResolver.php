<?php

namespace App\Domain\Pricing;

use App\Models\Promotion;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Finds the best running promotion for a service at a given server time.
 * Only one promotion applies to an order (the one with the largest discount;
 * ties are broken by priority).
 */
final class PromotionResolver
{
    /** @var array<string, Collection<int, Promotion>> */
    private array $memo = [];

    /** @return Collection<int, Promotion> */
    public function running(CarbonInterface $at): Collection
    {
        $key = $at->format('Y-m-d H:i');

        return $this->memo[$key] ??= Promotion::query()
            ->running($at)
            ->with('services:id')
            ->orderByDesc('priority')
            ->get();
    }

    /** @return array{0: ?Promotion, 1: int} the promotion and its discount */
    public function best(Service $service, int $amount, CarbonInterface $at): array
    {
        $best = null;
        $bestDiscount = 0;

        foreach ($this->running($at) as $promotion) {
            if (! $promotion->isRunning($at) || ! $promotion->appliesTo($service)) {
                continue;
            }

            $discount = Discount::compute(
                $amount,
                $service->currency,
                $promotion->percent_off,
                $promotion->amount_off,
                $promotion->currency,
                $promotion->max_discount_amount,
            );

            if ($discount > $bestDiscount) {
                $best = $promotion;
                $bestDiscount = $discount;
            }
        }

        return [$best, $bestDiscount];
    }

    /** The site-wide banner promotion (if any) for the announcement bar. */
    public function banner(CarbonInterface $at): ?Promotion
    {
        return $this->running($at)->first(fn (Promotion $p) => $p->show_banner && filled($p->banner_text));
    }
}
