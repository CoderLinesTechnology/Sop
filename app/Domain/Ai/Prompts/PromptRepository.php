<?php

namespace App\Domain\Ai\Prompts;

use App\Models\AiJob;
use App\Models\PromptVersion;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Resolves the prompt a job must use for a key. Jobs are reproducible: the
 * version ids active when the job started are snapshotted in
 * ai_jobs.prompt_versions and used for the whole job, even if an
 * administrator activates a newer version meanwhile. Keys missing from the
 * snapshot use the currently active version (recorded on the job); if a key
 * has no version at all, the built-in default is used and logged.
 */
class PromptRepository
{
    public function resolve(AiJob $job, string $key): ResolvedPrompt
    {
        $snapshot = (array) ($job->prompt_versions ?? []);
        $version = null;

        if (isset($snapshot[$key]['id'])) {
            $version = PromptVersion::query()->find($snapshot[$key]['id']);
        }

        if (! $version) {
            $version = PromptVersion::activeFor($key);
            $this->remember($job, $key, $version ? ['id' => $version->id, 'version' => $version->version] : ['id' => null, 'version' => 'builtin']);
        }

        $default = DefaultPrompts::get($key);

        if ($version) {
            $userTemplate = trim((string) $version->user_template);
            if ($userTemplate === '' && $default) {
                Log::warning('Prompt version has no user template; using the built-in template.', ['prompt_key' => $key, 'version' => $version->version]);
                $userTemplate = $default['user_template'];
            }

            return new ResolvedPrompt($key, (string) $version->system_prompt, $userTemplate, $version);
        }

        if (! $default) {
            throw new LogicException("No prompt is configured for key [{$key}].");
        }

        Log::warning('No active prompt version; using the built-in default.', ['prompt_key' => $key]);

        return new ResolvedPrompt($key, $default['system_prompt'], $default['user_template'], null);
    }

    /** Snapshot of every active prompt version (key => {id, version}). */
    public static function activeSnapshot(): array
    {
        return PromptVersion::query()->active()->orderBy('prompt_key')->get()
            ->mapWithKeys(fn (PromptVersion $p) => [$p->prompt_key => ['id' => $p->id, 'version' => $p->version]])
            ->all();
    }

    private function remember(AiJob $job, string $key, array $entry): void
    {
        $versions = (array) ($job->prompt_versions ?? []);
        if (($versions[$key] ?? null) === $entry) {
            return;
        }

        $versions[$key] = $entry;
        $job->prompt_versions = $versions;
        $job->save();
    }
}
