<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Api;

use ChandanDigital\NvidiaAi\Support\ModelRegistry;

/**
 * Validates images before they are sent to NVIDIA.
 *
 * Uploaded images arrive as base64 data URIs. They are decoded and inspected in memory only and
 * are never written to disk or to the Media Library. Image URLs are never downloaded by WordPress:
 * NVIDIA fetches them. They are still checked so that only public https:// addresses are passed on,
 * which keeps private network addresses and unpublished media out of requests.
 *
 * @since 1.1.0
 */
final class ImageInput
{
    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** Largest accepted width or height in pixels. */
    private const MAX_DIMENSION = 8192;

    /**
     * Validates an image URL.
     *
     * @param string $url Image URL.
     * @param array<string, mixed> $settings Model settings.
     * @return string|ApiError The cleaned URL, or an error.
     */
    public static function validate_url(string $url, array $settings)
    {
        if (empty($settings['image_urls'])) {
            return self::error(__('Image URLs are turned off for this model. Upload the image instead, or enable image URLs in the model settings.', 'chandan-digital-ai-for-nvidia'));
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return self::error(__('Enter an image URL of up to 2048 characters.', 'chandan-digital-ai-for-nvidia'));
        }
        $clean = esc_url_raw($url, ['https']);
        if ($clean === '' || wp_parse_url($clean, PHP_URL_SCHEME) !== 'https') {
            return self::error(__('Only https:// image URLs are accepted.', 'chandan-digital-ai-for-nvidia'));
        }
        $parts = wp_parse_url($clean);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return self::error(__('The image URL is not valid. URLs with a username or password are not accepted.', 'chandan-digital-ai-for-nvidia'));
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return self::error(__('Image URLs must use the standard https port.', 'chandan-digital-ai-for-nvidia'));
        }
        // IP literals and local names are checked directly, because wp_http_validate_url() trusts
        // any address on this site's own host.
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -10) === '.localhost'
            || ($isIp && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            return self::error(__('The image URL points to a private, local or unreachable address. Only public addresses can be sent to NVIDIA.', 'chandan-digital-ai-for-nvidia'));
        }
        // Rejects localhost and private or reserved IP ranges. This resolves DNS but downloads nothing.
        if (!wp_http_validate_url($clean)) {
            return self::error(__('The image URL points to a private, local or unreachable address. Only public addresses can be sent to NVIDIA.', 'chandan-digital-ai-for-nvidia'));
        }

        $path = isset($parts['path']) ? strtolower((string) $parts['path']) : '';
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension !== '') {
            $format = $extension === 'jpg' || $extension === 'jpe' ? 'jpeg' : $extension;
            if (!in_array($format, (array) $settings['image_formats'], true)) {
                /* translators: %s: list of image formats. */
                return self::error(sprintf(__('This image format is not enabled for this model. Allowed formats: %s.', 'chandan-digital-ai-for-nvidia'), self::format_list($settings)));
            }
        }

        // Keep media attached to unpublished or private content out of external requests.
        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
        if (is_string($siteHost) && strcasecmp($siteHost, (string) $parts['host']) === 0) {
            $attachmentId = attachment_url_to_postid($clean);
            if ($attachmentId > 0) {
                if (!current_user_can('read_post', $attachmentId)) {
                    return self::error(__('You do not have permission to use this media file.', 'chandan-digital-ai-for-nvidia'));
                }
                $parent = (int) wp_get_post_parent_id($attachmentId);
                if ($parent > 0 && get_post_status($parent) !== 'publish') {
                    return self::error(__('This image belongs to unpublished or private content, so its URL is not sent to NVIDIA. Upload the file directly if you want to analyse it.', 'chandan-digital-ai-for-nvidia'));
                }
            }
        }

        return $clean;
    }

    /**
     * Validates an uploaded image supplied as a base64 data URI.
     *
     * @param string $dataUri Data URI.
     * @param array<string, mixed> $settings Model settings.
     * @return string|ApiError A normalised data URI, or an error.
     */
    public static function validate_data_uri(string $dataUri, array $settings)
    {
        $maxBytes = max(1, (int) $settings['image_max_mb']) * 1048576;
        // Base64 adds about a third; allow for that before decoding anything.
        if (strlen($dataUri) > (int) ceil($maxBytes * 4 / 3) + 100) {
            /* translators: %d: size limit in megabytes. */
            return self::error(sprintf(__('The image is larger than the %d MB limit for this model.', 'chandan-digital-ai-for-nvidia'), (int) $settings['image_max_mb']), 'image_too_large');
        }
        if (!preg_match('#^data:(image/[a-z0-9.+\-]+);base64,([A-Za-z0-9+/=\r\n]+)$#i', $dataUri, $match)) {
            return self::error(__('The uploaded image could not be read. Choose the file again.', 'chandan-digital-ai-for-nvidia'));
        }
        $bytes = base64_decode(str_replace(["\r", "\n"], '', $match[2]), true);
        if ($bytes === false || $bytes === '') {
            return self::error(__('The uploaded image could not be decoded.', 'chandan-digital-ai-for-nvidia'));
        }
        if (strlen($bytes) > $maxBytes) {
            /* translators: %d: size limit in megabytes. */
            return self::error(sprintf(__('The image is larger than the %d MB limit for this model.', 'chandan-digital-ai-for-nvidia'), (int) $settings['image_max_mb']), 'image_too_large');
        }

        // Trust the file's actual bytes, not the type the browser reported.
        $info = @getimagesizefromstring($bytes); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid images are reported below.
        if (!is_array($info) || !isset(self::IMAGE_TYPES[$info[2]])) {
            /* translators: %s: list of image formats. */
            return self::error(sprintf(__('The file is not a supported image. Allowed formats: %s.', 'chandan-digital-ai-for-nvidia'), self::format_list($settings)), 'unsupported_image_format');
        }
        $format = self::IMAGE_TYPES[$info[2]];
        if (!in_array($format, (array) $settings['image_formats'], true)) {
            /* translators: 1: detected format, 2: list of image formats. */
            return self::error(sprintf(__('%1$s images are not enabled for this model. Allowed formats: %2$s.', 'chandan-digital-ai-for-nvidia'), strtoupper($format), self::format_list($settings)), 'unsupported_image_format');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            /* translators: %d: maximum width/height in pixels. */
            return self::error(sprintf(__('Image width and height must be between 1 and %d pixels.', 'chandan-digital-ai-for-nvidia'), self::MAX_DIMENSION));
        }

        return 'data:' . ModelRegistry::IMAGE_FORMATS[$format] . ';base64,' . base64_encode($bytes);
    }

    /**
     * @param array<string, mixed> $settings Model settings.
     */
    private static function format_list(array $settings): string
    {
        return strtoupper(implode(', ', (array) $settings['image_formats']));
    }

    /**
     * @param string $message Message.
     * @param string $code Error code.
     */
    private static function error(string $message, string $code = 'invalid_image'): ApiError
    {
        return new ApiError($code, 0, '', null, $message);
    }
}
