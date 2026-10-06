<?php

namespace App\Filament\Support\Catalogue;

use App\Models\PromptVersion;
use Illuminate\Support\HtmlString;

/** Side-by-side comparison of a prompt version with the active version of its key. */
final class PromptDiff
{
    /** Above this many line pairs the line diff is skipped (side-by-side only). */
    private const MAX_CELLS = 250_000;

    public static function compare(PromptVersion $version): HtmlString
    {
        $active = PromptVersion::activeFor($version->prompt_key);

        if (! $active) {
            return new HtmlString('<p>There is no active version of <code>'.e($version->prompt_key).'</code> yet, so there is nothing to compare with.</p>');
        }

        if ($active->is($version)) {
            return new HtmlString('<p>This is the active version.</p>');
        }

        $html = self::metaTable($active, $version);

        foreach (['system_prompt' => 'System prompt', 'user_template' => 'User template'] as $field => $label) {
            $html .= '<h3 style="font-weight: 600; margin: 1.25rem 0 .5rem;">'.e($label).'</h3>';
            $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: .75rem;">'
                .self::column('Active · v'.$active->version, (string) $active->{$field})
                .self::column(ucfirst($version->status).' · v'.$version->version, (string) $version->{$field})
                .'</div>';
            $html .= self::lineDiff((string) $active->{$field}, (string) $version->{$field});
        }

        return new HtmlString($html);
    }

    /**
     * Unified line diff (longest common subsequence).
     *
     * @return list<array{0: string, 1: string}> [op (" ", "-", "+"), line]
     */
    public static function lines(string $from, string $to): array
    {
        $a = $from === '' ? [] : preg_split('/\R/', $from);
        $b = $to === '' ? [] : preg_split('/\R/', $to);
        $n = count($a);
        $m = count($b);

        if ($n * $m > self::MAX_CELLS) {
            return [];
        }

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $diff = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $diff[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $diff[] = ['-', $a[$i++]];
            } else {
                $diff[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $diff[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $diff[] = ['+', $b[$j++]];
        }

        return $diff;
    }

    private static function lineDiff(string $from, string $to): string
    {
        if ($from === $to) {
            return '<p style="color: var(--gray-500); margin-top: .5rem;">No changes.</p>';
        }

        $lines = self::lines($from, $to);
        if ($lines === []) {
            return '<p style="color: var(--gray-500); margin-top: .5rem;">Too long for a line-by-line comparison.</p>';
        }

        $added = count(array_filter($lines, fn ($line) => $line[0] === '+'));
        $removed = count(array_filter($lines, fn ($line) => $line[0] === '-'));

        $rows = '';
        foreach ($lines as [$op, $line]) {
            $style = match ($op) {
                '+' => 'background: var(--success-50); color: var(--success-800);',
                '-' => 'background: var(--danger-50); color: var(--danger-800); text-decoration: line-through;',
                default => 'color: var(--gray-500);',
            };
            $rows .= '<div style="'.$style.' padding: 0 .5rem; white-space: pre-wrap; word-break: break-word;">'.e($op.' '.$line).'</div>';
        }

        return '<details style="margin-top: .5rem;" open><summary style="cursor: pointer;">Changes: <span style="color: var(--success-700);">+'.$added.'</span> / <span style="color: var(--danger-700);">−'.$removed.'</span> lines</summary>'
            .'<div style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; line-height: 1.5; border: 1px solid var(--gray-200); border-radius: .5rem; margin-top: .5rem; max-height: 24rem; overflow: auto;">'
            .$rows.'</div></details>';
    }

    private static function column(string $title, string $text): string
    {
        return '<div style="min-width: 0;"><div style="font-size: .75rem; font-weight: 600; color: var(--gray-500); margin-bottom: .25rem;">'.e($title).'</div>'
            .'<pre style="white-space: pre-wrap; word-break: break-word; font-size: .75rem; line-height: 1.5; padding: .75rem; border: 1px solid var(--gray-200); border-radius: .5rem; max-height: 28rem; overflow: auto; margin: 0;">'
            .e($text !== '' ? $text : '(empty)').'</pre></div>';
    }

    private static function metaTable(PromptVersion $active, PromptVersion $version): string
    {
        $row = fn (string $label, ?string $a, ?string $b): string => '<tr><th style="text-align: left; padding: .25rem .75rem .25rem 0; font-weight: 500; color: var(--gray-500);">'.e($label).'</th>'
            .'<td style="padding: .25rem .75rem;">'.e($a ?: '—').'</td>'
            .'<td style="padding: .25rem .75rem;'.(($a ?: '') !== ($b ?: '') ? ' font-weight: 600;' : '').'">'.e($b ?: '—').'</td></tr>';

        return '<table style="width: 100%; font-size: .875rem;"><thead><tr><th></th>'
            .'<th style="text-align: left; padding: .25rem .75rem;">Active (v'.$active->version.')</th>'
            .'<th style="text-align: left; padding: .25rem .75rem;">This version (v'.$version->version.')</th></tr></thead><tbody>'
            .$row('Label', $active->label, $version->label)
            .$row('Model fallback', $active->model, $version->model)
            .$row('Reasoning fallback', $active->reasoning_effort, $version->reasoning_effort)
            .'</tbody></table>';
    }
}
