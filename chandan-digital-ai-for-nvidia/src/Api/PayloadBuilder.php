<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Api;

use ChandanDigital\NvidiaAi\Content\IndianEnglishPolicy;

/**
 * Builds and validates NVIDIA chat completions request bodies for the dashboard (Playground and
 * diagnostics).
 *
 * Generation settings come from the model's saved dashboard settings. A setting left empty is left
 * out of the request so NVIDIA's default applies. Settings are never changed silently: a value that
 * a model does not accept produces a clear error instead.
 *
 * @since 1.1.0
 */
final class PayloadBuilder
{
    private const MAX_MESSAGES = 60;
    private const MAX_TEXT_CHARS = 200000;
    private const MAX_TOTAL_CHARS = 1000000;

    /** @var list<string> Notices about adjustments the user should know about. */
    public array $notices = [];

    /**
     * Builds a chat completions payload.
     *
     * @param array<string, mixed> $model Model descriptor from ModelRegistry.
     * @param array<string, mixed> $settings Model settings.
     * @param mixed $messages Conversation from the browser: list of {role, text, images?, reasoning?}.
     * @param bool $stream Whether to request a streamed response.
     * @param string $systemPrompt Optional system prompt.
     * @param bool $editorialPolicy Whether to add the editorial policy to the system prompt.
     * @return array<string, mixed>|ApiError
     */
    public function chat(array $model, array $settings, $messages, bool $stream, string $systemPrompt = '', bool $editorialPolicy = false)
    {
        if ($model['kind'] === 'image') {
            return self::invalid(__('Image generation models cannot be used in the chat Playground.', 'chandan-digital-ai-for-nvidia'));
        }
        if (!is_array($messages) || !$messages) {
            return self::invalid(__('Enter a prompt first.', 'chandan-digital-ai-for-nvidia'));
        }
        if (count($messages) > self::MAX_MESSAGES) {
            /* translators: %d: maximum number of messages. */
            return self::invalid(sprintf(__('The conversation is too long (more than %d messages). Clear the conversation and start again.', 'chandan-digital-ai-for-nvidia'), self::MAX_MESSAGES));
        }

        $apiMessages = [];
        $system = trim($systemPrompt);
        if ($editorialPolicy) {
            $system = ($system !== '' ? $system . "\n\n" : '') . IndianEnglishPolicy::instruction();
        }
        if ($system !== '') {
            $apiMessages[] = ['role' => 'system', 'content' => $system];
        }

        // Count images from the newest message backwards so the most recent ones are kept.
        $imageBudget = (int) $settings['image_max_count'];
        $totalChars = strlen($system);
        $prepared = [];
        $messages = array_values($messages);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (!is_array($message)) {
                return self::invalid(__('The conversation data is not valid.', 'chandan-digital-ai-for-nvidia'));
            }
            $role = (string) ($message['role'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true)) {
                return self::invalid(__('Conversation messages must come from the user or the assistant.', 'chandan-digital-ai-for-nvidia'));
            }
            $text = is_string($message['text'] ?? null) ? (string) $message['text'] : '';
            if (strlen($text) > self::MAX_TEXT_CHARS) {
                return self::invalid(__('One message is too long. Shorten it and try again.', 'chandan-digital-ai-for-nvidia'));
            }
            $totalChars += strlen($text);

            $images = isset($message['images']) && is_array($message['images']) ? $message['images'] : [];
            if ($role !== 'user') {
                $images = [];
            }

            $parts = [];
            $dropped = 0;
            foreach ($images as $image) {
                if (!is_array($image) || !isset($image['type'], $image['value']) || !is_string($image['value'])) {
                    return self::invalid(__('An image in the conversation is not valid.', 'chandan-digital-ai-for-nvidia'));
                }
                if ($model['kind'] !== 'vision' || empty($settings['images'])) {
                    /* translators: %s: model name. */
                    return self::invalid(sprintf(__('%s is not set up for image input. Choose a vision model or enable image input in its settings.', 'chandan-digital-ai-for-nvidia'), $model['name']));
                }
                if ($imageBudget <= 0) {
                    $dropped++;
                    continue;
                }
                $url = $image['type'] === 'url'
                    ? ImageInput::validate_url($image['value'], $settings)
                    : ImageInput::validate_data_uri($image['value'], $settings);
                if ($url instanceof ApiError) {
                    return $url;
                }
                $imageBudget--;
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
            }
            if ($dropped > 0) {
                $this->notices[] = sprintf(
                    /* translators: 1: number of images, 2: image limit. */
                    _n('%1$d older image was left out of this request because the model setting allows %2$d images per request.', '%1$d older images were left out of this request because the model setting allows %2$d images per request.', $dropped, 'chandan-digital-ai-for-nvidia'),
                    $dropped,
                    (int) $settings['image_max_count']
                );
            }

            if ($role === 'user' && trim($text) === '' && !$parts) {
                return self::invalid(__('A message is empty. Enter a prompt or attach an image.', 'chandan-digital-ai-for-nvidia'));
            }

            if ($parts) {
                // Text first, then images, matching NVIDIA's documented multimodal structure.
                $content = trim($text) !== '' ? array_merge([['type' => 'text', 'text' => $text]], $parts) : $parts;
            } else {
                $content = $text;
            }
            $apiMessage = ['role' => $role, 'content' => $content];

            if ($role === 'assistant' && $model['reasoning_passback'] && isset($message['reasoning']) && is_string($message['reasoning']) && $message['reasoning'] !== '') {
                // Kimi K3 expects its earlier reasoning back in multi-turn conversations.
                $apiMessage['reasoning_content'] = $message['reasoning'];
                $totalChars += strlen($message['reasoning']);
            }
            $prepared[] = $apiMessage;
        }

        if ($totalChars > self::MAX_TOTAL_CHARS) {
            return self::invalid(__('The conversation is too long. Clear it and start again.', 'chandan-digital-ai-for-nvidia'));
        }
        $last = $messages[count($messages) - 1];
        if (($last['role'] ?? '') !== 'user') {
            return self::invalid(__('The last message must come from the user.', 'chandan-digital-ai-for-nvidia'));
        }

        $payload = [
            'model' => $model['id'],
            'messages' => array_merge($apiMessages, array_reverse($prepared)),
        ];

        $params = self::generation_params($model, $settings);
        if ($params instanceof ApiError) {
            return $params;
        }
        $payload = array_merge($payload, $params);
        $payload['stream'] = $stream;

        return $payload;
    }

    /**
     * Generation parameters from saved model settings.
     *
     * @param array<string, mixed> $model Model descriptor.
     * @param array<string, mixed> $settings Model settings.
     * @return array<string, mixed>|ApiError
     */
    public static function generation_params(array $model, array $settings)
    {
        $params = [];
        if ($settings['max_tokens'] !== null) {
            $params['max_tokens'] = (int) $settings['max_tokens'];
        }
        if ($settings['temperature'] !== null) {
            $params['temperature'] = (float) $settings['temperature'];
        }
        if ($settings['top_p'] !== null) {
            $params['top_p'] = (float) $settings['top_p'];
        }
        $reasoning = (string) $settings['reasoning_effort'];
        if ($reasoning !== 'default') {
            if (!in_array($reasoning, $model['reasoning_values'], true)) {
                return self::invalid(sprintf(
                    /* translators: 1: value, 2: model name. */
                    __('Reasoning effort "%1$s" is not supported by %2$s. Change it in the model settings.', 'chandan-digital-ai-for-nvidia'),
                    $reasoning,
                    $model['name']
                ), 'unsupported_parameter');
            }
            $params['reasoning_effort'] = $reasoning;
        }
        if ($settings['seed'] !== null) {
            $params['seed'] = (int) $settings['seed'];
        }
        return $params;
    }

    /**
     * @param string $message Message.
     * @param string $code Error code.
     */
    private static function invalid(string $message, string $code = 'invalid_input'): ApiError
    {
        return new ApiError($code, 0, '', null, $message);
    }
}
