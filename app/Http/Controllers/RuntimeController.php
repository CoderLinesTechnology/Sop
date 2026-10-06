<?php

namespace App\Http\Controllers;

use App\Domain\Ai\PipelineDispatcher;
use App\Models\AiJob;
use App\Support\Runtime\AfterResponse;
use App\Support\Runtime\Heartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Entry points of the request-driven runtime (no queue worker, no cron). */
class RuntimeController extends Controller
{
    /** Signed loopback request: continue an AI job in a fresh PHP request. */
    public function continuePipeline(PipelineDispatcher $dispatcher, string $aiJob): Response
    {
        $job = AiJob::query()->where('uuid', $aiJob)->firstOrFail();

        $dispatcher->kick($job); // runs after this 202 response is sent

        return response()->noContent(202);
    }

    /** External ping (any uptime monitor): runs due maintenance after responding. */
    public function heartbeat(Heartbeat $heartbeat, string $token): JsonResponse
    {
        abort_unless(hash_equals(Heartbeat::token(), $token), 404);

        AfterResponse::run('heartbeat', fn () => $heartbeat->beat(50), timeLimitSeconds: 300);

        return response()->json(['status' => 'ok', 'last_beat_at' => Heartbeat::lastBeatAt()], 202);
    }
}
