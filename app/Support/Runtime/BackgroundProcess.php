<?php

namespace App\Support\Runtime;

use Illuminate\Support\Facades\Log;

/**
 * Starts an artisan command as a detached command-line process: a new
 * session, no output, not waited for. Used where the web server cuts off
 * long requests (Hostinger's LiteSpeed stops a PHP request about two minutes
 * after it started, even after the response was sent) for work that can take
 * longer, such as one AI pipeline stage.
 *
 * Only fixed commands built by the application are run, never input from a
 * request; every argument is shell-escaped.
 */
class BackgroundProcess
{
    /** @param list<string> $arguments artisan command name and its arguments */
    public function artisan(array $arguments): bool
    {
        $php = (string) config('statementra.runtime.php_binary', '/usr/bin/php');
        if ($php === '' || ! is_executable($php) || ! function_exists('proc_open')) {
            Log::warning('Background process unavailable', ['php' => $php, 'proc_open' => function_exists('proc_open')]);

            return false;
        }

        $command = implode(' ', array_map('escapeshellarg', [$php, base_path('artisan'), ...$arguments]));
        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
        $nohup = is_executable('/usr/bin/nohup') ? '/usr/bin/nohup ' : '';

        $process = @proc_open(
            $setsid.$nohup.$command.' > /dev/null 2>&1 &',
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            Log::warning('Background process could not be started', ['command' => $arguments[0] ?? null]);

            return false;
        }

        // The shell returns as soon as the command is in the background.
        return proc_close($process) === 0;
    }
}
