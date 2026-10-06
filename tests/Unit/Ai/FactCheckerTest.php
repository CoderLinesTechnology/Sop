<?php

use App\Domain\Ai\Writing\EvidenceCorpus;
use App\Domain\Ai\Writing\FactChecker;
use App\Domain\Ai\Writing\SentenceSplitter;
use App\Domain\Documents\DocumentModel;

function doc(string ...$paragraphs): DocumentModel
{
    return new DocumentModel('Personal Statement', null, null, array_map(fn ($p) => ['type' => 'paragraph', 'text' => $p], $paragraphs));
}

function corpus(): EvidenceCorpus
{
    return new EvidenceCorpus([
        'I completed a BSc in Computer Science at the University of Ghana in 2023 with a GPA of 3.80.',
        'I worked at Hubtel for two years and our team processed 1,200 tickets a week.',
        'MSc Computer Science',
        'University of Oxford',
    ]);
}

$order = ['institution' => 'University of Oxford', 'programme' => 'MSc Computer Science'];

it('accepts numbers that appear in the evidence, in any written form', function () use ($order) {
    $issues = (new FactChecker)->check(doc('In 2023 I graduated with a 3.8 GPA.', 'We handled 1200 tickets a week for 2 years.'), corpus(), $order);

    expect($issues)->toBe([]);
});

it('flags numbers, percentages and years that the evidence does not support', function () use ($order) {
    $issues = (new FactChecker)->check(doc('My work cut costs by 35% in 2019.'), corpus(), $order);

    expect(array_column($issues, 'type'))->toBe([FactChecker::UNSUPPORTED_NUMBER])
        ->and($issues[0]['problem'])->toContain('35%')
        ->and($issues[0]['excerpt'])->toBe('My work cut costs by 35% in 2019.');
});

it('flags institutions and degrees that are neither the order nor the applicant\'s own', function () use ($order) {
    $checker = new FactChecker;

    expect($checker->check(doc('Oxford University offers exactly what I need.'), corpus(), $order))->toBe([])
        ->and($checker->check(doc('I studied at the University of Ghana before applying.'), corpus(), $order))->toBe([])
        ->and(array_column($checker->check(doc('I am excited to join the University of Cambridge.'), corpus(), $order), 'type'))->toBe([FactChecker::UNKNOWN_INSTITUTION])
        ->and(array_column($checker->check(doc('The MSc Data Science suits me.'), corpus(), $order), 'type'))->toBe([FactChecker::UNKNOWN_PROGRAMME])
        ->and($checker->check(doc('During University I learned a lot.', 'The University gave me space.'), corpus(), $order))->toBe([]);
});

it('flags URLs, citations and placeholders', function () use ($order) {
    $checker = new FactChecker;
    $types = fn (string $text, bool $citations = false) => array_column($checker->check(doc($text), corpus(), $order, $citations), 'type');

    expect($types('See https://example.com for details.'))->toBe([FactChecker::URL_OR_CITATION])
        ->and($types('As shown by prior work (Smith, 2020).'))->toEqualCanonicalizing([FactChecker::URL_OR_CITATION, FactChecker::UNSUPPORTED_NUMBER])
        ->and($types('See https://example.com for details.', citations: true))->toBe([])
        ->and($types('I am excited to join [University Name] next year.'))->toBe([FactChecker::PLACEHOLDER]);
});

it('removes only the sentences with mechanical problems', function () use ($order) {
    $checker = new FactChecker;
    $document = doc('I graduated in 2023. My work cut costs by 35%. I enjoyed it.');

    $clean = $checker->sanitize($document, $checker->check($document, corpus(), $order));

    expect($clean->paragraphs())->toBe(['I graduated in 2023. I enjoyed it.']);
});

it('splits sentences without breaking abbreviations or decimals', function () {
    expect(SentenceSplitter::split('I studied with Dr. Mensah, e.g. on NLP. My GPA was 3.8 out of 4. "Why not?" was my first thought. It worked!'))
        ->toBe(['I studied with Dr. Mensah, e.g. on NLP.', 'My GPA was 3.8 out of 4.', '"Why not?" was my first thought.', 'It worked!']);
});

it('normalises numbers consistently', function () {
    expect(EvidenceCorpus::normalizeNumber('1,200'))->toBe('1200')
        ->and(EvidenceCorpus::normalizeNumber('3.80'))->toBe('3.8')
        ->and(EvidenceCorpus::normalizeNumber('0.5'))->toBe('0.5')
        ->and(EvidenceCorpus::normalizeNumber('007'))->toBe('7')
        ->and(EvidenceCorpus::normalizeNumber('4.0'))->toBe('4')
        ->and(array_column(EvidenceCorpus::extractNumbers('Ranked 1st of 250 (12.5 per cent) in 2024'), 'value'))->toBe(['1', '250', '12.5', '2024']);
});
