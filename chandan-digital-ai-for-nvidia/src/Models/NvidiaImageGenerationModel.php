<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception text, not browser output.

/**
 * Class for a NVIDIA NIM image generation model using the NVIDIA GenAI image API.
 *
 * NVIDIA's image generation models (the FLUX family) are not served via the OpenAI-compatible
 * `integrate.api.nvidia.com` endpoint. They are exposed through NVIDIA's GenAI inference endpoint
 * (`ai.api.nvidia.com/v1/genai/{model}`), which uses a NVIDIA-specific request and response schema:
 * a JSON body of `prompt`, `cfg_scale`, `width`, `height`, `steps`, and an optional `seed`, returning
 * `{"artifacts": [{"base64": "...", "finishReason": "SUCCESS"}]}` with the image bytes inline.
 *
 * @since 1.0.0
 *
 * @phpstan-type ArtifactData array{base64?: string, finishReason?: string}
 * @phpstan-type ImageResponseData array{artifacts?: list<ArtifactData>}
 * @phpstan-type ModelProfile array{cfg_scale: float, cfg_max: float, steps: int, steps_max: int}
 */
class NvidiaImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface
{
    /**
     * Base URL for the NVIDIA GenAI image generation endpoint.
     *
     * @since 1.0.0
     *
     * @var string
     */
    private const GENAI_BASE_URL = 'https://ai.api.nvidia.com/v1/genai';

    /**
     * Minimum HTTP request timeout, in seconds, for image generation.
     *
     * Image generation is significantly slower than text generation (including model cold starts), so
     * a longer timeout than the typical default is required to avoid premature timeouts.
     *
     * @since 1.0.0
     *
     * @var float
     */
    private const MIN_REQUEST_TIMEOUT = 60.0;

    /**
     * Per-model generation profiles (default and maximum cfg_scale / steps).
     *
     * Each FLUX variant accepts a different range of `cfg_scale` and `steps`. The defaults are chosen
     * for a good quality/speed balance; user-provided custom options are clamped to the maximums.
     *
     * @since 1.0.0
     *
     * @var array<string, ModelProfile>
     */
    private const MODEL_PROFILES = [
        'black-forest-labs/flux.1-schnell' => ['cfg_scale' => 0.0, 'cfg_max' => 0.0, 'steps' => 4, 'steps_max' => 4],
        'black-forest-labs/flux.1-dev' => ['cfg_scale' => 3.5, 'cfg_max' => 9.0, 'steps' => 28, 'steps_max' => 100],
        'black-forest-labs/flux.2-klein-4b' => ['cfg_scale' => 1.0, 'cfg_max' => 1.0, 'steps' => 4, 'steps_max' => 4],
    ];

    /**
     * Generation profile used when a model is not explicitly listed above.
     *
     * @since 1.0.0
     *
     * @var ModelProfile
     */
    private const DEFAULT_PROFILE = ['cfg_scale' => 3.5, 'cfg_max' => 9.0, 'steps' => 25, 'steps_max' => 50];

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();
        $params = $this->prepareGenerateImageParams($prompt);

        $config = $this->getConfig();
        $candidateCount = $config->getCandidateCount();
        $iterations = (is_int($candidateCount) && $candidateCount > 0) ? $candidateCount : 1;

        $url = self::GENAI_BASE_URL . '/' . $this->metadata()->getId();
        $requestOptions = $this->resolveRequestOptions();

        $candidates = [];
        for ($i = 0; $i < $iterations; $i++) {
            // Vary the seed across iterations so multiple candidates are not identical.
            $iterationParams = $params;
            if (isset($iterationParams['seed']) && is_int($iterationParams['seed'])) {
                $iterationParams['seed'] += $i;
            }

            $request = new Request(
                HttpMethodEnum::POST(),
                $url,
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                $iterationParams,
                $requestOptions
            );

            // Add authentication credentials to the request.
            $request = $this->getRequestAuthentication()->authenticateRequest($request);

            $response = $httpTransporter->send($request);
            ResponseUtil::throwIfNotSuccessful($response);

            foreach ($this->parseResponseToCandidates($response) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return new GenerativeAiResult(
            'img-' . substr(md5(uniqid('', true)), 0, 12),
            $candidates,
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Resolves the request options, ensuring a timeout long enough for image generation.
     *
     * @since 1.0.0
     *
     * @return RequestOptions The request options to use for the image generation request.
     */
    protected function resolveRequestOptions(): RequestOptions
    {
        $existing = $this->getRequestOptions();
        // Clone so the configured options object is not mutated.
        $options = $existing !== null ? RequestOptions::fromArray($existing->toArray()) : new RequestOptions();

        $timeout = $options->getTimeout();
        if ($timeout === null || $timeout < self::MIN_REQUEST_TIMEOUT) {
            $options->setTimeout(self::MIN_REQUEST_TIMEOUT);
        }

        return $options;
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt to generate an image for.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $config = $this->getConfig();
        $profile = self::MODEL_PROFILES[$this->metadata()->getId()] ?? self::DEFAULT_PROFILE;

        [$width, $height] = $this->resolveDimensions(
            $config->getOutputMediaOrientation(),
            $config->getOutputMediaAspectRatio()
        );

        $params = [
            'prompt' => $this->preparePromptParam($prompt),
            'cfg_scale' => $profile['cfg_scale'],
            'width' => $width,
            'height' => $height,
            'steps' => $profile['steps'],
        ];

        /*
         * Any custom options are added to the parameters as well. This lets developers tune
         * provider-specific options (e.g. seed, negative_prompt) or override the defaults. The
         * cfg_scale and steps values are clamped to what the selected model accepts.
         */
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            $params[$key] = $value;
        }

        if (is_numeric($params['cfg_scale'])) {
            $params['cfg_scale'] = min((float) $params['cfg_scale'], $profile['cfg_max']);
        }
        if (is_numeric($params['steps'])) {
            $params['steps'] = min((int) $params['steps'], $profile['steps_max']);
        }

        return $params;
    }

    /**
     * Extracts the prompt text from the messages.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to prepare. The NVIDIA GenAI image API accepts a
     *                                single text prompt.
     * @return string The prepared prompt parameter.
     * @throws InvalidArgumentException If the messages do not contain a single user text prompt.
     */
    protected function preparePromptParam(array $messages): string
    {
        if (count($messages) !== 1) {
            throw new InvalidArgumentException(
                'The NVIDIA image generation API requires a single user message as prompt.'
            );
        }
        $message = $messages[0];
        if (!$message->getRole()->isUser()) {
            throw new InvalidArgumentException(
                'The NVIDIA image generation API requires a user message as prompt.'
            );
        }

        $text = null;
        foreach ($message->getParts() as $part) {
            $partText = $part->getText();
            if ($partText !== null) {
                $text = $partText;
                break;
            }
        }

        if ($text === null) {
            throw new InvalidArgumentException(
                'The NVIDIA image generation API requires a single text message part as prompt.'
            );
        }

        return $text;
    }

    /**
     * Resolves the requested orientation/aspect ratio into NVIDIA-supported width and height values.
     *
     * The FLUX models accept a discrete set of dimensions; 1024x1024 (square), 1344x896 (landscape)
     * and 896x1344 (portrait) are valid across all of the supported models.
     *
     * @since 1.0.0
     *
     * @param MediaOrientationEnum|null $orientation The desired media orientation.
     * @param string|null $aspectRatio The desired media aspect ratio.
     * @return array{0: int, 1: int} The resolved [width, height].
     */
    protected function resolveDimensions(?MediaOrientationEnum $orientation, ?string $aspectRatio): array
    {
        if ($aspectRatio !== null) {
            switch ($aspectRatio) {
                case '1:1':
                    return [1024, 1024];
                case '3:2':
                    return [1344, 896];
                case '2:3':
                    return [896, 1344];
                default:
                    throw new InvalidArgumentException(
                        'The aspect ratio "' . $aspectRatio . '" is not supported.'
                    );
            }
        }

        if ($orientation !== null) {
            if ($orientation->isLandscape()) {
                return [1344, 896];
            }
            if ($orientation->isPortrait()) {
                return [896, 1344];
            }
        }

        return [1024, 1024];
    }

    /**
     * Parses the API response into a list of candidates.
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the API endpoint.
     * @return list<Candidate> The parsed candidates.
     */
    protected function parseResponseToCandidates(Response $response): array
    {
        /** @var ImageResponseData $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['artifacts']) || !is_array($responseData['artifacts'])) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'artifacts');
        }

        $candidates = [];
        foreach ($responseData['artifacts'] as $index => $artifact) {
            if (!is_array($artifact) || !isset($artifact['base64']) || !is_string($artifact['base64'])) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "artifacts[{$index}]",
                    'The value must contain a base64 string.'
                );
            }

            $base64 = $artifact['base64'];
            $imageFile = new File($base64, $this->detectMimeType($base64));
            $candidates[] = new Candidate(
                new Message(MessageRoleEnum::model(), [new MessagePart($imageFile)]),
                FinishReasonEnum::stop()
            );
        }

        if (count($candidates) === 0) {
            throw new RuntimeException('The NVIDIA image generation API returned no images.');
        }

        return $candidates;
    }

    /**
     * Detects the image MIME type from the leading characters of its base64 representation.
     *
     * @since 1.0.0
     *
     * @param string $base64 The base64-encoded image data.
     * @return string The detected MIME type, defaulting to image/jpeg.
     */
    protected function detectMimeType(string $base64): string
    {
        if (strncmp($base64, 'iVBORw0KGg', 10) === 0) {
            return 'image/png';
        }
        if (strncmp($base64, 'R0lGOD', 6) === 0) {
            return 'image/gif';
        }
        if (strncmp($base64, 'UklGR', 5) === 0) {
            return 'image/webp';
        }
        return 'image/jpeg';
    }
}
