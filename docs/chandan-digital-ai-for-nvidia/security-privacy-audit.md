# Security and Privacy Audit

**Plugin:** Chandan Digital AI for NVIDIA 1.2.1
**Compared with:** AI Provider for NVIDIA 1.0.2 (the uploaded ZIP)

## 1. Review of the original plugin

Every file in the original ZIP was read line by line. The original contained 13 files: one main file, an autoloader, six classes, an uninstall file, a readme, a logo, a translation template and the licence.

| Check | Finding |
|---|---|
| Telemetry, usage or installation tracking | **None found.** |
| Marketing analytics, tracking pixels, external scripts | **None found.** |
| Vendor version checks or self-updater | **None found.** It did not hook into WordPress updates. |
| Remote code loading, `eval`, hidden downloads | **None found.** |
| Webhooks or remote-control features | **None found.** |
| Scheduled (cron) jobs | **None.** |
| Outgoing connections | Only `integrate.api.nvidia.com` (chat and model list) and `ai.api.nvidia.com` (FLUX images). Both are needed for the plugin to work. |
| Vendor URLs | `aiacfpro.online` (Plugin URI) and `deepakbhojwani.online` (Author URI) appeared only as plugin header text. WordPress shows them as links; nothing was ever sent to them. |
| Stored data | One user-meta flag for a dismissed notice. |
| Known vulnerabilities | None found. The one AJAX action (dismissing a notice) checked a nonce and `manage_options`. |

**Conclusion:** the original was not doing anything hidden or harmful, so there was no tracking code to remove. The real risks for private use were:

1. **Update collision.** The public slug `ai-provider-for-nvidia` could be matched by WordPress.org, so a public update could replace your private changes.
2. **Vendor branding and links** in the plugin header.
3. **No key management, logging policy or access controls**, because the original had no settings screen.

These are addressed below.

## 2. Changes made

### Branding and update control

- New slug `chandan-digital-ai-for-nvidia`, new PHP namespace `ChandanDigital\NvidiaAi` and new text domain, so the plugin cannot clash with the public plugin's files or classes.
- `Update URI: https://chandandigital.com/private-plugins/chandan-digital-ai-for-nvidia/` in the header. Because this does not point to WordPress.org, WordPress.org will not offer updates for this plugin. The plugin adds no update checker of its own.
- WordPress auto-updates are turned off **for this plugin only** (`auto_update_plugin` filter). The Plugins screen shows "Manual updates only (upload a new ZIP)". WordPress core and other plugins are not touched.
- Vendor URIs replaced with `https://chandandigital.com/`. The original author is credited in the plugin header and readme, as the GPL licence requires. The `LICENSE` file is unchanged.

### API key handling

| Requirement | How it is met |
|---|---|
| Never hardcode keys | No key exists in any source file. A scan of the code found no `nvapi-` value apart from placeholders and masking code. |
| Never expose keys to JavaScript | The key is only added to the Authorization header on the server. Browser tests confirmed it never appears in page HTML or in the JavaScript config. |
| Only administrators configure | Every settings form checks `manage_options` and a nonce. Tested: an Editor posting to the settings handler gets HTTP 403. |
| Encrypted storage | Keys saved on the settings screen are encrypted with libsodium `secretbox` (bundled with WordPress), using a key derived from the `AUTH_KEY`/`SECURE_AUTH_KEY` salts in wp-config.php. The option is not autoloaded. |
| wp-config.php option | `define( 'CHANDAN_NVIDIA_API_KEY', '...' );` takes priority and is never written to the database. |
| Masked display | Only `nvapi-••••••••` plus the last four characters are shown. The input field is always empty after saving. |
| No secrets in errors | Upstream messages are shortened and scrubbed of `nvapi-` keys, Bearer tokens and the active key before they are shown or logged. |
| Not overwritten on update | Activation uses `add_option()`, which never replaces existing values. A failed or invalid key entry leaves the old key in place (tested). |
| Key versus model errors | 401 is reported as a key problem, 403/404 as model-access problems. The connection test shows these as separate steps. |
| Sharing with other plugins | By default the key is given to the WordPress AI Client only when WordPress has no NVIDIA key of its own. |
| Sync with Settings > Connectors (1.2.1) | A key saved in Connectors is copied into this plugin (encrypted). A key saved here is written to Connectors **only if a key is already kept there**, so the two never drift apart. WordPress stores Connectors keys as plain text, so if you want the key out of the database entirely, use the `CHANDAN_NVIDIA_API_KEY` constant. When NVIDIA rejects a key, the request is tried once with the other saved key; keys are never logged or sent anywhere except NVIDIA. |

### Endpoints and permissions

| Endpoint | Who can use it | Protection |
|---|---|---|
| `POST /chandan-digital-ai/v1/connection-test` | Administrators | Capability check + REST nonce |
| `POST /chandan-digital-ai/v1/models/verify` | Administrators | Capability check + REST nonce |
| `POST /chandan-digital-ai/v1/models/refresh` | Administrators | Capability check + REST nonce |
| `POST /chandan-digital-ai/v1/stream-test` | Administrators | Capability check + REST nonce; does not contact NVIDIA |
| `POST /chandan-digital-ai/v1/chat` and `/chat/stream` | Administrators (Editors only if you allow it) | Capability check + REST nonce + one-time request ID; image uploads also need `upload_files` |
| `POST /chandan-digital-ai/v1/seo/run` and `/seo/apply` (1.2.0) | Administrators and Editors by default (setting: Administrators only, or also Authors) | Capability check + REST nonce + `edit_post` on that exact post; refused when the SEO Assistant is switched off |
| `POST /chandan-digital-ai/v1/seo/featured` and the "image" task (1.2.0) | Same as above | Also needs `upload_files` |
| `admin-post.php` actions `cdnv_*` | Administrators | `manage_options` + nonce |
| `wp_ajax_cdnv_dismiss_notice` | Administrators | `manage_options` + nonce |

Tested: logged-out requests get HTTP 401, a logged-in request without a nonce is refused, and an Editor gets HTTP 403 on administrator routes. For the SEO routes, logged-out users and Authors (by default) are refused, Editors are allowed, and every route is refused when the SEO Assistant is off.

### Images and server-side request forgery (SSRF)

- **Playground uploads** are decoded and checked in memory only. They are never written to disk or the Media Library. The real file type is read from the bytes (`getimagesizefromstring`), so a script renamed as `.png` is rejected. Size, format, pixel dimensions and count are all limited.
- **Image URLs are never downloaded by WordPress.** NVIDIA downloads them. They are still checked: `https://` only, standard port only, no usernames or passwords in the URL, no `localhost` or `.local` names, no private or reserved IP addresses, and `wp_http_validate_url()` for hostnames that resolve to private networks. Media on your own site that belongs to unpublished content is refused, and the user must be able to read the attachment.
- In the browser, remote image URLs are shown as text, not loaded, so the administrator's IP address is not sent to that host.
- The Playground and the Privacy tab both state that images are sent to NVIDIA.
- **SEO Assistant images (1.2.0)** are the only files this plugin writes. They come from NVIDIA's FLUX endpoint as base64, are decoded, and must pass `getimagesizefromstring` as JPEG, PNG, WebP or GIF; the file extension comes from the detected type, not from the model. The file name suggested by the model is cleaned with `sanitize_title` (lowercase, dashes, no folders or extensions). The file is saved with `wp_upload_bits` and registered as a normal Media Library attachment attached to the post.

### Output safety

- All model output is inserted with `textContent`, never as HTML, so a reply cannot inject markup or scripts.
- The one exception is the SEO Assistant's "Improve content" preview, which must show HTML. That HTML is cleaned on the server with `wp_kses_post` (the same filter WordPress uses for post content from Authors) before it reaches the browser. Tests confirm `<script>` tags and `onclick` attributes are removed.
- Internal link suggestions are kept only when the URL is one of the real published posts or pages the plugin gave the model, so a model cannot slip an outside link into a post.
- Nothing from the SEO Assistant changes a post until the user presses a "Use" or "Replace" button.
- All PHP output in the admin views is escaped. The WordPress coding-standard security sniffs (escaping, nonce checks, input sanitising, SQL) report no issues in the new code. One warning remains on an exception message in an unchanged original file; it is never sent to the browser.
- The only direct SQL query (in `uninstall.php`) uses a fixed string with no user input.

### Outgoing request hygiene

- Redirects are not followed, so the API key cannot be forwarded to another host.
- A plain user agent (`ChandanDigitalAIforNVIDIA/` plus the version number) is used. WordPress's default would also send your site's address.
- Requests go through the WordPress HTTP API, so proxy settings, SSL certificates and `WP_HTTP_BLOCK_EXTERNAL` still apply.
- A base URL that is not on `nvidia.com` shows a warning, because the key is sent there. Only `https://` base URLs are accepted.

### WordPress AI plugin bridge (1.2.0)

- The plugin **reads** the WordPress AI plugin's Connector Approval options (`wpai_feature_connector-approval_enabled`, `wpai_connector_approvals`, `wpai_connector_approval_pending`) to explain 403 errors. It never writes them, so it cannot approve itself or anyone else. Approving stays an administrator's decision on Tools > Connector Approvals.
- It uses the AI plugin's documented filters to suggest NVIDIA models and to report that a key exists. It does not pass the key itself to the AI plugin; the AI Client gets it the same way as before (section "API key handling").
- The admin notice about blocked requests is shown only to administrators.

## 3. External dependencies

| Item | Status |
|---|---|
| Composer or npm packages shipped in the ZIP | None |
| External JavaScript, CSS or fonts in the dashboard | None. Browser tests recorded no requests from the plugin screens to other hosts (WordPress core's own Gravatar avatars in the admin bar were excluded) |
| WordPress AI Client | Supplied by WordPress 7.0+; optional for the dashboard |
| Third-party content | SEO rules adapted from claude-seo (MIT License, credited in `CREDITS.md`). Only text instructions were adapted; no code or scripts from that project are included |
| PHP extensions | `json` and `sodium` (or WordPress's bundled sodium_compat); `curl` recommended for live streaming and Stop; `gd` or similar for `getimagesizefromstring` |

## 4. Privacy

- **Data sent to NVIDIA:** the API key, model ID, messages, any images you add and generation settings. The SEO Assistant also sends the post's title, content, focus keyword and, for internal links, the titles, addresses and short summaries of up to 30 published posts and pages on your site. Nothing is sent on page load, and nothing goes to Chandan Digital or the original developer.
- **Local logs** are off by default. When on, they record time, request type, model ID, HTTP status, duration and a cleaned error. Prompts and responses are recorded only if you separately turn on content logging, and are cut to 2,000 characters. Image data, keys and headers are never logged. Retention runs from 1 to 90 days (default 7), with at most 500 entries and a **Clear logs** button. Turning logging off deletes existing entries.
- **Conversations** in the Playground live only in the browser tab.
- **Uninstall:** logs, cached catalogue, transients, scheduled events and per-user flags are always removed. The API key and settings are removed only if "Also delete the saved API key and all settings" is ticked. A manual ZIP update never deletes anything.

## 5. Remaining risks and recommendations

1. **Stored key protection depends on wp-config.php.** Someone with both your database and your wp-config.php can decrypt the saved key, as with any reversible storage. Using the `CHANDAN_NVIDIA_API_KEY` constant keeps the key out of the database.
2. **Changing WordPress salts** makes the saved key unreadable. The settings screen detects this and asks you to enter the key again.
3. **Editor access** to the Playground lets Editors spend your NVIDIA credits. It is off by default.
4. **Custom base URL:** any host you enter receives your API key. Leave the default unless you run your own NVIDIA NIM.
5. **The key mentioned earlier in your conversation** should be treated as exposed. Revoke it on build.nvidia.com and create a new one.
6. **Writing style rules:** the 1.1.1 rules, and the em dash check for other plugins, are content rules, not security controls. Anyone with administrator access can edit the rules. They are sent to NVIDIA with each request, so do not put private business data in them.
7. **SEO Assistant access:** Editors can use it by default, and each request uses your NVIDIA credits. Choose "Administrators" on the SEO Assistant tab if you want to limit it.
8. **Model suggestions are suggestions.** Read improved content and links before you publish. The plugin removes unsafe HTML and invented links, but it cannot check facts.
9. **Not tested:** WordPress multisite, and hosts that run PHP without cURL.
