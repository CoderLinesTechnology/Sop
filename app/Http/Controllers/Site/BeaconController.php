<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Service;
use App\Support\Analytics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives client-side analytics events that the server cannot observe on its
 * own (currently only "form started"). Accepts an allowlisted event and a
 * service slug — nothing else is stored.
 */
class BeaconController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (is_array($payload) && ($payload['event'] ?? null) === 'form_start' && is_string($payload['service'] ?? null)) {
            $serviceId = Service::query()->where('slug', mb_substr($payload['service'], 0, 160))->value('id');
            if ($serviceId) {
                Analytics::record(AnalyticsEvent::FORM_START, $request, ['service_id' => $serviceId]);
            }
        }

        return response()->noContent();
    }
}
