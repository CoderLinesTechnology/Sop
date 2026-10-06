<?php

namespace App\Domain\Documents;

/**
 * The requirements an order's document must satisfy, resolved from (in order
 * of authority) the institution's official instructions, the official
 * application platform, official country/education guidance, administrator
 * requirement rules and the service defaults. Fields are null when no source
 * imposes them; the system never invents a requirement.
 */
final class ResolvedRequirements
{
    public const LANGUAGE_VARIANTS = [
        'en-GB' => 'British English',
        'en-US' => 'American English',
        'en-CA' => 'Canadian English',
        'en-AU' => 'Australian English',
        'en-NZ' => 'New Zealand English',
        'en-IE' => 'Irish English',
        'en-ZA' => 'South African English',
        'en-IN' => 'Indian English',
    ];

    /**
     * @param  list<array{heading:string,question?:string,min_characters?:int,max_characters?:int,min_words?:int,max_words?:int}>  $requiredSections
     * @param  list<string>  $prohibitedContent
     * @param  list<string>  $fileTypes
     * @param  list<array{name:string,url:?string,type:string,checked_at:?string}>  $sources
     * @param  list<array{field:string,candidates:array,chosen:mixed,reason:string}>  $conflicts
     * @param  list<int>  $appliedRuleIds
     */
    public function __construct(
        public ?int $minWords = null,
        public ?int $maxWords = null,
        public ?int $minCharacters = null,
        public ?int $maxCharacters = null,
        public ?int $maxPages = null,
        public int $targetWords = 750,
        public string $languageVariant = 'en-GB',
        public string $dateFormat = 'j F Y',
        public ?string $pageSize = null,
        public ?string $fontFamily = null,
        public ?float $fontSize = null,
        public ?float $marginsMm = null,
        public ?float $lineSpacing = null,
        public array $requiredSections = [],
        public array $prohibitedContent = [],
        public ?string $applicationPlatform = null,
        public ?string $submissionMethod = null,
        public ?string $specialInstructions = null,
        public array $fileTypes = ['pdf', 'docx'],
        public ?string $namingConvention = null,
        public array $sources = [],
        public array $conflicts = [],
        public array $appliedRuleIds = [],
    ) {}

    public function languageName(): string
    {
        return self::LANGUAGE_VARIANTS[$this->languageVariant] ?? 'British English';
    }

    /** Human-readable summary of the binding limits (used in prompts and logs). */
    public function limitsSummary(): string
    {
        $parts = [];
        if ($this->maxWords) {
            $parts[] = ($this->minWords ? "{$this->minWords}–" : 'at most ')."{$this->maxWords} words";
        } elseif ($this->minWords) {
            $parts[] = "at least {$this->minWords} words";
        }
        if ($this->maxCharacters) {
            $parts[] = ($this->minCharacters ? "{$this->minCharacters}–" : 'at most ')."{$this->maxCharacters} characters including spaces";
        }
        if ($this->maxPages) {
            $parts[] = "at most {$this->maxPages} page(s)";
        }

        return $parts ? implode('; ', $parts) : "no official limit found (target about {$this->targetWords} words)";
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        $instance = new self;
        foreach (get_object_vars($instance) as $key => $default) {
            if (array_key_exists($key, $data)) {
                $instance->{$key} = $data[$key];
            }
        }

        return $instance;
    }
}
