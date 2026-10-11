<?php

use App\Domain\Ai\Writing\AnswerCopyCheck;
use App\Domain\Ai\Writing\StyleLinter;
use App\Domain\Documents\DocumentModel;

function cvDraft(string ...$bullets): DocumentModel
{
    return new DocumentModel('Ama Mensah', null, null, array_map(fn ($b) => ['type' => 'bullet', 'text' => $b], $bullets));
}

it('flags a bullet that repeats the customer\'s answer word for word, and not a real rewrite', function () {
    $answers = ['I prepare lesson plans and teaching notes for Integrated Science for the junior high students every week.'];

    $copied = (new AnswerCopyCheck)->find(cvDraft('Prepare lesson plans and teaching notes for Integrated Science for the junior high students every week.'), $answers);
    $rewritten = (new AnswerCopyCheck)->find(cvDraft('Plan and deliver weekly Integrated Science lessons for junior high learners, with notes and materials.'), $answers);

    expect($copied)->toHaveCount(1)
        ->and($copied[0]['type'])->toBe(AnswerCopyCheck::TYPE)
        ->and($rewritten)->toBe([]);
});

it('counts copied answers toward the editorial pass', function () {
    $finding = fn () => ['type' => AnswerCopyCheck::TYPE, 'severity' => 'medium', 'message' => '', 'excerpt' => null];

    expect(StyleLinter::needsEditorialPass([$finding(), $finding()]))->toBeTrue();
});
