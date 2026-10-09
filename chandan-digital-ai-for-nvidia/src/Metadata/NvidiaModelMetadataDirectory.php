<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Metadata;

use ChandanDigital\NvidiaAi\Provider\NvidiaProvider;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Class for the NVIDIA NIM model metadata directory.
 *
 * The NVIDIA NIM `/models` endpoint returns the full public model catalogue (every model NVIDIA has
 * published), regardless of the API key. Only a subset of those models is actually provisioned for
 * inference on a given account; the rest return `404 "Function not found for account"` when called.
 * The endpoint also reports no capability information.
 *
 * Which models are offered to the WordPress AI Client:
 *
 * - Built-in models from the original plugin (verified by its developer) are offered while NVIDIA's
 *   live catalogue still lists them, exactly as before.
 * - Kimi K3 and custom models are offered only after a successful access check with your API key
 *   (AI Models tab, Playground, or a successful AI Client request). A caller that names one of these
 *   models explicitly can still use it before that check; NVIDIA then decides.
 * - Image generation (FLUX) models are served by NVIDIA's separate GenAI endpoint, are not in the
 *   catalogue, and are offered unconditionally, as before.
 * - Models disabled in the AI Models tab are never offered.
 *
 * The default model chosen in the dashboard is listed first, because callers that do not ask for a
 * specific model usually take the first suitable one.
 *
 * @since 1.0.0
 * @since 1.1.0 Model list driven by the dashboard model registry.
 *
 * @phpstan-type ModelsResponseData array{
 *     data: list<array{id: string}>
 * }
 */
class NvidiaModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * How long the catalogue is reused between requests, in seconds.
     *
     * The original plugin fetched the catalogue on every page load that used the AI Client.
     *
     * @since 1.1.0
     */
    private const CATALOG_TTL = 900;

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            NvidiaProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * Reuses a recent catalogue fetched with the same API key and base URL, to avoid a /models request
     * on every page load.
     *
     * @since 1.1.0
     */
    protected function sendListModelsRequest(): array
    {
        $cacheKey = $this->catalogCacheKey();
        if ($cacheKey !== null) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && $cached) {
                return $this->toMap($this->buildModelMetadataList(array_fill_keys($cached, true)));
            }
        }
        try {
            return parent::sendListModelsRequest();
        } catch (ClientException $e) {
            // An old key left in Settings > Connectors or in this plugin: try the other saved key once.
            $auth = $this->getRequestAuthentication();
            $other = ($e->getCode() === 401 || strpos($e->getMessage(), '(401)') !== false) && $auth instanceof ApiKeyRequestAuthentication
                ? Settings::other_saved_key($auth->getApiKey())
                : '';
            if ($other === '') {
                throw $e;
            }
            $this->setRequestAuthentication(new ApiKeyRequestAuthentication($other));
            $models = parent::sendListModelsRequest();
            // Models created from now on in this page load use the working key.
            \WordPress\AiClient\AiClient::defaultRegistry()->setProviderRequestAuthentication('nvidia', new ApiKeyRequestAuthentication($other));
            return $models;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Includes the model registry revision and base URL, so enabling, disabling or verifying a model
     * takes effect immediately even when a persistent AI Client cache is configured.
     *
     * @since 1.1.0
     */
    protected function getBaseCacheKey(): string
    {
        return parent::getBaseCacheKey() . '_r' . ModelRegistry::revision() . '_' . substr(md5(Settings::base_url()), 0, 8);
    }

    /**
     * {@inheritDoc}
     *
     * Lets callers use Kimi K3 or a custom model by naming it explicitly, before its access check.
     *
     * @since 1.1.0
     */
    protected function createModelMetadataForExplicitModelIds(array $modelIds): array
    {
        $metadata = [];
        foreach ($modelIds as $modelId) {
            $model = ModelRegistry::get((string) $modelId);
            if ($model === null || !$model['enabled'] || !$model['requires_verification']) {
                continue;
            }
            $metadata[$model['id']] = $this->createMetadata($model);
        }
        return $metadata;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData('NVIDIA', 'data');
        }

        // Collect the set of model IDs that NVIDIA currently reports in its catalogue.
        $catalogIds = [];
        foreach ((array) $responseData['data'] as $modelData) {
            if (isset($modelData['id']) && is_string($modelData['id'])) {
                $catalogIds[$modelData['id']] = true;
            }
        }

        $cacheKey = $this->catalogCacheKey();
        if ($cacheKey !== null && $catalogIds) {
            set_transient($cacheKey, array_keys($catalogIds), self::CATALOG_TTL);
        }

        return $this->buildModelMetadataList($catalogIds);
    }

    /**
     * Builds the list of models offered to the AI Client.
     *
     * @since 1.1.0
     *
     * @param array<string, bool> $catalogIds Model IDs in NVIDIA's catalogue.
     * @return list<ModelMetadata>
     */
    protected function buildModelMetadataList(array $catalogIds): array
    {
        $models = [];
        foreach (ModelRegistry::all() as $modelId => $model) {
            if (!$model['enabled']) {
                continue;
            }
            if ($model['kind'] !== 'image') {
                if ($model['requires_verification']) {
                    if (($model['status']['state'] ?? '') !== 'verified') {
                        continue;
                    }
                } elseif (!isset($catalogIds[$modelId])) {
                    continue;
                }
            }
            $models[$modelId] = $this->createMetadata($model);
        }

        $default = (string) Settings::get('default_model');
        if (isset($models[$default])) {
            $models = [$default => $models[$default]] + $models;
        }

        return array_values($models);
    }

    /**
     * Creates metadata for one registry model.
     *
     * The NVIDIA NIM API does not return model capabilities, so they are declared here. Chat models
     * support text generation and chat history; vision-capable models additionally accept image input.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $model Model descriptor.
     */
    protected function createMetadata(array $model): ModelMetadata
    {
        if ($model['kind'] === 'image') {
            return new ModelMetadata(
                $model['id'],
                $model['name'],
                [CapabilityEnum::imageGeneration()],
                [
                    new SupportedOption(OptionEnum::candidateCount()),
                    // The NVIDIA GenAI image endpoint returns inline JPEG image data.
                    new SupportedOption(OptionEnum::outputMimeType(), ['image/jpeg']),
                    new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
                    new SupportedOption(OptionEnum::outputMediaOrientation(), [
                        MediaOrientationEnum::square(),
                        MediaOrientationEnum::landscape(),
                        MediaOrientationEnum::portrait(),
                    ]),
                    new SupportedOption(OptionEnum::outputMediaAspectRatio(), ['1:1', '3:2', '2:3']),
                    new SupportedOption(OptionEnum::customOptions()),
                    new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                    new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
                ]
            );
        }

        $inputModalities = [[ModalityEnum::text()]];
        if ($model['kind'] === 'vision') {
            $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
        }

        return new ModelMetadata(
            $model['id'],
            // The original plugin used the ID as the name, because NVIDIA returns no display name.
            $model['source'] === 'custom' || $model['id'] === ModelRegistry::KIMI_K3 ? $model['name'] : $model['id'],
            [
                CapabilityEnum::textGeneration(),
                CapabilityEnum::chatHistory(),
            ],
            [
                new SupportedOption(OptionEnum::systemInstruction()),
                new SupportedOption(OptionEnum::maxTokens()),
                new SupportedOption(OptionEnum::temperature()),
                new SupportedOption(OptionEnum::topP()),
                new SupportedOption(OptionEnum::stopSequences()),
                new SupportedOption(OptionEnum::presencePenalty()),
                new SupportedOption(OptionEnum::frequencyPenalty()),
                new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
                new SupportedOption(OptionEnum::outputSchema()),
                new SupportedOption(OptionEnum::functionDeclarations()),
                new SupportedOption(OptionEnum::customOptions()),
                new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
                new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
            ]
        );
    }

    /**
     * Converts a list to the ID-keyed map that sendListModelsRequest() returns.
     *
     * @param list<ModelMetadata> $list Model metadata.
     * @return array<string, ModelMetadata>
     */
    private function toMap(array $list): array
    {
        $map = [];
        foreach ($list as $metadata) {
            $map[$metadata->getId()] = $metadata;
        }
        return $map;
    }

    /**
     * Transient key for the cached catalogue, tied to the API key and base URL in use.
     */
    private function catalogCacheKey(): ?string
    {
        try {
            $auth = $this->getRequestAuthentication();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$auth instanceof ApiKeyRequestAuthentication) {
            return null;
        }
        return 'cdnv_catalog_' . substr(hash('sha256', $auth->getApiKey() . '|' . Settings::base_url()), 0, 20);
    }
}
