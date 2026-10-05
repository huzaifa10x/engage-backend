<?php

declare(strict_types=1);

namespace App\Domain\Templates\Services;

use App\Domain\Templates\Models\MessageTemplate;
use Illuminate\Validation\ValidationException;

/**
 * Turns the template form (header / body / footer / buttons) into Meta's `components` array,
 * enforcing the rules Meta would otherwise reject at review time.
 */
final class TemplateComponents
{
    public const MEDIA_FORMATS = ['IMAGE', 'VIDEO', 'DOCUMENT'];

    /** What Meta accepts as the sample of a media header, and the size limit in MB. */
    public const MEDIA_RULES = [
        'IMAGE' => ['mimes' => ['image/jpeg', 'image/png'], 'max_mb' => 5, 'label' => 'a JPG or PNG image up to 5 MB'],
        'VIDEO' => ['mimes' => ['video/mp4'], 'max_mb' => 16, 'label' => 'an MP4 video up to 16 MB'],
        'DOCUMENT' => ['mimes' => ['application/pdf'], 'max_mb' => 100, 'label' => 'a PDF document up to 100 MB'],
    ];

    /**
     * @param  array<string, mixed>  $data  header {format, text, example, handle}, body, body_examples[], footer, buttons[]
     * @return list<array<string, mixed>>
     */
    public function build(array $data): array
    {
        $components = [];

        $format = strtoupper((string) ($data['header']['format'] ?? 'TEXT'));
        $headerText = trim((string) ($data['header']['text'] ?? ''));

        if (in_array($format, self::MEDIA_FORMATS, true)) {
            // The sample file was uploaded to Meta beforehand; only its handle goes in the template.
            $handle = trim((string) ($data['header']['handle'] ?? ''));
            if ($handle === '') {
                throw ValidationException::withMessages(['header.media_id' => 'Attach a sample '.strtolower($format).' for the header.']);
            }
            $components[] = ['type' => 'HEADER', 'format' => $format, 'example' => ['header_handle' => [$handle]]];
        } elseif ($headerText !== '') {
            $vars = MessageTemplate::placeholders($headerText);
            if (count($vars) > 1 || ($vars !== [] && $vars !== ['1'])) {
                throw ValidationException::withMessages(['header.text' => 'The header can contain one variable only, written as {{1}}.']);
            }
            $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $headerText];
            if ($vars !== []) {
                $example = trim((string) ($data['header']['example'] ?? ''));
                if ($example === '') {
                    throw ValidationException::withMessages(['header.example' => 'Add an example value for the header variable.']);
                }
                $header['example'] = ['header_text' => [$example]];
            }
            $components[] = $header;
        }

        $body = trim((string) ($data['body'] ?? ''));
        $vars = MessageTemplate::placeholders($body);
        $expected = array_map('strval', range(1, max(1, count($vars))));
        if ($vars !== [] && $vars !== array_slice($expected, 0, count($vars))) {
            throw ValidationException::withMessages(['body' => 'Number the variables in order: {{1}}, {{2}}, {{3}} … without gaps.']);
        }
        $component = ['type' => 'BODY', 'text' => $body];
        if ($vars !== []) {
            $examples = array_values(array_filter(array_map(fn ($e) => trim((string) $e), (array) ($data['body_examples'] ?? [])), fn (string $e) => $e !== ''));
            if (count($examples) < count($vars)) {
                throw ValidationException::withMessages(['body_examples' => 'Add an example value for every variable so Meta can review the template.']);
            }
            $component['example'] = ['body_text' => [array_slice($examples, 0, count($vars))]];
        }
        $components[] = $component;

        $footer = trim((string) ($data['footer'] ?? ''));
        if ($footer !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => $footer];
        }

        $buttons = [];
        foreach (array_values((array) ($data['buttons'] ?? [])) as $i => $button) {
            $text = trim((string) ($button['text'] ?? ''));
            $buttons[] = match (strtoupper((string) ($button['type'] ?? ''))) {
                'QUICK_REPLY' => ['type' => 'QUICK_REPLY', 'text' => $text],
                'PHONE_NUMBER' => ['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => '+'.ltrim(preg_replace('/[^\d+]/', '', (string) ($button['phone_number'] ?? '')) ?? '', '+')],
                'URL' => $this->urlButton($text, (string) ($button['url'] ?? ''), (string) ($button['example'] ?? ''), $i),
                default => throw ValidationException::withMessages(["buttons.{$i}.type" => 'Unsupported button type.']),
            };
        }
        if ($buttons !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    /** @return array<string, mixed> */
    private function urlButton(string $text, string $url, string $example, int $index): array
    {
        $button = ['type' => 'URL', 'text' => $text, 'url' => trim($url)];

        $vars = MessageTemplate::placeholders($url);
        if ($vars !== []) {
            if ($vars !== ['1'] || ! str_ends_with(trim($url), '{{1}}')) {
                throw ValidationException::withMessages(["buttons.{$index}.url" => 'A link can have one variable, {{1}}, at the very end of the URL.']);
            }
            if (trim($example) === '') {
                throw ValidationException::withMessages(["buttons.{$index}.example" => 'Add a full example URL for the link variable.']);
            }
            $button['example'] = [trim($example)];
        }

        return $button;
    }
}
