<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Provider;

use ChandanDigital\NvidiaAi\Metadata\NvidiaModelMetadataDirectory;
use ChandanDigital\NvidiaAi\Models\NvidiaImageGenerationModel;
use ChandanDigital\NvidiaAi\Models\NvidiaTextGenerationModel;
use ChandanDigital\NvidiaAi\Support\Settings;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception text, not browser output.

/**
 * Class for the NVIDIA NIM provider.
 *
 * The provider ID stays "nvidia", exactly as in the original plugin, so existing code that asks the
 * WordPress AI Client for the "nvidia" provider keeps working.
 *
 * @since 1.0.0
 * @since 1.1.0 The base URL comes from the plugin settings.
 */
class NvidiaProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function baseUrl(): string
    {
        return Settings::base_url();
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                return new NvidiaTextGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isImageGeneration()) {
                return new NvidiaImageGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $providerMetadataArgs = [
            'nvidia',
            'NVIDIA',
            ProviderTypeEnum::cloud(),
            'https://build.nvidia.com',
            RequestAuthenticationMethod::apiKey()
        ];
        // Provider description support was added in 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            // For WordPress, we should translate the description.
            if (function_exists('__')) {
                // phpcs:ignore Generic.Files.LineLength.TooLong
                $providerMetadataArgs[] = __('Text generation with NVIDIA-hosted models (Kimi K3, Llama, Nemotron, Mistral, Qwen), image understanding on vision models, and image generation with FLUX models.', 'chandan-digital-ai-for-nvidia');
            } else {
                // phpcs:ignore Generic.Files.LineLength.TooLong -- Description literal, kept in sync with the translated string above.
                $providerMetadataArgs[] = 'Text generation with NVIDIA-hosted models (Kimi K3, Llama, Nemotron, Mistral, Qwen), image understanding on vision models, and image generation with FLUX models.';
            }
        }
        // Provider logoPath support was added in 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $providerMetadataArgs[] = dirname(__DIR__, 2) . '/assets/images/nvidia.svg';
        }
        return new ProviderMetadata(...$providerMetadataArgs);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // Check valid API access by attempting to list models.
        return new ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new NvidiaModelMetadataDirectory();
    }
}
