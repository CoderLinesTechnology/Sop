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

    public function check(string $name): ?array
    {
        foreach ($this->checks as $check) {
            if ($check['check'] === $name) {
                return $check;
            }
        }

        return null;
    }

    /** One line per failed check, for logs and admin notifications. */
    public function summary(): string
    {
        return $this->passed
            ? 'All '.count($this->checks).' file checks passed.'
            : implode(' ', array_map(fn ($c) => "[{$c['check']}] {$c['detail']}", $this->failures()));
    }

    /** Shape stored in document_versions.qa_results. */
    public function toArray(): array
    {
        return ['passed' => $this->passed, 'checks' => $this->checks];
    }

    public static function fromArray(array $data): self
    {
        return new self((bool) ($data['passed'] ?? false), array_values((array) ($data['checks'] ?? [])));
    }
}
