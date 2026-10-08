<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Seo;

use ChandanDigital\NvidiaAi\Api\ApiError;
use ChandanDigital\NvidiaAi\Api\NvidiaClient;
use ChandanDigital\NvidiaAi\Api\PayloadBuilder;
use ChandanDigital\NvidiaAi\Content\IndianEnglishPolicy;
use ChandanDigital\NvidiaAi\Content\JsonOutput;
use ChandanDigital\NvidiaAi\Rest\RestController;
use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const ChandanDigital\NvidiaAi\PLUGIN_FILE;
use const ChandanDigital\NvidiaAi\VERSION;

/**
 * SEO Assistant box in the post and page editor.
 *
 * Uses NVIDIA models through this plugin's own client (so it does not depend on the WordPress AI
 * plugin) to suggest SEO titles and meta descriptions, review the page, improve the content,
 * suggest internal links to real published pages, and create a featured image with alt text.
 * Nothing is changed in a post until the user presses a "Use" button.
 *
 * @since 1.2.0
 */
final class SeoAssistant
{
    public const KEYWORD_META = '_cdnv_focus_keyword';
    public const TASKS = ['titles', 'meta', 'audit', 'improve', 'links', 'image'];
    private const MAX_CONTENT_CHARS = 60000;

    /** Chandan Digital SEO post meta keys, by field. */
    private const SEOM_FIELDS = [
        'title' => '_seom_title',
        'description' => '_seom_description',
        'keyword' => '_seom_keyword',
    ];

    /**
     * Registers hooks.
     */
    public static function init(): void
    {
        add_action('add_meta_boxes', [self::class, 'add_box'], 20, 2);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('enqueue_block_editor_assets', [self::class, 'sidebar_assets']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    /**
     * Post types the assistant appears on: public types that use the editor.
     *
     * @return list<string>
     */
    public static function post_types(): array
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if ($type !== 'attachment' && post_type_supports($type, 'editor')) {
                $types[] = (string) $type;
            }
        }
        /**
         * Filters the post types that show the SEO Assistant.
         *
         * @since 1.2.0
         *
         * @param list<string> $types Post type names.
         */
        return array_values((array) apply_filters('cdnv_seo_post_types', $types));
    }

    /**
     * Whether Chandan Digital SEO is active (its SEO title, description and keyword fields are used).
     */
    public static function has_seo_plugin(): bool
    {
        return defined('SEOM_VERSION') || class_exists('SEO_Manager_Meta');
    }

    /**
     * Whether the current user may use the assistant at all.
     */
    public static function user_can_use(): bool
    {
        return (bool) Settings::get('seo_assistant') && current_user_can(Settings::seo_capability());
    }

    /**
     * Adds the meta box.
     *
     * @param string $postType Post type.
     */
    public static function add_box($postType): void
    {
        if (!self::user_can_use() || !in_array((string) $postType, self::post_types(), true)) {
            return;
        }
        add_meta_box(
            'cdnv_seo_assistant',
            __('SEO Assistant (NVIDIA AI)', 'chandan-digital-ai-for-nvidia'),
            [self::class, 'render'],
            (string) $postType,
            'normal',
            'high'
        );
    }

    /**
     * Renders the meta box.
     *
     * @param \WP_Post $post Post.
     */
    public static function render($post): void
    {
        $keyword = self::stored_keyword((int) $post->ID);
        $selected = self::default_model();
        ?>
        <div id="cdnv-seo" class="cdnv-seo" data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
            <div class="cdnv-seo__row">
                <label for="cdnv-seo-keyword"><strong><?php esc_html_e('Focus keyword', 'chandan-digital-ai-for-nvidia'); ?></strong></label>
                <input type="text" id="cdnv-seo-keyword" class="regular-text" value="<?php echo esc_attr($keyword); ?>" maxlength="100" placeholder="<?php esc_attr_e('For example: digital marketing agency in Kolkata', 'chandan-digital-ai-for-nvidia'); ?>">
                <button type="button" class="button" data-seo-action="save-keyword"><?php esc_html_e('Save keyword', 'chandan-digital-ai-for-nvidia'); ?></button>
                <label for="cdnv-seo-model" class="cdnv-seo__model-label"><?php esc_html_e('Model', 'chandan-digital-ai-for-nvidia'); ?></label>
                <select id="cdnv-seo-model">
                    <?php foreach (ModelRegistry::enabled_chat_models() as $id => $model) : ?>
                        <option value="<?php echo esc_attr($id); ?>" <?php selected($selected, $id); ?>><?php echo esc_html($model['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cdnv-seo__actions">
                <button type="button" class="button" data-seo-task="titles"><?php esc_html_e('SEO titles', 'chandan-digital-ai-for-nvidia'); ?></button>
                <button type="button" class="button" data-seo-task="meta"><?php esc_html_e('Meta description', 'chandan-digital-ai-for-nvidia'); ?></button>
                <button type="button" class="button" data-seo-task="audit"><?php esc_html_e('SEO check', 'chandan-digital-ai-for-nvidia'); ?></button>
                <button type="button" class="button" data-seo-task="improve"><?php esc_html_e('Improve content', 'chandan-digital-ai-for-nvidia'); ?></button>
                <button type="button" class="button" data-seo-task="links"><?php esc_html_e('Internal links', 'chandan-digital-ai-for-nvidia'); ?></button>
                <?php if (current_user_can('upload_files')) : ?>
                    <button type="button" class="button" data-seo-task="image"><?php esc_html_e('Featured image', 'chandan-digital-ai-for-nvidia'); ?></button>
                <?php endif; ?>
            </div>
            <div class="cdnv-seo__status" role="status" aria-live="polite"></div>
            <div class="cdnv-seo__results" aria-live="polite"></div>
            <p class="description">
                <?php esc_html_e('Uses the title and content currently in the editor, including unsaved changes. Text and images are sent to NVIDIA. Nothing changes in your post until you press a "Use" button.', 'chandan-digital-ai-for-nvidia'); ?>
                <?php if (!self::has_seo_plugin()) : ?>
                    <?php esc_html_e('Chandan Digital SEO is not active, so SEO titles and descriptions can be copied but not saved automatically.', 'chandan-digital-ai-for-nvidia'); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Loads the assistant's script and styles on post edit screens.
     *
     * @param string $hook Admin page hook.
     */
    public static function assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !self::user_can_use()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array((string) $screen->post_type, self::post_types(), true)) {
            return;
        }
        $base = plugin_dir_url(PLUGIN_FILE) . 'assets/';
        wp_enqueue_style('cdnv-seo-assistant', $base . 'css/seo-assistant.css', [], VERSION);
        wp_enqueue_script('cdnv-seo-assistant', $base . 'js/seo-assistant.js', [], VERSION, true);
        wp_add_inline_script('cdnv-seo-assistant', 'window.cdnvSeoConfig = ' . wp_json_encode([
            'restUrl' => esc_url_raw(rest_url(RestController::NAMESPACE . '/seo/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'hasSeoPlugin' => self::has_seo_plugin(),
            'i18n' => [
                'working' => __('Asking NVIDIA… this can take up to a minute.', 'chandan-digital-ai-for-nvidia'),
                'imageWorking' => __('Writing an image brief, then generating the image… this can take a minute or two.', 'chandan-digital-ai-for-nvidia'),
                'failed' => __('The request failed. Check your connection and try again.', 'chandan-digital-ai-for-nvidia'),
                'emptyContent' => __('Add a title or some content first.', 'chandan-digital-ai-for-nvidia'),
                'usePostTitle' => __('Use as post title', 'chandan-digital-ai-for-nvidia'),
                'useSeoTitle' => __('Use as SEO title', 'chandan-digital-ai-for-nvidia'),
                'useDescription' => __('Use as meta description', 'chandan-digital-ai-for-nvidia'),
                'copy' => __('Copy', 'chandan-digital-ai-for-nvidia'),
                'copied' => __('Copied', 'chandan-digital-ai-for-nvidia'),
                'saved' => __('Saved', 'chandan-digital-ai-for-nvidia'),
                'applied' => __('Done. Remember to save or update the post.', 'chandan-digital-ai-for-nvidia'),
                /* translators: %d: number of characters. */
                'chars' => __('%d characters', 'chandan-digital-ai-for-nvidia'),
                /* translators: %d: score out of 100. */
                'score' => __('SEO score (a guide, not a Google ranking): %d/100', 'chandan-digital-ai-for-nvidia'),
                'strengths' => __('What is already good', 'chandan-digital-ai-for-nvidia'),
                'issues' => __('What to fix', 'chandan-digital-ai-for-nvidia'),
                'replaceContent' => __('Replace post content', 'chandan-digital-ai-for-nvidia'),
                'copyHtml' => __('Copy HTML', 'chandan-digital-ai-for-nvidia'),
                'confirmReplace' => __('Replace the content in the editor with this version? You can undo with Ctrl+Z, and WordPress keeps a revision when you save.', 'chandan-digital-ai-for-nvidia'),
                'linkReason' => __('Why', 'chandan-digital-ai-for-nvidia'),
                'copyLink' => __('Copy link HTML', 'chandan-digital-ai-for-nvidia'),
                'noLinks' => __('No suitable internal links were found for this content.', 'chandan-digital-ai-for-nvidia'),
                'setFeatured' => __('Set as featured image', 'chandan-digital-ai-for-nvidia'),
                'featuredSet' => __('Featured image set.', 'chandan-digital-ai-for-nvidia'),
                'altText' => __('Alt text', 'chandan-digital-ai-for-nvidia'),
                'promptUsed' => __('Prompt used', 'chandan-digital-ai-for-nvidia'),
                'keywordSaved' => __('Focus keyword saved.', 'chandan-digital-ai-for-nvidia'),
            ],
        ]) . ';', 'before');
    }

    /**
     * Adds a small "SEO Assistant" panel to the block editor's Post sidebar.
     *
     * In WordPress 6.6+ meta boxes sit in a pane at the bottom of the editor that starts collapsed,
     * so many people never see the assistant. The panel's button opens that pane and scrolls to it.
     */
    public static function sidebar_assets(): void
    {
        if (!self::user_can_use()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'post' || !in_array((string) $screen->post_type, self::post_types(), true)) {
            return;
        }
        wp_enqueue_script(
            'cdnv-seo-sidebar',
            plugin_dir_url(PLUGIN_FILE) . 'assets/js/seo-sidebar.js',
            ['wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-editor'],
            VERSION,
            true
        );
        wp_add_inline_script('cdnv-seo-sidebar', 'window.cdnvSeoSidebar = ' . wp_json_encode([
            'title' => __('SEO Assistant (NVIDIA AI)', 'chandan-digital-ai-for-nvidia'),
            'text' => __('SEO titles, meta description, SEO check, content improvement, internal links and featured image.', 'chandan-digital-ai-for-nvidia'),
            'button' => __('Open SEO Assistant', 'chandan-digital-ai-for-nvidia'),
        ]) . ';', 'before');
    }

    /**
     * Registers REST routes.
     */
    public static function register_routes(): void
    {
        $permission = static function (WP_REST_Request $request): bool {
            $postId = (int) $request->get_param('post_id');
            return self::user_can_use() && $postId > 0 && current_user_can('edit_post', $postId);
        };
        register_rest_route(RestController::NAMESPACE, '/seo/run', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'run'],
            'permission_callback' => $permission,
            'args' => [
                'post_id' => ['type' => 'integer', 'required' => true],
                'task' => ['type' => 'string', 'required' => true, 'enum' => self::TASKS],
                'keyword' => ['type' => 'string', 'default' => ''],
                'model' => ['type' => 'string', 'default' => ''],
                'title' => ['type' => 'string', 'default' => ''],
                'content' => ['type' => 'string', 'default' => ''],
            ],
        ]);
        register_rest_route(RestController::NAMESPACE, '/seo/apply', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'apply'],
            'permission_callback' => $permission,
            'args' => [
                'post_id' => ['type' => 'integer', 'required' => true],
                'field' => ['type' => 'string', 'required' => true, 'enum' => ['title', 'description', 'keyword']],
                'value' => ['type' => 'string', 'required' => true],
            ],
        ]);
        register_rest_route(RestController::NAMESPACE, '/seo/featured', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'set_featured'],
            'permission_callback' => $permission,
            'args' => [
                'post_id' => ['type' => 'integer', 'required' => true],
                'attachment_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);
    }

    /**
     * Runs one SEO task.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function run(WP_REST_Request $request): WP_REST_Response
    {
        $task = (string) $request->get_param('task');
        $postId = (int) $request->get_param('post_id');
        if ($task === 'image' && !current_user_can('upload_files')) {
            return self::error(new ApiError('invalid_input', 0, '', null, __('You need permission to upload files to create images.', 'chandan-digital-ai-for-nvidia')), 403);
        }

        $model = ModelRegistry::get((string) $request->get_param('model'));
        if ($model === null || !$model['enabled'] || $model['kind'] === 'image') {
            $model = ModelRegistry::get(self::default_model());
        }
        if ($model === null) {
            return self::error(new ApiError('invalid_input', 0, '', null, __('No chat model is enabled. Enable one on the AI Models tab.', 'chandan-digital-ai-for-nvidia')), 400);
        }

        $title = trim(wp_strip_all_tags((string) $request->get_param('title')));
        $html = (string) $request->get_param('content');
        if (strlen($html) > self::MAX_CONTENT_CHARS * 2) {
            return self::error(new ApiError('invalid_input', 0, '', null, __('This post is too long for the SEO Assistant. Try it on a shorter post.', 'chandan-digital-ai-for-nvidia')), 400);
        }
        $plain = self::plain_text($html);
        if ($title === '' && $plain === '') {
            return self::error(new ApiError('invalid_input', 0, '', null, __('Add a title or some content first.', 'chandan-digital-ai-for-nvidia')), 400);
        }
        $keyword = substr(sanitize_text_field((string) $request->get_param('keyword')), 0, 100);

        $client = NvidiaClient::create();
        if ($client instanceof ApiError) {
            return self::error($client, 400);
        }

        $candidates = [];
        if ($task === 'links') {
            $candidates = self::link_candidates($postId, $title, $keyword);
            if (!$candidates) {
                return self::error(new ApiError('invalid_input', 0, '', null, __('There are no other published posts or pages to link to yet.', 'chandan-digital-ai-for-nvidia')), 400);
            }
        }

        $messages = [
            ['role' => 'system', 'content' => self::system_prompt($task)],
            ['role' => 'user', 'content' => self::user_prompt($task, $title, $task === 'improve' ? $html : $plain, $keyword, $candidates)],
        ];
        $params = PayloadBuilder::generation_params($model, ModelRegistry::settings($model['id']));
        if ($params instanceof ApiError) {
            return self::error($params, 400);
        }
        // Long rewrites need room; reasoning models also spend tokens thinking first.
        $minimum = $task === 'improve' ? 8192 : 2048;
        $params['max_tokens'] = max((int) ($params['max_tokens'] ?? 0), $minimum);

        $result = $client->chat(array_merge(['model' => $model['id'], 'messages' => $messages], $params));
        RestController::record_model_status($model['id'], $result['status'], $result['error']);
        Logger::log([
            'type' => 'seo_' . $task,
            'model' => $model['id'],
            'status' => $result['status'],
            'duration_ms' => $result['duration_ms'],
            'error_code' => $result['error'] !== null ? $result['error']->code : '',
            'error' => $result['error'] !== null ? $result['error']->summary() : '',
        ]);
        if ($result['error'] !== null) {
            return self::error($result['error'], 502);
        }
        if ($result['finish_reason'] === 'length' && $task !== 'improve') {
            return self::error(new ApiError('token_limit', 200, '', null, __('The model ran out of output tokens before finishing. Raise "Maximum output tokens" for this model, or choose a model that thinks less.', 'chandan-digital-ai-for-nvidia')), 502);
        }

        $parsed = self::parse($task, $result['content'], $plain, $html, $candidates);
        if ($parsed instanceof ApiError) {
            return self::error($parsed, 502);
        }

        if ($task === 'image') {
            $image = self::create_image($client, $postId, $parsed);
            if ($image instanceof ApiError) {
                return self::error($image, 502);
            }
            $parsed = array_merge($parsed, $image);
        }

        $parsed['model'] = $model['name'];
        if ($task === 'improve' && $result['finish_reason'] === 'length') {
            $parsed['warning'] = __('The model stopped at its output limit, so the end of this version may be missing. Raise "Maximum output tokens" for this model before using it.', 'chandan-digital-ai-for-nvidia');
        }
        return new WP_REST_Response(array_merge(['ok' => true, 'task' => $task], $parsed), 200);
    }

    /**
     * Saves an SEO field (Chandan Digital SEO) or the focus keyword.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function apply(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request->get_param('post_id');
        $field = (string) $request->get_param('field');
        $value = (string) $request->get_param('value');
        $value = $field === 'description' ? sanitize_textarea_field($value) : sanitize_text_field($value);
        $value = function_exists('mb_substr') ? mb_substr($value, 0, $field === 'description' ? 320 : 200) : substr($value, 0, 320);

        if ($field === 'keyword') {
            update_post_meta($postId, self::KEYWORD_META, $value);
        }
        if (!self::has_seo_plugin()) {
            if ($field === 'keyword') {
                return new WP_REST_Response(['ok' => true, 'saved' => ['keyword']], 200);
            }
            return self::error(new ApiError('invalid_input', 0, '', null, __('Chandan Digital SEO is not active, so this cannot be saved automatically. Copy it into your SEO plugin instead.', 'chandan-digital-ai-for-nvidia')), 400);
        }
        if ($value === '') {
            delete_post_meta($postId, self::SEOM_FIELDS[$field]);
        } else {
            update_post_meta($postId, self::SEOM_FIELDS[$field], $value);
        }
        return new WP_REST_Response(['ok' => true, 'saved' => [$field], 'value' => $value], 200);
    }

    /**
     * Sets a generated image as the featured image.
     *
     * @param WP_REST_Request $request Request.
     */
    public static function set_featured(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request->get_param('post_id');
        $attachmentId = (int) $request->get_param('attachment_id');
        if (!wp_attachment_is_image($attachmentId) || !current_user_can('edit_post', $attachmentId)) {
            return self::error(new ApiError('invalid_input', 0, '', null, __('That image could not be used.', 'chandan-digital-ai-for-nvidia')), 400);
        }
        set_post_thumbnail($postId, $attachmentId);
        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * The model to preselect: the SEO Assistant setting, else the dashboard default model.
     */
    public static function default_model(): string
    {
        $preferred = (string) Settings::get('seo_model');
        $model = $preferred !== '' ? ModelRegistry::get($preferred) : null;
        if ($model !== null && $model['enabled'] && $model['kind'] !== 'image') {
            return $preferred;
        }
        $models = ModelRegistry::enabled_chat_models();
        return (string) (array_key_first($models) ?? '');
    }

    /**
     * The focus keyword stored for a post (Chandan Digital SEO, this plugin, Yoast or Rank Math).
     *
     * @param int $postId Post ID.
     */
    public static function stored_keyword(int $postId): string
    {
        foreach (['_seom_keyword', self::KEYWORD_META, '_yoast_wpseo_focuskw', 'rank_math_focus_keyword'] as $key) {
            $value = get_post_meta($postId, $key, true);
            if (is_string($value) && trim($value) !== '') {
                return trim(explode(',', $value)[0]);
            }
        }
        return '';
    }

    /**
     * System prompt for a task: role, writing style, SEO skills and the output contract.
     *
     * @param string $task Task.
     */
    private static function system_prompt(string $task): string
    {
        $skills = [
            'titles' => ['seo-page'],
            'meta' => ['seo-page'],
            'audit' => ['seo-page', 'seo-content', 'seo-geo'],
            'improve' => ['seo-page', 'seo-content', 'seo-geo'],
            'links' => ['seo-links'],
            'image' => ['seo-images'],
        ];
        $contracts = [
            'titles' => 'TASK: SEO_TITLES. Write 5 different SEO title options for this page, each 50 to 60 characters, with the focus keyword near the start when one is given. Return JSON only, exactly in this shape: {"titles": ["...", "...", "...", "...", "..."]}',
            'meta' => 'TASK: META_DESCRIPTION. Write 3 different meta description options for this page, each 150 to 160 characters, including the focus keyword naturally when one is given. Return JSON only, exactly in this shape: {"descriptions": ["...", "...", "..."]}',
            'audit' => 'TASK: SEO_AUDIT. Review this page against the SEO skills. Base every point on the title and content given; never invent facts about the site. Return JSON only, exactly in this shape: {"score": 0-100, "summary": "two or three plain sentences", "strengths": ["..."], "issues": [{"priority": "high|medium|low", "issue": "what is wrong", "fix": "exactly what to change"}]}. List the most important issues first, at most 10. The score is a heuristic guide, not a Google ranking.',
            'improve' => 'TASK: IMPROVE_CONTENT. Rewrite the content so it serves readers and search better, following the SEO skills. Keep every fact, figure, name, link and image from the original, keep its language, and do not invent experience, data, prices or quotes; where proof is missing, leave the claim out. Use the focus keyword naturally. Return only the full improved content as clean HTML using the tags p, h2, h3, h4, ul, ol, li, strong, em, a, blockquote and img. No h1, no Markdown, no code fences, no notes before or after the HTML.',
            'links' => 'TASK: INTERNAL_LINKS. Suggest up to 8 internal links from this page to the candidate pages. Each anchor must be an exact phrase that already appears in the content. Use only candidate URLs. Return JSON only, exactly in this shape: {"links": [{"anchor": "exact phrase from the content", "url": "one candidate url", "reason": "one short sentence"}]}',
            'image' => 'TASK: IMAGE_BRIEF. Plan one featured image for this page. Return JSON only, exactly in this shape: {"prompt": "a detailed prompt for an image model, in English, under 600 characters", "alt": "alt text under 125 characters", "filename": "short-descriptive-file-name"}',
        ];
        $parts = [sprintf(
            'You are an experienced SEO editor working inside WordPress for the website "%s" (%s). Write in the same language as the page content.',
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            home_url('/')
        )];
        if (Settings::get('editorial_policy') && in_array($task, ['titles', 'meta', 'improve', 'audit'], true)) {
            $parts[] = IndianEnglishPolicy::instruction();
        }
        $parts[] = SeoSkills::combined($skills[$task]);
        $parts[] = $contracts[$task];
        return implode("\n\n", $parts);
    }

    /**
     * User message for a task.
     *
     * @param string $task Task.
     * @param string $title Post title.
     * @param string $content Plain text (or HTML for "improve").
     * @param string $keyword Focus keyword.
     * @param list<array<string, string>> $candidates Link candidates.
     */
    private static function user_prompt(string $task, string $title, string $content, string $keyword, array $candidates): string
    {
        $content = function_exists('mb_substr') ? mb_substr($content, 0, self::MAX_CONTENT_CHARS) : substr($content, 0, self::MAX_CONTENT_CHARS);
        $text = 'Focus keyword: ' . ($keyword !== '' ? $keyword : '(none given; infer the main topic)') . "\n";
        $text .= 'Page title: ' . ($title !== '' ? $title : '(no title yet)') . "\n\n";
        $text .= ($task === 'improve' ? "Page content (HTML):\n" : "Page content:\n") . ($content !== '' ? $content : '(no content yet)');
        if ($candidates) {
            $text .= "\n\nCandidate pages on this site (JSON):\n" . wp_json_encode(array_map(static function (array $c): array {
                return ['title' => $c['title'], 'url' => $c['url'], 'summary' => $c['summary']];
            }, $candidates), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $text;
    }

    /**
     * Validates and cleans a model reply for a task.
     *
     * @param string $task Task.
     * @param string $reply Model reply.
     * @param string $plain Plain-text content.
     * @param string $html Original HTML content.
     * @param list<array<string, string>> $candidates Link candidates.
     * @return array<string, mixed>|ApiError
     */
    private static function parse(string $task, string $reply, string $plain, string $html, array $candidates)
    {
        if ($task === 'improve') {
            $clean = trim((string) preg_replace('/\A```(?:html)?\s*|\s*```\z/i', '', trim($reply)));
            $clean = wp_kses_post($clean);
            if (trim(wp_strip_all_tags($clean)) === '') {
                return self::bad_output();
            }
            return ['html' => $clean, 'words_before' => str_word_count($plain), 'words_after' => str_word_count(wp_strip_all_tags($clean))];
        }

        $data = self::decode_json($reply);
        if ($data === null) {
            return self::bad_output();
        }

        switch ($task) {
            case 'titles':
            case 'meta':
                $key = $task === 'titles' ? 'titles' : 'descriptions';
                $items = [];
                foreach ((array) ($data[$key] ?? []) as $item) {
                    $text = trim(wp_strip_all_tags(is_string($item) ? $item : ''));
                    if ($text !== '' && !in_array($text, array_column($items, 'text'), true)) {
                        $items[] = ['text' => $text, 'chars' => function_exists('mb_strlen') ? mb_strlen($text) : strlen($text)];
                    }
                }
                return $items ? ['items' => array_slice($items, 0, 8)] : self::bad_output();

            case 'audit':
                $issues = [];
                foreach ((array) ($data['issues'] ?? []) as $issue) {
                    if (!is_array($issue) || empty($issue['issue'])) {
                        continue;
                    }
                    $priority = in_array($issue['priority'] ?? '', ['high', 'medium', 'low'], true) ? $issue['priority'] : 'medium';
                    $issues[] = [
                        'priority' => $priority,
                        'issue' => wp_strip_all_tags((string) $issue['issue']),
                        'fix' => wp_strip_all_tags((string) ($issue['fix'] ?? '')),
                    ];
                }
                $strengths = [];
                foreach ((array) ($data['strengths'] ?? []) as $strength) {
                    if (is_string($strength) && trim($strength) !== '') {
                        $strengths[] = wp_strip_all_tags($strength);
                    }
                }
                return [
                    'score' => max(0, min(100, (int) ($data['score'] ?? 0))),
                    'summary' => wp_strip_all_tags((string) ($data['summary'] ?? '')),
                    'strengths' => array_slice($strengths, 0, 10),
                    'issues' => array_slice($issues, 0, 12),
                ];

            case 'links':
                $byUrl = [];
                foreach ($candidates as $candidate) {
                    $byUrl[untrailingslashit($candidate['url'])] = $candidate;
                }
                $links = [];
                foreach ((array) ($data['links'] ?? []) as $link) {
                    if (!is_array($link)) {
                        continue;
                    }
                    $url = untrailingslashit((string) ($link['url'] ?? ''));
                    $anchor = trim(wp_strip_all_tags((string) ($link['anchor'] ?? '')));
                    // Only real candidate pages, anchors that exist in the text, and pages not already linked.
                    if (!isset($byUrl[$url]) || $anchor === '' || strlen($anchor) > 120 || isset($links[$url])) {
                        continue;
                    }
                    if ((function_exists('mb_stripos') ? mb_stripos($plain, $anchor) : stripos($plain, $anchor)) === false) {
                        continue;
                    }
                    if (strpos($html, $byUrl[$url]['url']) !== false) {
                        continue;
                    }
                    $links[$url] = [
                        'anchor' => $anchor,
                        'url' => $byUrl[$url]['url'],
                        'title' => $byUrl[$url]['title'],
                        'reason' => wp_strip_all_tags((string) ($link['reason'] ?? '')),
                    ];
                }
                return ['items' => array_values($links)];

            case 'image':
                $prompt = trim(wp_strip_all_tags((string) ($data['prompt'] ?? '')));
                if ($prompt === '') {
                    return self::bad_output();
                }
                $alt = trim(wp_strip_all_tags((string) ($data['alt'] ?? '')));
                $filename = sanitize_title((string) ($data['filename'] ?? ''));
                return [
                    'prompt' => function_exists('mb_substr') ? mb_substr($prompt, 0, 1000) : substr($prompt, 0, 1000),
                    'alt' => function_exists('mb_substr') ? mb_substr($alt, 0, 150) : substr($alt, 0, 150),
                    'filename' => $filename !== '' ? substr($filename, 0, 80) : 'featured-image',
                ];
        }
        return self::bad_output();
    }

    /**
     * Generates the featured image with FLUX and adds it to the Media Library.
     *
     * @param NvidiaClient $client Client.
     * @param int $postId Post ID.
     * @param array<string, string> $brief Prompt, alt text and file name.
     * @return array<string, mixed>|ApiError
     */
    private static function create_image(NvidiaClient $client, int $postId, array $brief)
    {
        $modelId = (string) Settings::get('seo_image_model');
        $model = ModelRegistry::get($modelId);
        if ($model === null || $model['kind'] !== 'image' || !$model['enabled']) {
            $modelId = 'black-forest-labs/flux.1-schnell';
        }
        $image = $client->generate_image($modelId, $brief['prompt']);
        Logger::log([
            'type' => 'seo_image_generation',
            'model' => $modelId,
            'status' => $image['status'],
            'duration_ms' => $image['duration_ms'],
            'error_code' => $image['error'] !== null ? $image['error']->code : '',
            'error' => $image['error'] !== null ? $image['error']->summary() : '',
        ]);
        if ($image['error'] !== null) {
            return $image['error'];
        }

        $info = getimagesizefromstring($image['bytes']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!isset($extensions[$mime])) {
            return new ApiError('malformed_response', 200, 'Unsupported image type returned.');
        }
        $upload = wp_upload_bits($brief['filename'] . '.' . $extensions[$mime], null, $image['bytes']);
        if (!empty($upload['error'])) {
            return new ApiError('invalid_request', 0, '', null, sprintf(
                /* translators: %s: upload error. */
                __('The image was created but could not be saved: %s', 'chandan-digital-ai-for-nvidia'),
                (string) $upload['error']
            ));
        }
        $title = $brief['alt'] !== '' ? $brief['alt'] : ucwords(str_replace('-', ' ', $brief['filename']));
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $mime,
            'post_title' => $title,
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file'], $postId);
        if (is_wp_error($attachmentId) || !$attachmentId) {
            return new ApiError('invalid_request', 0, '', null, __('The image was created but could not be added to the Media Library.', 'chandan-digital-ai-for-nvidia'));
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $upload['file']));
        update_post_meta($attachmentId, '_wp_attachment_image_alt', $brief['alt']);

        return [
            'attachment_id' => (int) $attachmentId,
            'url' => (string) wp_get_attachment_image_url($attachmentId, 'medium_large'),
            'edit_url' => (string) get_edit_post_link($attachmentId, 'raw'),
            'image_model' => $modelId,
        ];
    }

    /**
     * Real published pages the post could link to.
     *
     * @param int $postId Current post.
     * @param string $title Current title.
     * @param string $keyword Focus keyword.
     * @return list<array{id: int, title: string, url: string, summary: string}>
     */
    private static function link_candidates(int $postId, string $title, string $keyword): array
    {
        $types = array_values(array_intersect(['post', 'page'], self::post_types()));
        if (!$types) {
            return [];
        }
        $base = [
            'post_type' => $types,
            'post_status' => 'publish',
            'post__not_in' => [$postId],
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => false,
        ];
        $searches = [];
        if ($keyword !== '') {
            $searches[] = $keyword;
        }
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $title) ?: [], static function (string $word): bool {
            return (function_exists('mb_strlen') ? mb_strlen($word) : strlen($word)) > 4;
        });
        usort($words, static function (string $a, string $b): int {
            return strlen($b) - strlen($a);
        });
        foreach (array_slice(array_values($words), 0, 3) as $word) {
            $searches[] = $word;
        }

        $ids = [];
        foreach ($searches as $search) {
            $ids = array_merge($ids, get_posts($base + ['s' => $search, 'posts_per_page' => 10]));
        }
        $categories = wp_get_post_categories($postId);
        if ($categories) {
            $ids = array_merge($ids, get_posts($base + ['category__in' => $categories, 'posts_per_page' => 10]));
        }
        $ids = array_merge($ids, get_posts($base + ['posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC']));

        $candidates = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $post = get_post($id);
            if (!$post) {
                continue;
            }
            $summary = $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content;
            $candidates[] = [
                'id' => $id,
                'title' => wp_strip_all_tags(get_the_title($post)),
                'url' => (string) get_permalink($post),
                'summary' => wp_trim_words(wp_strip_all_tags(strip_shortcodes($summary)), 25, '…'),
            ];
            if (count($candidates) >= 30) {
                break;
            }
        }
        return $candidates;
    }

    /**
     * Plain text from editor HTML, without block comments or shortcodes.
     *
     * @param string $html HTML.
     */
    private static function plain_text(string $html): string
    {
        $text = (string) preg_replace('/<!--.*?-->/s', '', $html);
        $text = wp_strip_all_tags(strip_shortcodes($text));
        return trim((string) preg_replace('/[ \t]+/', ' ', html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
    }

    /**
     * Reads a JSON object from a reply, accepting code fences, leading reasoning or a short note.
     *
     * @param string $reply Model reply.
     * @return array<string, mixed>|null
     */
    private static function decode_json(string $reply): ?array
    {
        try {
            $data = json_decode(JsonOutput::normalize($reply), true);
            if (is_array($data)) {
                return $data;
            }
        } catch (\Throwable $e) {
            // Fall through to the outermost object below.
        }
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($reply, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }

    private static function bad_output(): ApiError
    {
        return new ApiError('malformed_response', 200, '', null, __('The model did not reply in the expected format. Try again, or choose another model in the SEO Assistant.', 'chandan-digital-ai-for-nvidia'));
    }

    /**
     * @param ApiError $error Error.
     * @param int $status HTTP status.
     */
    private static function error(ApiError $error, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'error' => $error->to_array()], $status);
    }
}
