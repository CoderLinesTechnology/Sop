<?php

use App\Domain\Documents\DocumentModel;
use App\Domain\Documents\QaResult;
use App\Domain\Documents\ResolvedRequirements;

it('groups body blocks into sections and separates answers from questions', function () {
    $model = DocumentModel::fromArray(['title' => 'Personal Statement', 'blocks' => [
        ['type' => 'paragraph', 'text' => 'Opening.'],
        ['type' => 'heading', 'text' => 'Question one?'],
        ['type' => 'paragraph', 'text' => 'Answer one.'],
        ['type' => 'paragraph', 'text' => 'More of answer one.'],
        ['type' => 'heading', 'text' => 'Question two?'],
        ['type' => 'paragraph', 'text' => 'Answer two.'],
    ]]);

    expect($model->sections())->toBe([
        ['heading' => null, 'text' => 'Opening.'],
        ['heading' => 'Question one?', 'text' => "Answer one.\n\nMore of answer one."],
        ['heading' => 'Question two?', 'text' => 'Answer two.'],
    ])
        ->and($model->answerText())->toBe("Opening.\n\nAnswer one.\n\nMore of answer one.\n\nAnswer two.");

    $platform = new ResolvedRequirements(limitsIncludeHeadings: false);
    $default = new ResolvedRequirements;

    expect($platform->countWords($model))->toBe(9)
        ->and($default->countWords($model))->toBe(13)
        ->and($platform->countCharacters($model))->toBeLessThan($default->countCharacters($model));
});

it('round-trips the shared DTOs through arrays', function () {
    $requirements = new ResolvedRequirements(maxWords: 650, limitsIncludeHeadings: false, fieldSources: ['maxWords' => 'customer']);
    $restored = ResolvedRequirements::fromArray(json_decode(json_encode($requirements->toArray()), true));

    expect($restored->maxWords)->toBe(650)
        ->and($restored->limitsIncludeHeadings)->toBeFalse()
        ->and($restored->fieldSources)->toBe(['maxWords' => 'customer'])
        ->and($restored->isConvention('maxWords'))->toBeFalse()
        ->and(ResolvedRequirements::fromArray(['pageSize' => 'A4', 'fieldSources' => ['pageSize' => 'rule:country']])->isConvention('pageSize'))->toBeTrue()
        ->and(ResolvedRequirements::fromArray(['maxWords' => 500])->limitsIncludeHeadings)->toBeTrue(); // older snapshots

    $result = new QaResult(false, [
        ['check' => 'pdf_opens', 'passed' => true, 'detail' => 'ok'],
        ['check' => 'word_limits', 'passed' => false, 'detail' => 'Too long.'],
    ]);

    expect(QaResult::fromArray($result->toArray()))->toEqual($result)
        ->and($result->check('word_limits')['passed'])->toBeFalse()
        ->and($result->check('nope'))->toBeNull()
        ->and($result->summary())->toBe('[word_limits] Too long.');
});
