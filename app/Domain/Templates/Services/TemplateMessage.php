<?php

declare(strict_types=1);

namespace App\Domain\Templates\Services;

use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Models\Media;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\WhatsApp\Models\PhoneNumber;

/**
 * Prepares a template send: the template must be APPROVED on the sending number's WhatsApp
 * Business Account, and the variables must match its definition. Produces Meta's `components`
 * plus the filled-in text, so the inbox shows what the customer actually received.
 */
final class TemplateMessage
{
    private const MEDIA_HEADERS = ['IMAGE' => 'image', 'VIDEO' => 'video', 'DOCUMENT' => 'document'];

    /**
     * @param  array<string, mixed>  $input  name, language, and either `variables` {header, body, buttons} or raw `components`
     * @return array{template: array<string, mixed>, body: ?string}
     */
    public function prepare(PhoneNumber $number, array $input, ?Media $media): array
    {
        $name = (string) ($input['name'] ?? '');
        $language = (string) ($input['language'] ?? '');
        $raw = is_array($input['components'] ?? null) ? array_values($input['components']) : null;

        $scope = fn () => MessageTemplate::query()->where('waba_account_id', $number->waba_account_id);
        /** @var ?MessageTemplate $template */
        $template = $scope()->where('name', $name)->where('language', $language)->first();

        if ($template === null) {
            // Templates of this account are known here, and this one is not among them.
            if ($scope()->withTrashed()->exists()) {
                throw MessagingException::templateUnavailable("The template \"{$name}\" ({$language}) does not exist on this WhatsApp account. Sync templates and choose an approved one.");
            }

            // Never synced yet (e.g. right after connecting): let Meta validate the send.
            return ['template' => array_filter(['name' => $name, 'language' => $language, 'components' => $raw]), 'body' => null];
        }

        if (! $template->isSendable()) {
            $status = strtolower(str_replace('_', ' ', $template->status));
            throw MessagingException::templateUnavailable("The template \"{$name}\" is {$status}. Only approved templates can be sent.");
        }

        $definition = $template->variables();
        $variables = is_array($input['variables'] ?? null) ? $input['variables'] : [];
        $named = strtoupper($template->parameter_format) === 'NAMED';
        $components = [];
        $headerMedia = null;

        $headerValues = array_values(array_map('strval', (array) ($variables['header'] ?? [])));
        $bodyValues = array_values(array_map('strval', (array) ($variables['body'] ?? [])));
        $buttonValues = (array) ($variables['buttons'] ?? []);

        if ($raw !== null && $variables === []) {
            $components = $raw; // API clients that build components themselves
            $headerValues = $this->textParameters($raw, 'header');
            $bodyValues = $this->textParameters($raw, 'body');
        } else {
            $format = $definition['header_format'];
            if ($format !== null && isset(self::MEDIA_HEADERS[$format])) {
                $headerMedia = self::MEDIA_HEADERS[$format];
                if ($media === null || Media::whatsappTypeFor((string) $media->mime_type) !== $headerMedia) {
                    throw MessagingException::templateUnavailable("This template has {$this->article($headerMedia)} {$headerMedia} header. Attach {$this->article($headerMedia)} {$headerMedia} to send it.");
                }
            } elseif ($format === 'LOCATION') {
                throw MessagingException::templateUnavailable('Templates with a location header cannot be sent from the inbox yet.');
            } elseif ($definition['header'] !== []) {
                $this->assertCount('header', $definition['header'], $headerValues);
                $components[] = ['type' => 'header', 'parameters' => $this->parameters($definition['header'], $headerValues, $named)];
            }

            if ($definition['body'] !== []) {
                $this->assertCount('body', $definition['body'], $bodyValues);
                $components[] = ['type' => 'body', 'parameters' => $this->parameters($definition['body'], $bodyValues, $named)];
            }

            foreach ($definition['buttons'] as $button) {
                if (! $button['variable']) {
                    continue;
                }
                $value = trim((string) ($buttonValues[$button['index']] ?? $buttonValues[(string) $button['index']] ?? ''));
                if ($value === '' && $button['type'] !== 'URL') {
                    $value = $bodyValues[0] ?? ''; // one-time passcode buttons repeat the code
                }
                if ($value === '') {
                    throw MessagingException::templateUnavailable("Fill in the value for the \"{$button['text']}\" button.");
                }
                $components[] = [
                    'type' => 'button',
                    'sub_type' => $button['type'] === 'COPY_CODE' ? 'copy_code' : 'url',
                    'index' => (string) $button['index'],
                    'parameters' => [$button['type'] === 'COPY_CODE' ? ['type' => 'coupon_code', 'coupon_code' => $value] : ['type' => 'text', 'text' => $value]],
                ];
            }
        }

        $header = $template->component('HEADER');
        $rendered = array_filter([
            'header' => $template->headerFormat() === 'TEXT' ? $this->render((string) ($header['text'] ?? ''), $definition['header'], $headerValues) : null,
            'body' => $this->render((string) ($template->component('BODY')['text'] ?? ''), $definition['body'], $bodyValues),
            'footer' => $template->component('FOOTER')['text'] ?? null,
            'buttons' => array_column($definition['buttons'], 'text') ?: null,
        ]);

        return [
            'template' => array_filter([
                'id' => $template->id,
                'name' => $template->name,
                'language' => $template->language,
                'category' => $template->category,
                'components' => $components ?: null,
                'header_media' => $headerMedia,
                'rendered' => $rendered ?: null,
            ]),
            'body' => $rendered['body'] ?? null,
        ];
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $values
     */
    private function assertCount(string $part, array $names, array $values): void
    {
        $filled = array_filter($values, fn (string $value) => trim($value) !== '');
        if (count($values) !== count($names) || count($filled) !== count($names)) {
            $count = count($names);
            throw MessagingException::templateUnavailable("This template needs {$count} {$part} variable".($count === 1 ? '' : 's').'. Fill in every variable before sending.');
        }
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $values
     * @return list<array<string, string>>
     */
    private function parameters(array $names, array $values, bool $named): array
    {
        $parameters = [];
        foreach ($names as $i => $placeholder) {
            // Meta rejects new lines, tabs and runs of 4+ spaces inside a variable.
            $text = trim((string) preg_replace('/\s{4,}/', '   ', (string) preg_replace('/[\r\n\t]+/', ' ', $values[$i])));
            $parameters[] = ['type' => 'text', 'text' => $text] + ($named ? ['parameter_name' => $placeholder] : []);
        }

        return $parameters;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $values
     */
    private function render(string $text, array $names, array $values): string
    {
        foreach ($names as $i => $placeholder) {
            if (isset($values[$i])) {
                $text = (string) preg_replace('/\{\{\s*'.preg_quote($placeholder, '/').'\s*\}\}/', addcslashes($values[$i], '\\$'), $text);
            }
        }

        return $text;
    }

    /**
     * @param  list<mixed>  $components
     * @return list<string>
     */
    private function textParameters(array $components, string $type): array
    {
        foreach ($components as $component) {
            if (is_array($component) && strtolower((string) ($component['type'] ?? '')) === $type) {
                $texts = [];
                foreach ((array) ($component['parameters'] ?? []) as $parameter) {
                    if (is_array($parameter) && isset($parameter['text'])) {
                        $texts[] = (string) $parameter['text'];
                    }
                }

                return $texts;
            }
        }

        return [];
    }

    private function article(string $noun): string
    {
        return $noun === 'image' ? 'an' : 'a';
    }
}
