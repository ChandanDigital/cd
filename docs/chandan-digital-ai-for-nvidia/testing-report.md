# Testing Report

**Plugin:** Chandan Digital AI for NVIDIA 1.2.0
**Test date:** 8 October 2026

## Summary

| Test suite | Result |
|---|---|
| Integration tests inside WordPress (WP-CLI) | **196 passed, 0 failed** |
| Browser end-to-end tests (Chromium, Playwright) | **112 passed, 0 failed** |
| Lifecycle tests with the real ZIP | All steps behaved as expected (details below) |
| Editor 403 reproduced with the real WordPress AI plugin 1.4.0 | Cause confirmed, fix confirmed (details below) |
| PHP 7.4 to 8.4 compatibility scan (PHPCompatibility) | 0 errors |
| WordPress security sniffs (escaping, nonces, input, SQL, i18n) | 0 issues in new code; 1 warning on an unchanged original file (not browser output) |
| PHP warnings or notices from the plugin during all tests | 0 |
| **Live tests against NVIDIA's real API** | **Not performed** (see below) |

Full outputs: `tests/chandan-digital-ai-for-nvidia/final-integration.txt` and `final-e2e.txt`.

The security sniffs flag one line in `src/Content/JsonOutput.php`, a file carried over unchanged from the original plugin. It builds an exception message for PHP code, not browser output, so it was left as it is.

## Why live NVIDIA tests were not performed

1. This build environment's network policy blocked `integrate.api.nvidia.com` (the proxy refused the connection).
2. No NVIDIA API key was supplied for this work, and the key mentioned earlier in your conversation was deliberately not used.

So **no result in this report comes from NVIDIA's real servers.** Instead, a local mock server (`tests/chandan-digital-ai-for-nvidia/mock/router.php`) imitates NVIDIA's documented formats. It sends streams split at awkward points (inside JSON, between `\r` and `\n`, and two events in one write), uses `\r\n` line endings for one model, adds `<think>` tags, drops a connection mid-stream, and returns 401, 403, 404, 400, 429 (with Retry-After) and 500 errors. For 1.2.0 it also answers the SEO Assistant's tasks (including deliberately messy answers: JSON inside a code fence, a `<script>` tag, an invented URL and a link phrase that is not in the text) and returns a small JPEG for FLUX image requests. It also enforces Moonshot AI's documented Kimi K3 rules (`reasoning_effort` low/high/max, temperature 1), so the plugin's error paths could be checked.

Please follow section 4 of the API Configuration Guide on your site to confirm live behaviour with your key.

## Test environment

- WordPress 7.1.3 (bundles WordPress AI Client 1.3.1), SQLite database
- PHP 8.3.6 with cURL, PHP built-in web server (6 workers)
- Chromium (headless) through Playwright 1.48
- Plugin installed from the release ZIP for lifecycle tests
- WordPress AI plugin 1.4.0, built from its GitHub source (wordpress.org downloads were blocked here). Its JavaScript was not built, so a test-only must-use plugin defined `WPAI_IS_TEST` to skip its built-assets check. That flag only affects that check.
- Chandan Digital SEO (`seo-manager-pro`) active, for the SEO title, description and keyword fields

## Results against the requested checklist

### Model registration

| Check | Result |
|---|---|
| Kimi K3 appears in the model selector | **Pass** (AI Models table, Playground selector, Diagnostics selector) |
| Exact model ID `moonshotai/kimi-k3` is used | **Pass** (checked in requests from the Playground, the connection test and the WordPress AI Client) |
| Existing NVIDIA models remain available | **Pass** (all 48 original models registered; catalogue-listed ones offered to the AI Client as before) |
| Model selection persists after saving | **Pass** (default model and enabled/disabled state survive a page reload) |
| Disabled models disappear from the AI Client list immediately | **Pass** |
| Kimi K3 offered to the AI Client only after access is confirmed | **Pass** |
| A model that later returns 404 stops being offered | **Pass** |

### API authentication

| Check | Result |
|---|---|
| Valid key authenticates | **Pass (mock)** |
| Invalid key shows a clear key error, not a model error | **Pass (mock)**: 401 is reported as `invalid_api_key` |
| Key remains private | **Pass**: never in page HTML, JavaScript config, logs or error text; stored encrypted; option not autoloaded |
| Missing key handled safely | **Pass**: clear message, no request sent |
| Invalid key entry does not overwrite a good key | **Pass** |
| Plugin key shared with the AI Client without replacing an existing WordPress key | **Pass** (fallback and always modes tested) |
| Live NVIDIA authentication | **Not performed** |

### Text generation

| Check | Result |
|---|---|
| Simple text prompts | **Pass (mock)**: Playground streaming and non-streaming, and the AI Client |
| Multi-turn conversations | **Pass (mock)**: second turn sent with the first reply and its reasoning |
| Kimi K3 reasoning passed back | **Pass (mock)**: in the Playground and through the AI Client |
| Token settings respected | **Pass (mock)**: dashboard value used when unset; caller's value kept when set |
| Responses display correctly, including Hindi text split across chunks | **Pass** |
| Copy response | **Pass** |
| Live NVIDIA replies | **Not performed** |

### Image understanding

| Check | Result |
|---|---|
| Public image URL | **Pass (mock)**: passed to NVIDIA as `image_url`; not downloaded by WordPress |
| Uploaded image (PNG) | **Pass (mock)** |
| Text and image together | **Pass (mock)**: two images (one upload, one URL) in one message |
| AI Client image input to Kimi K3 | **Pass (mock)** |
| Unsupported formats return useful errors | **Pass**: GIF refused in the browser and on the server; SVG URL refused |
| Disguised file (PHP code with a PNG type) | **Pass**: rejected on the server |
| Oversized image | **Pass**: rejected |
| More images than allowed | **Pass**: oldest left out, with a visible notice |
| Private, loopback and local URLs (SSRF) | **Pass**: rejected, including when sent directly to the REST API |
| URLs with credentials or non-standard ports | **Pass**: rejected |
| Real image analysis by Kimi K3 | **Not performed** |

### Streaming

| Check | Result |
|---|---|
| Streaming responses render incrementally | **Pass**: browser showed text before the reply finished |
| SSE events parsed safely | **Pass**: split chunks, `\r\n`, multi-line data, comments, `[DONE]`, oversize guard |
| `<think>` tags split across chunks | **Pass** |
| Cancellation | **Pass**: Stop button and server-side abort both end the request |
| Interrupted connection | **Pass**: reported as incomplete; partial text kept but not used as context |
| Non-streaming fallback | **Pass**: non-streaming mode works, and the Playground switches automatically if a stream cannot start |
| Duplicate requests | **Pass**: a reused request ID gets HTTP 409 |
| Server streaming self-test | **Pass**: "events arrived one by one (first after 30 ms, last after 1630 ms)" on the test server |
| Streaming on your real host (nginx, Apache, CDN) | **Not performed**: use API Diagnostics > Test streaming |

### Reasoning

| Check | Result |
|---|---|
| Supported values (low, high, max) | **Pass**: saved and sent |
| Unsupported value (medium) for Kimi K3 | **Pass**: disabled in the form, refused on save, refused at request time, and refused before sending in the AI Client |
| Reasoning control disabled for models without verified support | **Pass** |
| NVIDIA rejection shows NVIDIA's own reason | **Pass (mock)** |
| Invalid values cause no fatal errors | **Pass** |
| Live acceptance of low/high on NVIDIA | **Not performed** |

### Error handling

| Check | Result |
|---|---|
| 401, 403, 404, 408, 413, 422, 429, 500, 502, 503, 504 classified correctly | **Pass** |
| 429 retried once after Retry-After, then succeeds | **Pass (mock)** |
| No retry when retries are set to 0 | **Pass** |
| Timeouts, network and TLS errors | **Pass** (classification) |
| Upstream messages scrubbed of keys | **Pass** |

### Security

| Check | Result |
|---|---|
| No exposed credentials | **Pass** |
| No unauthorised REST endpoints | **Pass**: logged-out requests get 401; no-nonce requests refused; Editors get 403 on admin routes |
| No unsafe privileged AJAX or form actions | **Pass**: Editor cannot save settings (403) |
| Editor Playground access only when allowed | **Pass** |
| No vendor telemetry | **Pass** (the original had none either; see the audit) |
| No hidden update downloader | **Pass**: no update code; `Update URI` set; auto-update off for this plugin only |
| No external requests from plugin screens | **Pass** (WordPress core's Gravatar avatars excluded) |
| No remote code execution path introduced | **Pass** by code review: no `eval`, no dynamic includes from input; Playground uploads never written to disk; SEO images saved only after an image-type check, with the extension taken from the detected type |

### The 403 error in posts and pages (added in 1.2.0)

The WordPress AI plugin (1.4.0) has a "Connector Approval" feature. When it is on, a guard inside WordPress stops every request to an AI connector until an administrator approves the plugin that is asking. The guard answers with a `wpai_connector_not_approved` error that carries HTTP status 403. The AI plugin's own editor buttons (title, excerpt, meta description, alt text, images) count as a plugin that needs approval, so they fail until "AI" is approved for NVIDIA.

This was reproduced on the test site with the AI plugin's real title-generation endpoint (`tests/chandan-digital-ai-for-nvidia/repro-connector-approval.php`):

| Situation | Result |
|---|---|
| Connector Approval off | **HTTP 200**, title returned |
| Connector Approval on, "AI" not approved for NVIDIA | **Refused** before reaching NVIDIA: "The "nvidia" AI connector has not been approved for use by "ai/ai.php"", and the request was added to the AI plugin's pending list |
| Connector Approval on, "AI" approved for NVIDIA | **HTTP 200**, title returned |

Depending on what WordPress already had cached, the AI plugin reported the refusal as a network error (503) or as "Please ensure you have a connected provider that supports text generation" (500). The browser shows the guard's 403 when the refusal reaches it directly. In every case the cause was the missing approval, and approving fixed it.

| Check | Result |
|---|---|
| Diagnostics "Editor AI check" names the blocked plugin, says why, and links to Tools > Connector Approvals | **Pass** |
| Overview shows a warning card while a block is recorded | **Pass** |
| Once approved, the plugin is no longer reported as blocked | **Pass** |
| A blocked request is reported as `connector_not_approved`, also when wrapped inside the AI Client's network error | **Pass** |
| The AI plugin receives NVIDIA text, vision and FLUX image models as preferred models | **Pass** |
| The AI plugin knows NVIDIA credentials exist when the key is saved in this plugin | **Pass** |
| This plugin never approves anything by itself | **Pass** by code review: it only reads the AI plugin's options |

### SEO Assistant (added in 1.2.0)

| Check | Result |
|---|---|
| Box appears on posts and pages in the block editor | **Pass** |
| WordPress 7.1 keeps meta boxes in a closed pane; the "Open SEO Assistant" button in the Post sidebar opens it | **Pass** |
| Focus keyword read from Chandan Digital SEO | **Pass** |
| SEO titles: fenced JSON parsed, duplicates removed, character counts shown | **Pass (mock)** |
| "Use as post title" changes the editor title | **Pass** |
| "Use as SEO title" and "Use as meta description" fill and save the Chandan Digital SEO fields | **Pass** |
| Values are still there after the post is saved (the SEO plugin's own save does not wipe them) | **Pass** |
| SEO check shows a score and the fixes, unknown priority values cleaned up | **Pass (mock)** |
| Improve content: `<script>` and `onclick` removed on the server; preview, then "Replace post content" updates the block editor | **Pass (mock)** |
| Internal links: only real published posts and pages, phrase must be in the text, invented URLs dropped | **Pass (mock)** |
| Featured image: FLUX request sent to NVIDIA's GenAI endpoint, image saved to the Media Library with SEO file name and alt text, set as featured image | **Pass (mock)** |
| SEO tasks get at least 2,048 output tokens (8,192 for improve) | **Pass** |
| Writing style and the matching SEO skill are added to the system prompt | **Pass** |
| Permissions: logged-out refused, Authors refused by default, Editors allowed, box off refused | **Pass** |
| Empty post refused with a clear message | **Pass** |
| SEO skills: six skills with the claude-seo credit, no em dashes, selectable in the Playground | **Pass** |
| Classic Editor screen in a real browser | **Not performed** (the same REST routes were tested; the Classic Editor code path in the script was reviewed only) |
| Real answers from NVIDIA models and real FLUX images | **Not performed** (needs live NVIDIA access) |

### New models (added in 1.2.0)

| Check | Result |
|---|---|
| `z-ai/glm-5.3` (GLM-5.3, text), `z-ai/glm-5.3-flash` (GLM-5.3 Flash, vision) and `deepseek-ai/deepseek-v4.1-flash` (DeepSeek V4.1 Flash, vision) registered with these exact IDs | **Pass** |
| Marked "New" and "access check required" until "Check access" succeeds | **Pass** |
| Access check and a chat request send the exact model ID | **Pass (mock)** |
| Model list refresh shows the new models | **Pass (mock)** |
| Whether your NVIDIA key can use these models | **Not performed** (use "Check access" on the AI Models tab) |

### Garbled Kimi K3 replies (added in 1.1.2)

The mock server copies the failure seen on a live site: a reply that starts with `<|close|>` and turns into random Chinese, Cyrillic and English pieces, plus a reasoning loop of 80 "!" characters.

| Check | Result |
|---|---|
| Guard spots `<|close|>`, `<|reserved_token_N|>`, 40+ "!" and broken characters | **Pass** |
| Guard leaves normal English, Bengali, Hindi and code alone | **Pass** |
| A marker split across two stream chunks is still caught, and nothing after it reaches the screen | **Pass** |
| Streaming: garbled first reply thrown away, request sent again, clean answer shown with a note | **Pass** |
| Streaming: garbled twice gives one clear message, no garbage on screen | **Pass** |
| "!!!" loop in the reasoning caught and retried | **Pass** |
| Non-streaming: same retry and error behaviour | **Pass** |
| WordPress AI Client: garbled reply retried; if garbled twice, the calling plugin gets an error instead of the text (exactly two requests sent) | **Pass** |
| Upgrade from 1.1.1 fills an unset Kimi top P with 0.95 and keeps a value you chose | **Pass** (also checked through the real upload screen) |
| Whether NVIDIA's live service returns clean answers more often with top P 0.95 | **Not performed** (needs live NVIDIA access) |

### Writing style (added in 1.1.1)

| Check | Result |
|---|---|
| Built-in rules cover language, rhythm, active voice, repetition, hedging, substance, structure, formatting, sensitive topics, technical output and the final check | **Pass** |
| The rules text contains no em dash | **Pass** |
| Other plugins' requests carry the rules (on by default) | **Pass (mock)** |
| Switching the style off sends no extra system message | **Pass (mock)** |
| Playground box ticked by default, and its request carries the rules after the user's own system prompt | **Pass** |
| Editing the rules on the Privacy tab saves them, and they reach the AI Client | **Pass** |
| "Go back to the built-in rules" restores them; saving the built-in text unchanged stores nothing extra | **Pass** |
| Rules longer than 20,000 characters refused, old rules kept | **Pass** |
| Whether a live model actually follows the rules | **Not performed** (needs live NVIDIA access) |

### Plugin lifecycle (with the real ZIP)

| Check | Result |
|---|---|
| Fresh installation | **Pass** |
| Activation creates defaults without overwriting existing values | **Pass** |
| Settings save correctly | **Pass** |
| Deactivation keeps settings | **Pass** |
| Reinstallation (delete, then install again) keeps key and settings | **Pass** |
| Upgrade from 1.1.0 to 1.1.1 through the upload screen | **Pass**: key, default model, Kimi settings and an earlier "style off" choice all kept |
| Manual ZIP update via **Upload Plugin > Replace current with uploaded** | **Pass**: WordPress offered "replace", reported "Plugin updated successfully", and key, default model and Kimi settings were kept |
| Delete with "delete all data" removes every plugin option | **Pass** |
| Running alongside the original plugin | **Pass**: no fatal error; clear notice; this plugin takes over once the original is deactivated |
| Existing AI Client integration keeps working | **Pass (mock)**: original models send the same requests as before (no added defaults) |
| Upgrade from 1.1.2 to 1.2.0 through **Upload Plugin > Replace current with uploaded** | **Pass**: "Plugin updated successfully"; key, default model and Kimi reasoning setting kept; SEO Assistant on; the three new models present; auto-update still off |
| No horizontal scrolling at phone width (390 px) on all eight tabs | **Pass** |

## Bugs found and fixed during testing

1. Non-streamed replies were marked as failed even when they succeeded (`ok` flag not set). Fixed in `NvidiaClient::chat()`.
2. An `https://127.0.0.1/...` image URL was accepted when the site itself runs on that address, because WordPress trusts its own host. Fixed with explicit checks for private and reserved IPs, `localhost` and `.local` names.
3. The image URL field overflowed the screen on phones. Fixed in CSS.
4. Model names showed "Nvidia" instead of "NVIDIA". Fixed.
5. (1.2.0) In WordPress 7.1 the SEO Assistant box was inside the closed "Meta Boxes" pane, so it was easy to miss. Added the "Open SEO Assistant" button in the Post sidebar and a note on the SEO Assistant tab.
6. (1.2.0) A long status label on the SEO Assistant tab caused sideways scrolling on phones. Shortened.

## Not tested

- Live requests to NVIDIA (any model, including FLUX image generation), as explained above.
- The WordPress AI plugin's editor buttons in a real browser (its JavaScript was not built here). Its REST endpoint was tested directly instead.
- WordPress multisite.
- PHP 7.4 at runtime (only a static compatibility scan was run; tests ran on PHP 8.3).
- Hosts without the PHP cURL extension (buffered streaming fallback path).
- Real-world hosting stacks such as nginx with PHP-FPM, Apache, LiteSpeed or Cloudflare.

## How to run the tests yourself

The test files are in `tests/chandan-digital-ai-for-nvidia/`:

- `mock/router.php`: start with `php -S 127.0.0.1:8080 router.php`
- `integration.php`: run with `CDNV_MOCK_LOG=/path/to/mock/requests.log wp eval-file integration.php` on a test site that has a must-use plugin pointing the plugin at the mock: `add_filter('cdnv_api_base_url', fn() => 'http://127.0.0.1:8080/v1');`
- `e2e.js` and `upload.js`: Playwright scripts (set `executablePath` to your Chromium)

Never run these against a live site.
