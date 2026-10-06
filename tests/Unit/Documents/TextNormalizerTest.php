<?php

use App\Domain\Documents\Qa\TextNormalizer;

it('normalises ligatures, quotes, dashes and invisible characters', function () {
    expect(TextNormalizer::normalize("“Eﬃcient” — it’s\u{00AD}fine…\u{200B}"))->toBe('"Efficient" - it\'sfine...')
        ->and(TextNormalizer::compact("A  b\n\tc\u{00A0}d"))->toBe('Abcd');
});

it('accepts identical text regardless of wrapping', function () {
    $result = TextNormalizer::compare(TextNormalizer::compact("One paragraph.\n\nAnother one."), TextNormalizer::compactExtracted("One\nparagraph. Another\none."));

    expect($result['equal'])->toBeTrue();
});

it('undoes hyphenation without losing real hyphens', function () {
    $expected = TextNormalizer::compact('An international, self-motivated student.');

    expect(TextNormalizer::compare($expected, TextNormalizer::compactExtracted("An inter-\nnational, self-\nmotivated student."))['equal'])->toBeTrue()
        ->and(TextNormalizer::compare($expected, TextNormalizer::compact('An international, selfmotivated student.'))['equal'])->toBeTrue() // pdftotext joined "self-|motivated"
        ->and(TextNormalizer::compare($expected, TextNormalizer::compact('An international, self motivated pupil.'))['equal'])->toBeFalse();
});

it('explains missing, extra and changed text', function () {
    $expected = TextNormalizer::compact('First sentence. Second sentence. Third sentence.');

    $missing = TextNormalizer::compare($expected, TextNormalizer::compact('First sentence. Second sentence.'), 'PDF');
    $extra = TextNormalizer::compare($expected, TextNormalizer::compact('First sentence. Second sentence. Third sentence. Internal note.'), 'PDF');
    $changed = TextNormalizer::compare($expected, TextNormalizer::compact('First sentence. Second phrase. Third sentence.'), 'DOCX');

    expect($missing['equal'])->toBeFalse()
        ->and($missing['detail'])->toContain('missing text')->toContain('Thirdsentence')
        ->and($extra['detail'])->toContain('unexpected extra text')->toContain('Internalnote')
        ->and($changed['detail'])->toStartWith('DOCX text differs')->toContain('after “Firstsentence.Second”')->toContain('found “phrase.Third');
});

it('splits extracted text into compact lines', function () {
    expect(TextNormalizer::extractedLines("  Page 1 of 2 \n\nHello wor-\nld\n"))->toBe(['Page1of2', 'Hellowor'.TextNormalizer::LINE_END_HYPHEN, 'ld']);
});
