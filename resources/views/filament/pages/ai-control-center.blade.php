@php
    $num = fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals);
    $usd = fn ($value, int $decimals = 2) => $value === null ? '—' : '$'.number_format((float) $value, $decimals);
    $grid = 'display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));';
    $tile = 'border: 1px solid var(--gray-200); border-radius: .75rem; padding: .875rem 1rem; background: var(--gray-50);';
    $tileLabel = 'font-size: .75rem; color: var(--gray-500); text-transform: uppercase; letter-spacing: .04em;';
    $tileValue = 'font-size: 1.5rem; font-weight: 600; margin-top: .25rem; line-height: 1.2;';
    $table = 'width: 100%; border-collapse: collapse; font-size: .875rem;';
    $th = 'text-align: left; font-weight: 500; color: var(--gray-500); padding: .5rem .75rem; border-bottom: 1px solid var(--gray-200); white-space: nowrap;';
    $td = 'padding: .5rem .75rem; border-bottom: 1px solid var(--gray-100); vertical-align: top;';
    $muted = 'color: var(--gray-500);';
    $mono = 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem;';
    $usage = $metrics['usage'];
@endphp

<x-filament-panels::page>
    <div style="display: grid; gap: 1.5rem;">

        {{-- Provider & budget --}}
        <x-filament::section heading="Provider" description="Infrastructure settings come from the server environment; the API key is never shown.">
            <div style="{{ $grid }}">
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Provider</div>
                    <div style="{{ $tileValue }}">{{ $provider['name'] === 'openai' ? 'OpenAI' : ucfirst($provider['name'] ?: 'Not set') }}</div>
                    @if ($provider['name'] === 'fake')
                        <x-filament::badge color="warning" style="margin-top: .5rem;">Fake provider — demo output only</x-filament::badge>
                    @endif
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">API key</div>
                    <div style="margin-top: .5rem;">
                        @if ($provider['key_configured'])
                            <x-filament::badge color="success" icon="heroicon-m-check-circle">Configured</x-filament::badge>
                        @else
                            <x-filament::badge color="{{ $provider['name'] === 'openai' ? 'danger' : 'gray' }}" icon="heroicon-m-x-circle">Not configured</x-filament::badge>
                        @endif
                    </div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Default models</div>
                    <div style="margin-top: .375rem; {{ $mono }}">{{ $provider['default_model'] ?: '—' }}</div>
                    <div style="{{ $mono }}">{{ $provider['writing_model'] ?: '—' }} <span style="{{ $muted }}">(writing)</span></div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Spend today</div>
                    <div style="{{ $tileValue }}">{{ $usd($metrics['today']['cost']) }}</div>
                    <div style="{{ $muted }} font-size: .8125rem;">of {{ $usd($provider['daily_budget'], 0) }} daily budget</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Last 30 days --}}
        <x-filament::section heading="Last {{ \App\Filament\Pages\AiControlCenter::WINDOW_DAYS }} days" description="Figures are cached for 5 minutes ({{ \Illuminate\Support\Carbon::parse($metrics['generated_at'])->diffForHumans() }}).">
            <div style="{{ $grid }}">
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">AI jobs</div>
                    <div style="{{ $tileValue }}">{{ $num($metrics['jobs']) }}</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Failure rate</div>
                    <div style="{{ $tileValue }}">{{ $metrics['failure_rate'] === null ? '—' : $metrics['failure_rate'].'%' }}</div>
                    <div style="{{ $muted }} font-size: .8125rem;">failed ÷ finished jobs</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Average generation time</div>
                    <div style="{{ $tileValue }}">{{ $metrics['avg_minutes'] === null ? '—' : $metrics['avg_minutes'].' min' }}</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Average cost per order</div>
                    <div style="{{ $tileValue }}">{{ $usd($metrics['cost']['average'], 3) }}</div>
                    <div style="{{ $muted }} font-size: .8125rem;">{{ $usd($metrics['cost']['total']) }} across {{ $num($metrics['cost']['orders']) }} orders</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Average quality score</div>
                    <div style="{{ $tileValue }}">{{ $metrics['quality'] === null ? '—' : $num($metrics['quality'], 2).' / 10' }}</div>
                    <div style="{{ $muted }} font-size: .8125rem;">final review round</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Tokens</div>
                    <div style="{{ $tileValue }}">{{ $num($usage['input'] + $usage['output']) }}</div>
                    <div style="{{ $muted }} font-size: .8125rem;">{{ $num($usage['input']) }} in ({{ $num($usage['cached']) }} cached) · {{ $num($usage['output']) }} out ({{ $num($usage['reasoning']) }} reasoning)</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Model calls · web searches</div>
                    <div style="{{ $tileValue }}">{{ $num($usage['calls']) }} · {{ $num($usage['search_calls']) }}</div>
                </div>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1rem; align-items: center;">
                <span style="{{ $muted }} font-size: .875rem;">Jobs by status:</span>
                @foreach ($statuses as $status)
                    <x-filament::badge :color="$status->getColor()">{{ $status->getLabel() }}: {{ $metrics['by_status'][$status->value] ?? 0 }}</x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Default workflow --}}
        <x-filament::section heading="Default workflow" :description="$workflow ? $workflow->name.' · version '.$workflow->version : 'No active workflow — the built-in defaults are shown.'">
            @if ($workflowUrl)
                <x-slot name="afterHeader">
                    <x-filament::link :href="$workflowUrl" icon="heroicon-m-pencil-square">Edit workflow</x-filament::link>
                </x-slot>
            @endif

            <div style="overflow-x: auto;">
                <table style="{{ $table }}">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Stage</th>
                            <th style="{{ $th }}">Status</th>
                            <th style="{{ $th }}">Model</th>
                            <th style="{{ $th }}">Prompt</th>
                            <th style="{{ $th }}">Reasoning</th>
                            <th style="{{ $th }}">Max output</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stages as $stage)
                            <tr>
                                <td style="{{ $td }}">{{ $stage['label'] }}</td>
                                <td style="{{ $td }}">
                                    @if (! $stage['enabled'])
                                        <x-filament::badge color="gray">Skipped</x-filament::badge>
                                    @elseif ($stage['required'])
                                        <x-filament::badge color="info">Required</x-filament::badge>
                                    @else
                                        <x-filament::badge color="success">Enabled</x-filament::badge>
                                    @endif
                                </td>
                                @if ($stage['uses_model'])
                                    <td style="{{ $td }} {{ $mono }}">{{ $stage['model'] ?? '—' }}</td>
                                    <td style="{{ $td }}">
                                        <span style="{{ $mono }}">{{ $stage['prompt_key'] }}</span>
                                        @if ($stage['prompt'])
                                            @php($url = $promptUrl($stage['prompt']))
                                            @if ($url)
                                                <a href="{{ $url }}" style="text-decoration: underline;">v{{ $stage['prompt']->version }}</a>
                                            @else
                                                v{{ $stage['prompt']->version }}
                                            @endif
                                        @else
                                            <x-filament::badge color="warning">Built-in default</x-filament::badge>
                                        @endif
                                    </td>
                                    <td style="{{ $td }}">{{ ucfirst((string) ($stage['reasoning'] ?? '—')) }}</td>
                                    <td style="{{ $td }}">{{ $num($stage['max_output_tokens']) }}</td>
                                @else
                                    <td style="{{ $td }} {{ $muted }}" colspan="4">No language model</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="{{ $grid }} margin-top: 1.25rem;">
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Quality gate</div>
                    <div style="margin-top: .375rem;">Pass at <strong>{{ data_get($config, 'quality.threshold') }}</strong> / 10, every category ≥ <strong>{{ data_get($config, 'quality.min_category_score') }}</strong></div>
                    <div style="{{ $muted }}">Up to {{ data_get($config, 'quality.max_refinement_rounds') }} refinement round(s)</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Cost & call limits per order</div>
                    <div style="margin-top: .375rem;">{{ $usd(data_get($config, 'limits.max_cost_usd')) }} · {{ data_get($config, 'limits.max_llm_calls') }} model calls · {{ data_get($config, 'limits.max_search_calls') }} searches</div>
                    <div style="{{ $muted }}">{{ data_get($config, 'limits.max_duration_minutes') }} min max · then {{ \App\Filament\Support\Catalogue\AiOptions::BUDGET_EXCEEDED[data_get($config, 'on_budget_exceeded')] ?? data_get($config, 'on_budget_exceeded') }}</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Retries</div>
                    <div style="margin-top: .375rem;">{{ data_get($config, 'limits.max_stage_attempts') }} attempts per stage</div>
                    <div style="{{ $muted }}">{{ data_get($config, 'limits.max_length_revisions') }} length-fix passes</div>
                </div>
                <div style="{{ $tile }}">
                    <div style="{{ $tileLabel }}">Research</div>
                    <div style="margin-top: .375rem;">{{ data_get($config, 'research.max_search_calls') }} searches · {{ data_get($config, 'research.search_context_size') }} context</div>
                    <div style="{{ $muted }}">{{ data_get($config, 'research.official_first') ? 'Official sources first' : 'Any source order' }}{{ data_get($config, 'research.allow_secondary_sources') ? '' : ' · official only' }}</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Active prompts --}}
        <x-filament::section heading="Active prompt versions" collapsible>
            @if ($activePrompts->isEmpty())
                <p style="{{ $muted }}">No prompt has an active version yet.</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="{{ $table }}">
                        <thead>
                            <tr>
                                <th style="{{ $th }}">Prompt key</th>
                                <th style="{{ $th }}">Version</th>
                                <th style="{{ $th }}">Label</th>
                                <th style="{{ $th }}">Activated</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($activePrompts as $version)
                                @php($url = $promptUrl($version))
                                <tr>
                                    <td style="{{ $td }} {{ $mono }}">{{ $version->prompt_key }}</td>
                                    <td style="{{ $td }}">@if ($url)<a href="{{ $url }}" style="text-decoration: underline;">v{{ $version->version }}</a>@else v{{ $version->version }} @endif</td>
                                    <td style="{{ $td }}">{{ $version->label ?: '—' }}</td>
                                    <td style="{{ $td }} {{ $muted }}">{{ $version->activated_at?->format('j M Y H:i') ?? '—' }}{{ $version->activatedBy ? ' by '.$version->activatedBy->name : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- Ratings --}}
        <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));">
            <x-filament::section heading="Customer ratings by prompt version" description="Average rating (1–5) of delivered documents in the last {{ \App\Filament\Pages\AiControlCenter::WINDOW_DAYS }} days.">
                @if ($metrics['ratings_by_prompt'] === [])
                    <p style="{{ $muted }}">No ratings yet.</p>
                @else
                    <table style="{{ $table }}">
                        <thead><tr><th style="{{ $th }}">Prompt</th><th style="{{ $th }}">Rating</th><th style="{{ $th }}">Ratings</th></tr></thead>
                        <tbody>
                            @foreach ($metrics['ratings_by_prompt'] as $row)
                                <tr>
                                    <td style="{{ $td }}"><span style="{{ $mono }}">{{ $row['prompt_key'] }}</span> v{{ $row['version'] ?? '?' }}@if ($row['status'] === 'active') <x-filament::badge color="success" size="sm">active</x-filament::badge>@endif
                                        @if ($row['label'])<div style="{{ $muted }} font-size: .8125rem;">{{ $row['label'] }}</div>@endif
                                    </td>
                                    <td style="{{ $td }}"><strong>{{ $num($row['average'], 2) }}</strong></td>
                                    <td style="{{ $td }}">{{ $row['count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>

            <x-filament::section heading="Customer ratings by workflow">
                @if ($metrics['ratings_by_workflow'] === [])
                    <p style="{{ $muted }}">No ratings yet.</p>
                @else
                    <table style="{{ $table }}">
                        <thead><tr><th style="{{ $th }}">Workflow</th><th style="{{ $th }}">Rating</th><th style="{{ $th }}">Ratings</th></tr></thead>
                        <tbody>
                            @foreach ($metrics['ratings_by_workflow'] as $row)
                                <tr>
                                    <td style="{{ $td }}">{{ $row['workflow'] }}@if ($row['version']) <span style="{{ $muted }}">v{{ $row['version'] }}</span>@endif</td>
                                    <td style="{{ $td }}"><strong>{{ $num($row['average'], 2) }}</strong></td>
                                    <td style="{{ $td }}">{{ $row['count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        </div>

        {{-- Failures --}}
        <x-filament::section heading="Recent failures" description="Latest AI jobs that failed or reported an error. Open the order to retry, skip a stage or take over.">
            @if ($failures->isEmpty())
                <p style="{{ $muted }}">No recent failures.</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="{{ $table }}">
                        <thead>
                            <tr>
                                <th style="{{ $th }}">Order</th>
                                <th style="{{ $th }}">Status</th>
                                <th style="{{ $th }}">Stage</th>
                                <th style="{{ $th }}">Error</th>
                                <th style="{{ $th }}">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($failures as $job)
                                @php($url = $orderUrl($job))
                                <tr>
                                    <td style="{{ $td }} {{ $mono }}">
                                        @if ($url)
                                            <a href="{{ $url }}" style="text-decoration: underline;">{{ $job->order->reference }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td style="{{ $td }}"><x-filament::badge :color="$job->status?->getColor() ?? 'gray'">{{ $job->status?->getLabel() ?? '—' }}</x-filament::badge></td>
                                    <td style="{{ $td }}">{{ $job->current_stage?->getLabel() ?? '—' }}</td>
                                    <td style="{{ $td }}">
                                        <span style="{{ $mono }}">{{ $job->last_error_code ?? '—' }}</span>
                                        @if ($job->last_error_message)
                                            <div style="{{ $muted }} font-size: .8125rem;">{{ $limit($job->last_error_message, 180) }}</div>
                                        @endif
                                        @if ($job->failure_count > 1)
                                            <div style="{{ $muted }} font-size: .75rem;">{{ $job->failure_count }} failures</div>
                                        @endif
                                    </td>
                                    <td style="{{ $td }} {{ $muted }} white-space: nowrap;">{{ $job->updated_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
