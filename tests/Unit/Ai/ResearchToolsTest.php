<?php

use App\Domain\Ai\Llm\LlmResponse;
use App\Domain\Ai\Research\QuoteMatcher;
use App\Domain\Ai\Research\SourceClassifier;
use App\Domain\Ai\Research\UrlNormalizer;
use App\Enums\SourceType;

it('matches quotes exactly or fuzzily after normalisation', function () {
    $page = '<p>The MSc in Computer Science is a one-year programme. Students take five option courses&nbsp;and complete a dissertation project supervised by faculty.</p>';
    $matcher = new QuoteMatcher;

    expect($matcher->score('Students take five option courses and complete a dissertation project', strip_tags($page)))->toBe(1.0)
        ->and($matcher->matches('“students take FIVE option-courses and complete a dissertation project”', strip_tags($page)))->toBeTrue()
        ->and($matcher->matches('Students take five courses and complete a supervised dissertation project', strip_tags($page)))->toBeTrue()
        ->and($matcher->matches('Students take seven elective modules and an industry placement abroad', strip_tags($page)))->toBeFalse()
        ->and($matcher->score('one year', strip_tags($page)))->toBe(1.0)
        ->and($matcher->score('', strip_tags($page)))->toBe(0.0);
});

it('classifies sources by domain and trusts the model only for official domains', function () {
    $classifier = new SourceClassifier;
    $official = ['ox.ac.uk'];

    $programme = $classifier->classify('https://www.cs.ox.ac.uk/teaching/msc', $official, 'official_programme');
    $blogClaimingOfficial = $classifier->classify('https://www.topuniversities.com/oxford', $official, 'official_programme');

    expect($programme['type'])->toBe(SourceType::OfficialProgramme)
        ->and($programme['official'])->toBeTrue()
        ->and($programme['rank'])->toBe(1)
        ->and($classifier->classify('https://www.ox.ac.uk/admissions', $official, 'secondary')['type'])->toBe(SourceType::OfficialUniversity)
        ->and($blogClaimingOfficial['type'])->toBe(SourceType::Secondary)
        ->and($blogClaimingOfficial['official'])->toBeFalse()
        ->and($classifier->classify('https://www.ucas.com/undergraduate/applying', [], null)['type'])->toBe(SourceType::ApplicationPlatform)
        ->and($classifier->classify('https://www.gov.uk/student-visa', [], null)['type'])->toBe(SourceType::Government)
        ->and($classifier->classify('https://studyinjapan.go.jp/en', [], null)['type'])->toBe(SourceType::OfficialScholarship)
        ->and($classifier->classify('https://www.chevening.org/scholarships/', [], null)['type'])->toBe(SourceType::OfficialScholarship)
        ->and($classifier->classify('https://notox.ac.uk.evil.com/', $official, 'official_programme')['type'])->toBe(SourceType::Secondary);
});

it('normalises URLs for comparison and storage', function () {
    expect(UrlNormalizer::normalize('HTTPS://WWW.Ox.AC.uk:443/Admissions/?utm_source=x&b=2&a=1#top'))->toBe('https://www.ox.ac.uk/Admissions?a=1&b=2')
        ->and(UrlNormalizer::normalize('ftp://example.com/file'))->toBeNull()
        ->and(UrlNormalizer::normalize('javascript:alert(1)'))->toBeNull()
        ->and(UrlNormalizer::bareDomain('https://www.ox.ac.uk/admissions'))->toBe('ox.ac.uk')
        ->and(UrlNormalizer::bareDomain('ox.ac.uk'))->toBe('ox.ac.uk')
        ->and(UrlNormalizer::bareDomain('not a domain'))->toBeNull()
        ->and(UrlNormalizer::hostMatches('www.cs.ox.ac.uk', 'ox.ac.uk'))->toBeTrue()
        ->and(UrlNormalizer::hostMatches('fox.ac.uk', 'ox.ac.uk'))->toBeFalse();
});

it('parses refusals and citations from the Responses API format', function () {
    $response = LlmResponse::fromApi([
        'id' => 'resp_1',
        'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]]],
    ]);

    expect($response->refusal)->toBe('I cannot help with that.')
        ->and($response->text)->toBe('')
        ->and($response->searchCalls)->toBe(0);
});
