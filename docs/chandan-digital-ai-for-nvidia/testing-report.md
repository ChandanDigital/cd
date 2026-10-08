# Testing Report

**Plugin:** Chandan Digital AI for NVIDIA 1.1.1
**Test date:** 8 October 2026

## Summary

| Test suite | Result |
|---|---|
| Integration tests inside WordPress (WP-CLI) | **144 passed, 0 failed** |
| Browser end-to-end tests (Chromium, Playwright) | **84 passed, 0 failed** |
| Lifecycle tests with the real ZIP | All steps behaved as expected (details below) |
| PHP 7.4 to 8.4 compatibility scan (PHPCompatibility) | 0 errors |
| WordPress security sniffs (escaping, nonces, input, SQL, i18n) | 0 issues in new code; 1 warning on an unchanged original file (not browser output) |
| PHP warnings or notices from the plugin during all tests | 0 |
| **Live tests against NVIDIA's real API** | **Not performed** (see below) |

Full outputs: `tests/chandan-digital-ai-for-nvidia/final-integration.txt` and `final-e2e.txt`.

## Why live NVIDIA tests were not performed

1. This build environment's network policy blocked `integrate.api.nvidia.com` (the proxy refused the connection).
2. No NVIDIA API key was supplied for this work, and the key mentioned earlier in your conversation was deliberately not used.

So **no result in this report comes from NVIDIA's real servers.** Instead, a local mock server (`tests/chandan-digital-ai-for-nvidia/mock/router.php`) imitates NVIDIA's documented formats. It sends streams split at awkward points (inside JSON, between `\r` and `\n`, and two events in one write), uses `\r\n` line endings for one model, adds `<think>` tags, drops a connection mid-stream, and returns 401, 403, 404, 400, 429 (with Retry-After) and 500 errors. It also enforces Moonshot AI's documented Kimi K3 rules (`reasoning_effort` low/high/max, temperature 1), so the plugin's error paths could be checked.

Please follow section 4 of the API Configuration Guide on your site to confirm live behaviour with your key.

## Test environment

- WordPress 7.1.3 (bundles WordPress AI Client 1.3.1), SQLite database
- PHP 8.3.6 with cURL, PHP built-in web server (6 workers)
- Chromium (headless) through Playwright 1.48
- Plugin installed from the release ZIP for lifecycle tests

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
| Server streaming self-test | **Pass**: "events arrived one by one (first after 29 ms, last after 1631 ms)" on the test server |
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
| No remote code execution path introduced | **Pass** by code review: no `eval`, no dynamic includes from input, uploads never written to disk |

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
| No horizontal scrolling at phone width (390 px) on all seven tabs | **Pass** |

## Bugs found and fixed during testing

1. Non-streamed replies were marked as failed even when they succeeded (`ok` flag not set). Fixed in `NvidiaClient::chat()`.
2. An `https://127.0.0.1/...` image URL was accepted when the site itself runs on that address, because WordPress trusts its own host. Fixed with explicit checks for private and reserved IPs, `localhost` and `.local` names.
3. The image URL field overflowed the screen on phones. Fixed in CSS.
4. Model names showed "Nvidia" instead of "NVIDIA". Fixed.

## Not tested

- Live requests to NVIDIA (any model), as explained above.
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
