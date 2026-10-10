<?php

namespace App\Domain\Email;

use App\Enums\EmailTemplateKey;
use App\Models\EmailTemplate;
use App\Support\Settings;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;

/**
 * Renders admin-editable email templates.
 *
 * Bodies are Markdown with {{variable}} placeholders, button shortcodes
 * {{button:variable|Button text}} and list sections {{section:variable|Heading}}
 * (left out entirely when the list is empty). Values are inserted as data: customer-
 * supplied text is Markdown- and HTML-escaped so it cannot inject links or
 * markup; only *_link variables (URLs we generate) are inserted as URLs, and
 * only if they point at this site.
 */
final class TemplateRenderer
{
    /** @return array{subject:string, html:string, text:string} */
    public function render(EmailTemplateKey|EmailTemplate $template, array $variables): array
    {
        $template = $template instanceof EmailTemplate ? $template : $this->template($template);
        $variables = $this->withGlobals($variables);

        $subject = $this->substitute((string) $template->subject, $variables, plain: true);

        $buttons = [];
        $markdown = preg_replace_callback('/\{\{\s*button:([a-z_]+)\s*\|\s*([^}]+?)\s*\}\}/i', function ($m) use ($variables, &$buttons) {
            $url = $this->safeUrl($variables[$m[1]] ?? null);
            if (! $url) {
                return '';
            }
            $token = 'STBUTTON'.count($buttons).'X';
            $buttons[$token] = ['url' => $url, 'label' => trim($m[2])];

            return "\n\n{$token}\n\n";
        }, (string) $template->body) ?? '';

        // {{section:list_variable|Heading}}: a bold heading and a bulleted list, or nothing at all when the list is empty.
        $markdown = preg_replace_callback('/\{\{\s*section:([a-z_]+)\s*\|\s*([^}]+?)\s*\}\}/i', function ($m) use ($variables) {
            $items = array_values(array_filter(array_map('strval', (array) ($variables[strtolower($m[1])] ?? []))));

            return $items === []
                ? ''
                : "\n\n**".$this->escapeMarkdown(trim($m[2])).'**'."\n\n".implode("\n", array_map(fn (string $item) => '- '.$this->escapeMarkdown($item), $items))."\n\n";
        }, $markdown) ?? $markdown;

        $markdown = $this->substitute($markdown, $variables, plain: false);

        $converter = new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = (string) $converter->convert($markdown);

        foreach ($buttons as $token => $button) {
            $html = str_replace(
                ["<p>{$token}</p>", $token],
                view('emails.partials.button', $button)->render(),
                $html,
            );
        }

        $text = $this->toText($markdown, $buttons);

        return [
            'subject' => Str::limit(trim(preg_replace('/\s+/', ' ', $subject) ?? ''), 200, ''),
            'html' => view('emails.layout', ['content' => $html, 'subject' => $subject])->render(),
            'text' => $text,
        ];
    }

    public function template(EmailTemplateKey $key): EmailTemplate
    {
        $template = EmailTemplate::query()->where('key', $key->value)->first();

        return $template ?? new EmailTemplate([
            'key' => $key->value,
            'name' => $key->getLabel(),
            'subject' => DefaultEmailTemplates::subject($key),
            'body' => DefaultEmailTemplates::body($key),
            'is_active' => true,
        ]);
    }

    private function withGlobals(array $variables): array
    {
        return $variables + [
            'site_name' => Settings::siteName(),
            'support_email' => Settings::supportEmail(),
            'site_url' => url('/'),
        ];
    }

    private function substitute(string $text, array $variables, bool $plain): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($variables, $plain) {
            $name = strtolower($m[1]);
            $value = $variables[$name] ?? '';

            if (is_array($value)) {
                // Lists (e.g. follow-up questions) become numbered Markdown lists.
                $items = array_values(array_filter(array_map('strval', $value)));
                if ($plain) {
                    return implode('; ', $items);
                }

                return "\n\n".implode("\n", array_map(fn ($item, $i) => ($i + 1).'. '.$this->escapeMarkdown($item), $items, array_keys($items)))."\n\n";
            }

            $value = (string) $value;

            if (str_ends_with($name, '_link') || $name === 'site_url') {
                $url = $this->safeUrl($value);

                return $url ? ($plain ? $url : '<'.$url.'>') : '';
            }

            return $plain ? $value : $this->escapeMarkdown($value);
        }, $text) ?? $text;
    }

    private function escapeMarkdown(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~])/', '\\\\$1', $value) ?? $value;
    }

    /** Only absolute URLs on this application's host are allowed in emails. */
    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = parse_url($value, PHP_URL_HOST);
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array($scheme, ['https', 'http'], true) && $host && $host === $appHost ? $value : null;
    }

    private function toText(string $markdown, array $buttons): string
    {
        foreach ($buttons as $token => $button) {
            $markdown = str_replace($token, $button['label'].': '.$button['url'], $markdown);
        }

        $text = preg_replace('/<(https?:[^>]+)>/', '$1', $markdown) ?? $markdown;
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $text) ?? $text;
        $text = preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!|<>~])/', '$1', $text) ?? $text;
        $text = preg_replace('/^#+\s*/m', '', $text) ?? $text;
        $text = str_replace(['**', '__'], '', $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text)."\n\n— ".Settings::siteName()."\n".Settings::supportEmail()."\n";
    }
}
