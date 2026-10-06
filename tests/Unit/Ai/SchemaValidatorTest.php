<?php

use App\Domain\Ai\Llm\FakeOutputs;
use App\Domain\Ai\Llm\LlmRequest;
use App\Domain\Ai\Llm\SchemaValidator;
use App\Domain\Ai\Prompts\Schemas;

it('follows OpenAI strict mode rules in every schema', function (string $key) {
    $schema = Schemas::for($key);

    expect(SchemaValidator::strictModeProblems($schema['schema']))->toBe([])
        ->and($schema['name'])->toMatch('/^[a-zA-Z0-9_-]{1,64}$/')
        ->and($schema['schema']['type'])->toBe('object');
})->with(Schemas::keys());

it('accepts valid data and explains invalid data', function () {
    $schema = Schemas::for('verification')['schema'];
    $validator = new SchemaValidator;

    expect($validator->validate(['reviews' => [['claim_id' => 'C1', 'supported' => true, 'notes' => 'ok']], 'conflicts' => []], $schema))->toBe([]);

    $errors = $validator->validate(['reviews' => [['claim_id' => 'C1', 'supported' => 'yes', 'extra' => 1]]], $schema);
    expect($errors)->toContain('$: missing required property "conflicts"')
        ->toContain('$.reviews[0]: missing required property "notes"')
        ->toContain('$.reviews[0]: unexpected property "extra"')
        ->toContain('$.reviews[0].supported: expected boolean, got string');
});

it('checks enums, nullable types and numeric ranges', function () {
    $validator = new SchemaValidator;
    $schema = Schemas::for('analysis')['schema'];
    $data = [
        'prompt_interpretation' => 'x', 'essay_questions' => [], 'qualities_to_demonstrate' => [], 'experiences_to_emphasise' => [],
        'research_questions' => [['question' => 'q', 'purpose' => 'gossip']],
        'claims_requiring_verification' => [], 'official_domains' => [['domain' => 'ox.ac.uk', 'entity' => 'Oxford', 'confidence' => 1.5]],
        'application_platform' => null,
        'stated_limits' => ['max_words' => null, 'min_words' => null, 'max_characters' => 4000, 'min_characters' => null, 'max_pages' => null, 'source' => null, 'quote' => null],
        'required_sections' => [], 'language_variant' => null, 'language_variant_source' => 'unknown', 'missing_information' => [], 'risks' => [],
    ];

    $errors = $validator->validate($data, $schema);

    expect($errors)->toHaveCount(2)
        ->and($errors[0])->toContain('$.research_questions[0].purpose: value must be one of')
        ->and($errors[1])->toBe('$.official_domains[0].confidence: must be <= 1');
});

it('produces schema-valid fake output for every task, even from sparse context', function (string $task) {
    $request = new LlmRequest(model: 'fake', instructions: '', input: [], task: $task, context: [
        'order' => ['institution' => 'University of Lagos', 'programme' => 'MSc Data Science', 'essay_question' => 'Why? (500 words)'],
        'document' => ['title' => 'Personal Statement', 'blocks' => [['index' => 1, 'type' => 'paragraph', 'text' => 'I built a model in 2021. It worked well.']]],
        'claims' => [['claim_id' => 'C1', 'claim' => 'The programme has a capstone.']],
    ]);

    $output = (new FakeOutputs)->generate($request);

    expect((new SchemaValidator)->validate($output, Schemas::for($task)['schema']))->toBe([]);
})->with(Schemas::keys());
