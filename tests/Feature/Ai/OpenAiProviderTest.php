<?php

use App\Domain\Ai\Llm\LlmCall;
use App\Domain\Ai\Llm\LlmException;
use App\Domain\Ai\Llm\LlmGateway;
use App\Domain\Ai\Llm\LlmRequest;
use App\Domain\Ai\Llm\OpenAiProvider;
use App\Domain\Ai\Pipeline\PipelineTrigger;
use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Ai\Prompts\Schemas;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJobStep;
use App\Models\AiUsage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Feature\Ai\Support\PipelineFixtures;
use Tests\Feature\Ai\Support\RecordingTrigger;

uses(PipelineFixtures::class);

beforeEach(function () {
    config([
        'statementra.ai.openai_api_key' => 'sk-test-123',
        'statementra.ai.openai_base_url' => 'https://api.openai.test/v1',
        'statementra.ai.openai_organization' => 'org_abc',
        'statementra.ai.openai_project' => 'proj_xyz',
        'statementra.ai.request_timeout' => 120,
    ]);
    Sleep::fake();
});

function apiResponse(array $overrides = []): array
{
    $text = '{"reviews":[{"claim_id":"C1","supported":true,"notes":"Direct quote."}],"conflicts":[]}';

    return array_replace([
        'id' => 'resp_123',
        'object' => 'response',
        'model' => 'gpt-6.1-sol-2026-09-01',
        'status' => 'completed',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
            ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => [
                'type' => 'search',
                'queries' => ['oxford msc computer science modules'],
                'sources' => [
                    ['type' => 'url', 'url' => 'https://www.ox.ac.uk/admissions/graduate/courses/msc-computer-science?utm_source=openai'],
                    ['type' => 'url', 'url' => 'https://www.cs.ox.ac.uk/teaching/msc/'],
                ],
            ]],
            ['type' => 'web_search_call', 'id' => 'ws_2', 'status' => 'completed', 'action' => [
                'type' => 'open_page', 'url' => 'https://www.cs.ox.ac.uk/research/',
            ]],
            ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed', 'content' => [[
                'type' => 'output_text',
                'text' => $text,
                'annotations' => [[
                    'type' => 'url_citation', 'url' => 'https://www.ox.ac.uk/admissions/graduate/courses/msc-computer-science', 'title' => 'MSc in Computer Science', 'start_index' => 3, 'end_index' => 40,
                ]],
            ]]],
        ],
        'usage' => [
            'input_tokens' => 1200,
            'input_tokens_details' => ['cached_tokens' => 200],
            'output_tokens' => 300,
            'output_tokens_details' => ['reasoning_tokens' => 120],
            'total_tokens' => 1500,
        ],
    ], $overrides);
}

function sampleRequest(array $overrides = []): LlmRequest
{
    return new LlmRequest(...array_replace([
        'model' => 'gpt-6.1-sol',
        'instructions' => 'Be precise.',
        'input' => [['role' => 'user', 'content' => [
            ['type' => 'input_text', 'text' => 'Review these claims.'],
            ['type' => 'input_image', 'image_url' => 'data:image/png;base64,AAAA', 'detail' => 'high'],
            ['type' => 'input_file', 'filename' => 'upload-1a2b3c4d.pdf', 'file_data' => 'data:application/pdf;base64,BBBB'],
        ]]],
        'schema' => Schemas::for('verification'),
        'reasoningEffort' => 'high',
        'maxOutputTokens' => 8000,
        'tools' => [['type' => 'web_search', 'filters' => ['allowed_domains' => ['ox.ac.uk']], 'search_context_size' => 'medium', 'user_location' => ['type' => 'approximate', 'country' => 'GB']]],
        'maxToolCalls' => 5,
        'include' => ['web_search_call.action.sources'],
        'safetyIdentifier' => hash('sha256', '01J9ZK3M6W0000000000000000'),
        'metadata' => ['app' => 'statementra', 'stage' => 'research'],
        'store' => false,
        'timeoutSeconds' => 60,
    ], $overrides));
}

it('sends a Responses API request with structured output, reasoning and web search', function () {
    Http::fake(['api.openai.test/*' => Http::response(apiResponse())]);

    app(OpenAiProvider::class)->send(sampleRequest());

    Http::assertSent(function (Request $http) {
        $body = $http->data();

        return $http->url() === 'https://api.openai.test/v1/responses'
            && $http->method() === 'POST'
            && $http->hasHeader('Authorization', 'Bearer sk-test-123')
            && $http->hasHeader('OpenAI-Organization', 'org_abc')
            && $http->hasHeader('OpenAI-Project', 'proj_xyz')
            && $body['model'] === 'gpt-6.1-sol'
            && $body['instructions'] === 'Be precise.'
            && $body['input'][0]['role'] === 'user'
            && $body['input'][0]['content'][1] === ['type' => 'input_image', 'image_url' => 'data:image/png;base64,AAAA', 'detail' => 'high']
            && $body['input'][0]['content'][2]['type'] === 'input_file'
            && $body['input'][0]['content'][2]['file_data'] === 'data:application/pdf;base64,BBBB'
            && $body['text']['format']['type'] === 'json_schema'
            && $body['text']['format']['name'] === 'claim_review'
            && $body['text']['format']['strict'] === true
            && $body['text']['format']['schema']['additionalProperties'] === false
            && $body['reasoning'] === ['effort' => 'high']
            && $body['max_output_tokens'] === 8000
            && $body['tools'][0]['type'] === 'web_search'
            && $body['tools'][0]['filters']['allowed_domains'] === ['ox.ac.uk']
            && $body['tools'][0]['user_location'] === ['type' => 'approximate', 'country' => 'GB']
            && $body['max_tool_calls'] === 5
            && $body['include'] === ['web_search_call.action.sources']
            && $body['store'] === false
            && strlen($body['safety_identifier']) === 64
            && $body['metadata'] === ['app' => 'statementra', 'stage' => 'research'];
    });
});

it('omits optional fields it was not given but always sends store', function () {
    Http::fake(['api.openai.test/*' => Http::response(apiResponse())]);

    app(OpenAiProvider::class)->send(new LlmRequest(model: 'gpt-6-luna', instructions: 'x', input: [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'y']]]]));

    Http::assertSent(fn (Request $http) => ! array_key_exists('tools', $http->data())
        && ! array_key_exists('max_tool_calls', $http->data())
        && ! array_key_exists('text', $http->data())
        && ! array_key_exists('reasoning', $http->data())
        && $http->data()['store'] === false);
});

it('parses output text, url citations, web search activity and usage', function () {
    Http::fake(['api.openai.test/*' => Http::response(apiResponse())]);

    $response = app(OpenAiProvider::class)->send(sampleRequest());

    expect($response->id)->toBe('resp_123')
        ->and($response->model)->toBe('gpt-6.1-sol-2026-09-01')
        ->and($response->isComplete())->toBeTrue()
        ->and(json_decode($response->text, true)['reviews'][0]['claim_id'])->toBe('C1')
        ->and($response->citations)->toBe([[
            'url' => 'https://www.ox.ac.uk/admissions/graduate/courses/msc-computer-science',
            'title' => 'MSc in Computer Science',
            'start_index' => 3,
            'end_index' => 40,
        ]])
        ->and($response->searchCalls)->toBe(2)
        ->and($response->searchQueries)->toBe(['oxford msc computer science modules'])
        ->and($response->searchSources)->toContain('https://www.cs.ox.ac.uk/teaching/msc/', 'https://www.cs.ox.ac.uk/research/')
        ->and($response->inputTokens)->toBe(1200)
        ->and($response->cachedInputTokens)->toBe(200)
        ->and($response->outputTokens)->toBe(300)
        ->and($response->reasoningTokens)->toBe(120)
        ->and($response->seenUrls())->toBe([
            'https://www.ox.ac.uk/admissions/graduate/courses/msc-computer-science',
            'https://www.cs.ox.ac.uk/teaching/msc',
            'https://www.cs.ox.ac.uk/research',
        ]);
});

it('reports an incomplete response with its reason', function () {
    Http::fake(['api.openai.test/*' => Http::response(apiResponse([
        'status' => 'incomplete',
        'incomplete_details' => ['reason' => 'max_output_tokens'],
    ]))]);

    $response = app(OpenAiProvider::class)->send(sampleRequest());

    expect($response->isIncomplete())->toBeTrue()
        ->and($response->incompleteReason)->toBe('max_output_tokens');
});

it('retries rate limits in-process, honouring Retry-After', function () {
    Http::fakeSequence('api.openai.test/*')
        ->push(['error' => ['message' => 'Rate limit reached', 'type' => 'requests', 'code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '2'])
        ->push(apiResponse());

    $response = app(OpenAiProvider::class)->send(sampleRequest());

    expect($response->id)->toBe('resp_123');
    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(2)->seconds()]);
});

it('gives up on persistent server errors with a retryable error', function () {
    Http::fake(['api.openai.test/*' => Http::response(['error' => ['message' => 'Overloaded']], 503)]);

    expect(fn () => app(OpenAiProvider::class)->send(sampleRequest()))
        ->toThrow(fn (LlmException $e) => expect($e->errorCode)->toBe('server_error')->and($e->retryable)->toBeTrue()->and($e->httpStatus)->toBe(503));

    Http::assertSentCount(OpenAiProvider::HTTP_RETRIES + 1);
});

it('does not retry client errors', function () {
    Http::fake(['api.openai.test/*' => Http::response(['error' => ['message' => "Invalid schema for response_format 'claim_review'", 'type' => 'invalid_request_error']], 400)]);

    expect(fn () => app(OpenAiProvider::class)->send(sampleRequest()))
        ->toThrow(fn (LlmException $e) => expect($e->errorCode)->toBe('client_error')->and($e->retryable)->toBeFalse()->and($e->getMessage())->toContain('Invalid schema'));

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('treats an exhausted quota as permanent', function () {
    Http::fake(['api.openai.test/*' => Http::response(['error' => ['message' => 'You exceeded your current quota', 'code' => 'insufficient_quota']], 429)]);

    expect(fn () => app(OpenAiProvider::class)->send(sampleRequest()))
        ->toThrow(fn (LlmException $e) => expect($e->errorCode)->toBe('insufficient_quota')->and($e->retryable)->toBeFalse());

    Http::assertSentCount(1);
});

it('surfaces timeouts as retryable without waiting in-process', function () {
    Http::fake(['api.openai.test/*' => Http::failedConnection('cURL error 28: Operation timed out after 60001 milliseconds')]);

    expect(fn () => app(OpenAiProvider::class)->send(sampleRequest()))
        ->toThrow(fn (LlmException $e) => expect($e->errorCode)->toBe('timeout')->and($e->retryable)->toBeTrue());

    Sleep::assertNeverSlept();
});

it('refuses to run without an API key', function () {
    config(['statementra.ai.openai_api_key' => null]);
    Http::fake();

    expect(fn () => app(OpenAiProvider::class)->send(sampleRequest()))
        ->toThrow(fn (LlmException $e) => expect($e->errorCode)->toBe('not_configured'));

    Http::assertNothingSent();
});

it('retries a truncated response once with a larger budget through the gateway', function () {
    $this->setUpPipeline();
    app()->instance(PipelineTrigger::class, new RecordingTrigger);
    $order = $this->paidOrder();
    $job = app(PipelineDispatcher::class)->startForOrder($order);
    $job->forceFill(['provider' => 'openai'])->save();

    $step = AiJobStep::query()->create(['ai_job_id' => $job->id, 'stage' => PipelineStage::Verification, 'sequence' => 1, 'attempt' => 1, 'status' => StepStatus::Running, 'started_at' => now()]);
    $ctx = new StageContext($job, $order, $step, PipelineStage::Verification, microtime(true) + 600);

    Http::fakeSequence('api.openai.test/*')
        ->push(apiResponse(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]))
        ->push(apiResponse());

    $result = app(LlmGateway::class)->call($ctx, new LlmCall(task: 'verification', variables: ['order_details' => ['institution' => 'University of Oxford'], 'claims' => []]));

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
    expect($result->data['reviews'][0]['supported'])->toBeTrue()
        ->and($sent)->toHaveCount(2)
        ->and($sent[1]['max_output_tokens'])->toBe($sent[0]['max_output_tokens'] * 2)
        ->and($sent[0]['model'])->toBe('gpt-6.1-sol')
        ->and($sent[0]['text']['format']['name'])->toBe('claim_review')
        ->and($sent[0]['instructions'])->toContain('<untrusted_data')
        ->and($sent[0]['input'][0]['content'][0]['text'])->toContain('University of Oxford')
        ->and(AiUsage::query()->where('ai_job_id', $job->id)->pluck('status')->all())->toBe(['incomplete', 'success'])
        ->and(AiUsage::query()->where('ai_job_id', $job->id)->where('status', 'success')->value('model'))->toBe('gpt-6.1-sol-2026-09-01')
        ->and((float) AiUsage::query()->where('ai_job_id', $job->id)->where('status', 'success')->value('estimated_cost_usd'))->toBeGreaterThan(0.0);
});
