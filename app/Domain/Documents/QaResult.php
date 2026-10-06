<?php

namespace App\Domain\Documents;

/** Outcome of file validation for a rendered document version. */
final class QaResult
{
    /** @param list<array{check:string,passed:bool,detail:string}> $checks */
    public function __construct(public readonly bool $passed, public readonly array $checks) {}

    /** @return list<array{check:string,passed:bool,detail:string}> */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => ! $c['passed']));
    }
}
