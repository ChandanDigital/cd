<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Models;

use ChandanDigital\NvidiaAi\Api\ApiError;
use ChandanDigital\NvidiaAi\Api\OutputGuard;
use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use ChandanDigital\NvidiaAi\Provider\NvidiaProvider;
use ChandanDigital\NvidiaAi\Content\IndianEnglishPolicy;
use ChandanDigital\NvidiaAi\Content\JsonOutput;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception text, not browser output.

/**
 * Class for a NVIDIA NIM text generation model using the OpenAI-compatible Chat Completions API.
 *
 * Since 1.1.0, models with dashboard settings applied to AI Client requests (Kimi K3 by default) get
 * those settings as defaults whenever the caller leaves them unset, and Kimi K3 receives its earlier
 * reasoning back in multi-turn conversations, as Moonshot AI and NVIDIA require.
 *
 * @since 1.0.0
 *
 * @phpstan-type ToolCallData array{
 *     id?: string,
 *     type?: string,
 *     function?: array{name?: string, arguments?: string}
 * }
 * @phpstan-type MessageData array{
 *     role?: string,
 *     content?: ?string,
 *     reasoning_content?: ?string,
 *     tool_calls?: list<ToolCallData>
 * }
 * @phpstan-type ChoiceData array{
 *     index?: int,
 *     message?: MessageData,
 *     finish_reason?: ?string
 * }
 * @phpstan-type UsageData array{
 *     prompt_tokens?: int,
 *     completion_tokens?: int,
 *     total_tokens?: int
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     choices?: list<ChoiceData>,
 *     usage?: UsageData
 * }
 */
class NvidiaTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        $params = $this->prepareGenerateTextParams($prompt);

        $request = new Request(
            HttpMethodEnum::POST(),
            NvidiaProvider::url('chat/completions'),
            ['Content-Type' => 'application/json'],
            $params,
            $this->resolveRequestOptions()
        );

        // Add authentication credentials to the request.
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        // Send and process the request. A garbled reply is sent again once, and never returned.
        $start = microtime(true);
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $httpTransporter->send($request);
            } catch (\Throwable $e) {
                $this->recordOutcome(0, '', $start, $e->getMessage());
                throw $e;
            }
            if (!$response->isSuccessful() || !$this->isGarbledResponse($response)) {
                break;
            }
            if ($attempt >= 1) {
                $this->recordOutcome($response->getStatusCode(), '', $start, '', new ApiError('garbled_output', $response->getStatusCode()));
                throw new RuntimeException(ApiError::message_for('garbled_output'));
            }
        }
        $this->recordOutcome($response->getStatusCode(), (string) $response->getBody(), $start);
        ResponseUtil::throwIfNotSuccessful($response);
        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Whether a successful response carries corrupted text (see OutputGuard).
     *
     * @since 1.1.2
     *
     * @param Response $response The response from the API endpoint.
     */
    protected function isGarbledResponse(Response $response): bool
    {
        $data = $response->getData();
        if (!is_array($data) || !isset($data['choices']) || !is_array($data['choices'])) {
            return false;
        }
        foreach ($data['choices'] as $choice) {
            $message = is_array($choice) && isset($choice['message']) && is_array($choice['message']) ? $choice['message'] : [];
            foreach (['content', 'reasoning_content', 'reasoning'] as $field) {
                if (isset($message[$field]) && is_string($message[$field]) && OutputGuard::is_garbled($message[$field])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Dashboard settings to apply to AI Client requests for this model, or null when the model's
     * "apply to AI Client requests" setting is off (the default for the original built-in models).
     *
     * @since 1.1.0
     *
     * @return array<string, mixed>|null
     */
    protected function dashboardSettings(): ?array
    {
        $modelId = $this->metadata()->getId();
        if (ModelRegistry::get($modelId) === null) {
            return null;
        }
        $settings = ModelRegistry::settings($modelId);
        return !empty($settings['ai_client_defaults']) ? $settings : null;
    }

    /**
     * Resolves the request options. For models with dashboard settings applied, the request timeout
     * is raised to at least the dashboard timeout, because reasoning models can think for minutes.
     *
     * @since 1.1.0
     *
     * @return RequestOptions|null The request options to use.
     */
    protected function resolveRequestOptions(): ?RequestOptions
    {
        $existing = $this->getRequestOptions();
        if ($this->dashboardSettings() === null) {
            return $existing;
        }
        // Clone so the configured options object is not mutated.
        $options = $existing !== null ? RequestOptions::fromArray($existing->toArray()) : new RequestOptions();
        $minimum = (float) Settings::get('timeout');
        $timeout = $options->getTimeout();
        if ($timeout === null || $timeout < $minimum) {
            $options->setTimeout($minimum);
        }
        return $options;
    }

    /**
     * Records the outcome of a request: updates the model's access status (for models that need an
     * access check) and writes a local log entry when logging is enabled. Prompts are not passed here.
     *
     * @since 1.1.0
     *
     * @param int $status HTTP status, or 0 when the request failed before a response arrived.
     * @param string $body Response body (only read for error details).
     * @param float $start Request start time.
     * @param string $transportError Transport error message, if any.
     * @param ApiError|null $error Error already classified by the caller.
     */
    protected function recordOutcome(int $status, string $body, float $start, string $transportError = '', ?ApiError $error = null): void
    {
        try {
            $modelId = $this->metadata()->getId();
            if ($error === null && $status === 0) {
                $error = ApiError::is_connector_block($transportError)
                    ? new ApiError('connector_not_approved', 403, Logger::redact($transportError, 300))
                    : new ApiError('network_error', 0, Logger::redact($transportError, 300));
            } elseif ($error === null && $status >= 400) {
                $error = ApiError::from_http($status, substr($body, 0, 65536));
            }

            $model = ModelRegistry::get($modelId);
            if ($model !== null && $model['requires_verification']) {
                if ($error === null && ($model['status']['state'] ?? '') !== 'verified') {
                    ModelRegistry::set_status($modelId, 'verified', $status);
                } elseif ($error !== null && in_array($error->code, ['model_not_available', 'invalid_model', 'access_denied'], true)) {
                    ModelRegistry::set_status($modelId, $error->code === 'access_denied' ? 'access_denied' : 'not_available', $status, $error->summary());
                }
            }

            Logger::log([
                'type' => 'ai_client',
                'model' => $modelId,
                'status' => $status,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'error_code' => $error !== null ? $error->code : '',
                'error' => $error !== null ? $error->summary() : '',
            ]);
        } catch (\Throwable $e) {
            // Diagnostics must never break an AI request.
            return;
        }
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt to generate text for. Either a single message or a list of messages
     *                              from a chat.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $messages = [];

        $systemInstruction = (string) $config->getSystemInstruction();
        // Keep caller context and output constraints, then apply the editorial policy (when enabled).
        $systemContent = $systemInstruction;
        if (Settings::get('editorial_policy')) {
            $systemContent = ($systemInstruction !== '' ? $systemInstruction . "\n\n" : '')
                . IndianEnglishPolicy::instruction();
        }
        if ($systemContent !== '' || $this->expectsJsonOutput()) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemContent,
            ];
        }

        $messages = array_merge($messages, $this->prepareMessagesParam($prompt));

        $params = [
            'model' => $this->metadata()->getId(),
            'messages' => $messages,
            'stream' => false,
        ];

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $params['max_tokens'] = $maxTokens;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        // Note: NVIDIA NIM does not support the top_k parameter on the Chat Completions endpoint.

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $params['stop'] = $stopSequences;
        }

        $presencePenalty = $config->getPresencePenalty();
        if ($presencePenalty !== null) {
            $params['presence_penalty'] = $presencePenalty;
        }

        $frequencyPenalty = $config->getFrequencyPenalty();
        if ($frequencyPenalty !== null) {
            $params['frequency_penalty'] = $frequencyPenalty;
        }

        $outputMimeType = $config->getOutputMimeType();
        $outputSchema = $config->getOutputSchema();
        if ($outputMimeType === 'application/json' || $outputSchema !== null) {
            if ($outputSchema) {
                $params['response_format'] = [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'response_schema',
                        'schema' => $outputSchema,
                        'strict' => true,
                    ],
                ];
            } else {
                $params['response_format'] = ['type' => 'json_object'];
            }
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations)) {
            $params['tools'] = $this->prepareToolsParam($functionDeclarations);
        }

        /*
         * Any custom options are added to the parameters as well.
         * This allows developers to pass other options that may be more niche or not yet supported by the SDK.
         */
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $params[$key] = $value;
        }

        $this->applyDashboardDefaults($params);

        if ($this->expectsJsonOutput()) {
            $params['messages'][0]['content'] .= ($params['messages'][0]['content'] !== '' ? "\n\n" : '')
                . "JSON OUTPUT CONTRACT: Return exactly one valid JSON value "
                . 'matching the requested schema. No Markdown fences, introduction, comments or trailing commas. '
                . 'Escape quotes, backslashes and line breaks inside JSON strings. '
                . 'Required keys, types, enum values and output structure take priority over editorial style. '
                . 'Apply writing rules only to free-form reader-facing prose. Keep the JSON complete within '
                . 'the output budget. Do not include reasoning in the JSON response.';
        }
        return $params;
    }

    /**
     * Fills in generation settings from the dashboard that the caller left unset, and validates the
     * reasoning effort for models with a known list of accepted values.
     *
     * Values the caller set are kept, except temperature when the administrator explicitly chose the
     * "always use the dashboard temperature" compatibility option for this model.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $params Request parameters (modified in place).
     * @throws InvalidArgumentException If the reasoning effort is not accepted by the model.
     */
    protected function applyDashboardDefaults(array &$params): void
    {
        $model = ModelRegistry::get($this->metadata()->getId());
        $dashboard = $this->dashboardSettings();

        if ($dashboard !== null) {
            if (!isset($params['max_tokens']) && $dashboard['max_tokens'] !== null) {
                $params['max_tokens'] = (int) $dashboard['max_tokens'];
            }
            if ($dashboard['temperature'] !== null) {
                if (!isset($params['temperature']) || $dashboard['temperature_policy'] === 'dashboard') {
                    $params['temperature'] = (float) $dashboard['temperature'];
                }
            }
            if (!isset($params['top_p']) && $dashboard['top_p'] !== null) {
                $params['top_p'] = (float) $dashboard['top_p'];
            }
            if (!array_key_exists('reasoning_effort', $params) && $dashboard['reasoning_effort'] !== 'default') {
                $params['reasoning_effort'] = (string) $dashboard['reasoning_effort'];
            }
            if (!array_key_exists('seed', $params) && $dashboard['seed'] !== null) {
                $params['seed'] = (int) $dashboard['seed'];
            }
        }

        if ($model !== null && $model['reasoning_values'] && isset($params['reasoning_effort'])
            && !in_array($params['reasoning_effort'], $model['reasoning_values'], true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Reasoning effort "%s" is not supported by %s. Supported values: %s.',
                    is_scalar($params['reasoning_effort']) ? (string) $params['reasoning_effort'] : gettype($params['reasoning_effort']),
                    $model['name'],
                    implode(', ', $model['reasoning_values'])
                )
            );
        }
    }

    /**
     * Prepares the messages parameter for the API request.
     *
     * A single SDK message may translate into multiple Chat Completions messages, because function
     * calls and function responses must be sent as their own messages (with roles `assistant` and
     * `tool` respectively).
     *
     * For models that need their earlier reasoning back (Kimi K3), each assistant message is sent as
     * one message carrying its content, reasoning_content and tool_calls together.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to prepare.
     * @return list<array<string, mixed>> The prepared messages parameter.
     */
    protected function prepareMessagesParam(array $messages): array
    {
        $apiMessages = [];
        $model = ModelRegistry::get($this->metadata()->getId());
        $passback = $model !== null && $model['reasoning_passback'];

        foreach ($messages as $message) {
            $role = $message->getRole();
            $roleString = $this->getMessageRoleString($role);

            $contentParts = [];
            $toolCalls = [];
            $thoughts = [];

            foreach ($message->getParts() as $part) {
                $type = $part->getType();

                if ($passback && $roleString === 'assistant' && $type->isText() && $part->getChannel()->isThought()) {
                    $thoughts[] = (string) $part->getText();
                    continue;
                }

                if ($type->isFunctionResponse()) {
                    $functionResponse = $part->getFunctionResponse();
                    if (!$functionResponse) {
                        // This should be impossible due to class internals, but still needs to be checked.
                        throw new RuntimeException(
                            'The function_response typed message part must contain a function response.'
                        );
                    }
                    // Function responses are their own `tool` role message.
                    $apiMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $functionResponse->getId(),
                        'content' => (string) json_encode($functionResponse->getResponse()),
                    ];
                    continue;
                }

                if ($type->isFunctionCall()) {
                    $functionCall = $part->getFunctionCall();
                    if (!$functionCall) {
                        // This should be impossible due to class internals, but still needs to be checked.
                        throw new RuntimeException(
                            'The function_call typed message part must contain a function call.'
                        );
                    }
                    $toolCalls[] = [
                        'id' => $functionCall->getId(),
                        'type' => 'function',
                        'function' => [
                            'name' => $functionCall->getName(),
                            'arguments' => (string) json_encode($functionCall->getArgs() ?? new \stdClass()),
                        ],
                    ];
                    continue;
                }

                $contentPart = $this->getMessageContentPart($part);
                if ($contentPart !== null) {
                    $contentParts[] = $contentPart;
                }
            }

            if ($passback && $roleString === 'assistant') {
                $assistantMessage = [
                    'role' => 'assistant',
                    'content' => $this->normalizeContent($contentParts),
                ];
                if ($thoughts) {
                    $assistantMessage['reasoning_content'] = implode("\n", $thoughts);
                }
                if ($toolCalls) {
                    $assistantMessage['tool_calls'] = $toolCalls;
                }
                $apiMessages[] = $assistantMessage;
                continue;
            }

            // Emit the textual/visual content message, if any.
            if ($contentParts || !$toolCalls) {
                $apiMessages[] = [
                    'role' => $roleString,
                    'content' => $this->normalizeContent($contentParts),
                ];
            }

            // Emit an assistant message carrying the tool calls, if any.
            if ($toolCalls) {
                $apiMessages[] = [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => $toolCalls,
                ];
            }
        }

        return $apiMessages;
    }

    /**
     * Normalizes a list of content parts into the Chat Completions `content` value.
     *
     * When there is a single text part, a plain string is returned (the most widely supported form).
     * When images are present, the structured array form is returned.
     *
     * @since 1.0.0
     *
     * @param list<array<string, mixed>> $contentParts The content parts.
     * @return string|list<array<string, mixed>> The normalized content.
     */
    protected function normalizeContent(array $contentParts)
    {
        if (count($contentParts) === 0) {
            return '';
        }

        $allText = true;
        foreach ($contentParts as $contentPart) {
            if (($contentPart['type'] ?? '') !== 'text') {
                $allText = false;
                break;
            }
        }

        if ($allText) {
            $text = '';
            foreach ($contentParts as $contentPart) {
                $text .= $contentPart['text'];
            }
            return $text;
        }

        return $contentParts;
    }

    /**
     * Returns the NVIDIA API specific role string for the given message role.
     *
     * @since 1.0.0
     *
     * @param MessageRoleEnum $role The message role.
     * @return string The role for the API request.
     */
    protected function getMessageRoleString(MessageRoleEnum $role): string
    {
        if ($role === MessageRoleEnum::model()) {
            return 'assistant';
        }
        return 'user';
    }

    /**
     * Returns the Chat Completions content part data for a (text or file) message part.
     *
     * @since 1.0.0
     *
     * @param MessagePart $part The message part to get the data for.
     * @return ?array<string, mixed> The content part data, or null to skip the part.
     * @throws InvalidArgumentException If the message part type or data is unsupported.
     */
    protected function getMessageContentPart(MessagePart $part): ?array
    {
        $type = $part->getType();

        if ($type->isText()) {
            // Internal reasoning ("thought") parts are not sent back to the API.
            if ($part->getChannel()->isThought()) {
                return null;
            }
            return [
                'type' => 'text',
                'text' => $part->getText(),
            ];
        }

        if ($type->isFile()) {
            $file = $part->getFile();
            if (!$file) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The file typed message part must contain a file.'
                );
            }
            if (!$file->isImage()) {
                throw new InvalidArgumentException(
                    'Unsupported file type: The NVIDIA NIM Chat Completions API only supports image files.'
                );
            }
            if ($file->isRemote()) {
                $fileUrl = $file->getUrl();
                if (!$fileUrl) {
                    // This should be impossible due to class internals, but still needs to be checked.
                    throw new RuntimeException(
                        'The remote file must contain a URL.'
                    );
                }
                return [
                    'type' => 'image_url',
                    'image_url' => ['url' => $fileUrl],
                ];
            }
            $dataUri = $file->getDataUri();
            if (!$dataUri) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The inline file must contain base64 data.'
                );
            }
            return [
                'type' => 'image_url',
                'image_url' => ['url' => $dataUri],
            ];
        }

        throw new InvalidArgumentException(
            sprintf(
                'Unsupported message part type "%s".',
                $type
            )
        );
    }

    /**
     * Prepares the tools parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<FunctionDeclaration> $functionDeclarations The function declarations.
     * @return list<array<string, mixed>> The prepared tools parameter.
     */
    protected function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = [];

        foreach ($functionDeclarations as $functionDeclaration) {
            $parameters = $functionDeclaration->getParameters();
            if ($parameters === null) {
                $parameters = [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ];
            }
            $tools[] = [
                'type' => 'function',
                'function' => array_filter(
                    [
                        'name' => $functionDeclaration->getName(),
                        'description' => $functionDeclaration->getDescription(),
                        'parameters' => $parameters,
                    ],
                    static function ($value) {
                        return $value !== null;
                    }
                ),
            ];
        }

        return $tools;
    }

    /**
     * Parses the response from the API endpoint to a generative AI result.
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the API endpoint.
     * @return GenerativeAiResult The parsed generative AI result.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['choices']) || !$responseData['choices']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'choices');
        }
        if (!is_array($responseData['choices']) || !array_is_list($responseData['choices'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'choices',
                'The value must be an indexed array.'
            );
        }

        $candidates = [];
        foreach ($responseData['choices'] as $index => $choice) {
            if (!is_array($choice)) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "choices[{$index}]",
                    'The value must be an associative array.'
                );
            }
            $candidate = $this->parseChoiceToCandidate($choice, (int) $index);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        if (isset($responseData['usage']) && is_array($responseData['usage'])) {
            $usage = $responseData['usage'];
            $promptTokens = $usage['prompt_tokens'] ?? 0;
            $completionTokens = $usage['completion_tokens'] ?? 0;
            $tokenUsage = new TokenUsage(
                $promptTokens,
                $completionTokens,
                $usage['total_tokens'] ?? ($promptTokens + $completionTokens)
            );
        } else {
            $tokenUsage = new TokenUsage(0, 0, 0);
        }

        // Use any other data from the response as provider-specific response metadata.
        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['choices'], $additionalData['usage']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses a single choice from the API response into a Candidate object.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $choice The choice data from the API response.
     * @param int $index The index of the choice in the choices array.
     * @return Candidate|null The parsed candidate, or null if the choice should be skipped.
     */
    protected function parseChoiceToCandidate(array $choice, int $index): ?Candidate
    {
        $messageData = $choice['message'] ?? null;
        if (!is_array($messageData)) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "choices[{$index}].message"
            );
        }

        $parts = [];
        $hasFunctionCalls = false;

        if ($this->expectsJsonOutput() && empty($messageData['tool_calls'])
            && (!isset($messageData['content']) || !is_string($messageData['content'])
                || trim($messageData['content']) === '')) {
            JsonOutput::normalize('', (string) ($choice['finish_reason'] ?? 'stop'));
        }

        // Reasoning content, when provided separately, is surfaced as a thought part. Some NVIDIA
        // backends name the field "reasoning" instead of "reasoning_content".
        foreach (['reasoning_content', 'reasoning'] as $reasoningField) {
            if (isset($messageData[$reasoningField]) && is_string($messageData[$reasoningField])) {
                $reasoning = trim($messageData[$reasoningField]);
                if ($reasoning !== '') {
                    $parts[] = new MessagePart($reasoning, MessagePartChannelEnum::thought());
                    break;
                }
            }
        }

        if (isset($messageData['content']) && is_string($messageData['content']) && $messageData['content'] !== '') {
            $jsonOutput = $this->expectsJsonOutput() && empty($messageData['tool_calls']);
            $rawContent = $messageData['content'];
            $structuredOutput = $jsonOutput || JsonOutput::isValid(trim($rawContent));
            if ($jsonOutput) {
                $segments = [
                    'reasoning' => '',
                    'text' => JsonOutput::normalize($rawContent, (string) ($choice['finish_reason'] ?? 'stop')),
                ];
            } elseif ($structuredOutput) {
                $segments = ['reasoning' => '', 'text' => trim($rawContent)];
            } else {
                $segments = $this->splitReasoningFromContent($rawContent);
            }
            if ($segments['reasoning'] !== '') {
                $parts[] = new MessagePart($segments['reasoning'], MessagePartChannelEnum::thought());
            }
            if ($segments['text'] !== '') {
                // Reject rather than alter punctuation blindly or damage structured output.
                if (!$structuredOutput && Settings::get('editorial_policy')
                    && IndianEnglishPolicy::containsEmDash($segments['text'])) {
                    throw new RuntimeException(
                        'Content quality check failed: the generated text contains an em dash. '
                        . 'Generate the content again using commas, full stops, colons or brackets.'
                    );
                }
                $parts[] = new MessagePart($segments['text']);
            }
        }

        if (isset($messageData['tool_calls']) && is_array($messageData['tool_calls'])) {
            foreach ($messageData['tool_calls'] as $toolCall) {
                if (!is_array($toolCall)) {
                    continue;
                }
                $part = $this->parseToolCallToPart($toolCall);
                if ($part !== null) {
                    $parts[] = $part;
                    $hasFunctionCalls = true;
                }
            }
        }

        if (count($parts) === 0) {
            return null;
        }

        $finishReasonString = isset($choice['finish_reason']) && is_string($choice['finish_reason'])
            ? $choice['finish_reason']
            : 'stop';
        $finishReason = $this->parseFinishReason($finishReasonString, $hasFunctionCalls);

        return new Candidate(new Message(MessageRoleEnum::model(), $parts), $finishReason);
    }

    /** Detect all supported ways for callers to request JSON output. */
    protected function expectsJsonOutput(): bool
    {
        $config = $this->getConfig();
        $custom = $config->getCustomOptions();
        $format = $custom['response_format'] ?? null;
        return $config->getOutputMimeType() === 'application/json'
            || $config->getOutputSchema() !== null
            || (is_array($format) && in_array($format['type'] ?? '', ['json_object', 'json_schema'], true));
    }

    /**
     * Splits inline `<think>...</think>` reasoning blocks from the user-visible content.
     *
     * @since 1.0.0
     *
     * @param string $content The raw content string.
     * @return array{reasoning: string, text: string} The separated reasoning and text.
     */
    protected function splitReasoningFromContent(string $content): array
    {
        $reasoning = '';

        if (strpos($content, '<think>') !== false) {
            if (preg_match_all('/<think>(.*?)<\/think>/s', $content, $matches)) {
                $reasoning = trim(implode("\n", $matches[1]));
            }
            $content = (string) preg_replace('/<think>.*?<\/think>/s', '', $content);
        }

        return [
            'reasoning' => $reasoning,
            'text' => trim($content),
        ];
    }

    /**
     * Parses a tool call from the API response into a MessagePart.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $toolCall The tool call data.
     * @return MessagePart|null The parsed message part, or null to skip.
     */
    protected function parseToolCallToPart(array $toolCall): ?MessagePart
    {
        if (($toolCall['type'] ?? 'function') !== 'function') {
            return null;
        }

        $function = $toolCall['function'] ?? null;
        if (!is_array($function) || !isset($function['name']) || !is_string($function['name'])) {
            throw new InvalidArgumentException(
                'Tool call has an invalid function shape.'
            );
        }

        $id = isset($toolCall['id']) && is_string($toolCall['id']) ? $toolCall['id'] : '';

        /*
         * Parse and normalize function arguments. The API returns arguments as a JSON
         * string. An empty object "{}" decodes to an empty array, which semantically
         * means "no arguments" and is normalized to null.
         */
        $args = null;
        if (isset($function['arguments']) && is_string($function['arguments']) && $function['arguments'] !== '') {
            $decoded = json_decode($function['arguments'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $args = $decoded;
            }
        }

        return new MessagePart(new FunctionCall($id, $function['name'], $args));
    }

    /**
     * Parses the Chat Completions finish reason to a finish reason enum.
     *
     * @since 1.0.0
     *
     * @param string $finishReason The finish reason from the API response.
     * @param bool $hasFunctionCalls Whether the choice contains function calls.
     * @return FinishReasonEnum The finish reason.
     */
    protected function parseFinishReason(string $finishReason, bool $hasFunctionCalls): FinishReasonEnum
    {
        switch ($finishReason) {
            case 'stop':
                return $hasFunctionCalls ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop();
            case 'length':
                return FinishReasonEnum::length();
            case 'tool_calls':
            case 'function_call':
                return FinishReasonEnum::toolCalls();
            case 'content_filter':
                return FinishReasonEnum::contentFilter();
            default:
                return $hasFunctionCalls ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop();
        }
    }
}
