<?php

namespace App\Domain\Ai;

use App\Models\Applicant;
use App\Models\InformationRequest;

/**
 * Adds the customer's answers to follow-up questions to the applicant
 * profile without a model call. Each answer becomes a verified fact that
 * quotes the answer verbatim and cites its order answer (followup_{request}_{key}),
 * so the pipeline can continue from analysis instead of reading every
 * upload again: answering costs one short analysis call, not a new ingestion.
 */
class FollowUpFacts
{
    private const MAX_QUOTE = 1500;

    /** Keyword → profile category, checked in this order; anything else is "other". */
    private const CATEGORIES = [
        'motivation' => ['why', 'interest', 'motivat', 'inspir', 'passion'],
        'career_goal' => ['goal', 'future', 'career', 'plan', 'hope to'],
        'project' => ['project', 'research', 'thesis'],
        'achievement' => ['achiev', 'award', 'result', 'proud'],
        'work_history' => ['work', 'job', 'role', 'intern', 'employ', 'volunteer'],
        'academic_history' => ['study', 'studied', 'course', 'degree', 'grade', 'module', 'universit', 'school'],
    ];

    /**
     * @param  array<string, string>  $answers  cleaned answers keyed by question key (q1, q2...)
     * @return bool false when there is no applicant profile yet (the pipeline then starts from ingestion)
     */
    public function merge(InformationRequest $request, array $answers): bool
    {
        $applicant = Applicant::query()->where('order_id', $request->order_id)->lockForUpdate()->first();
        if (! $applicant || ! is_array($applicant->profile)) {
            return false;
        }

        $profile = $applicant->profile;
        $facts = array_values(array_filter((array) ($profile['facts'] ?? []), fn ($fact) => ! str_starts_with((string) ($fact['source_ref'] ?? ''), 'followup_'.$request->id.'_')));

        foreach ((array) $request->questions as $question) {
            $answer = trim((string) ($answers[$question['key']] ?? ''));
            if ($answer === '') {
                continue;
            }

            $facts[] = [
                'id' => 'FU'.$request->id.'-'.$question['key'],
                'category' => $this->category((string) $question['question']),
                'statement' => 'Asked "'.$question['question'].'", the applicant answered: '.$answer,
                'date' => null,
                'source_type' => 'answer',
                'source_ref' => 'followup_'.$request->id.'_'.$question['key'],
                'evidence_quote' => mb_substr($answer, 0, self::MAX_QUOTE),
                'confidence' => 'high',
                'quote_verified' => true,
            ];
        }

        $profile['facts'] = $facts;
        $applicant->forceFill(['profile' => $profile])->save();

        return true;
    }

    private function category(string $question): string
    {
        $text = mb_strtolower($question);
        foreach (self::CATEGORIES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
                }
            }
        }

        return 'other';
    }
}
