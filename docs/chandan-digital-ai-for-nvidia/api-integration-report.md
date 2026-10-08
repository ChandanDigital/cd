# API Integration Report: Kimi K3 on NVIDIA

**Plugin:** Chandan Digital AI for NVIDIA 1.1.1
**Model:** Moonshot AI Kimi K3, model ID `moonshotai/kimi-k3`
**Endpoint:** `https://integrate.api.nvidia.com/v1/chat/completions` (POST, JSON, Bearer token)

## 1. How the original plugin worked

The uploaded `ai-provider-for-nvidia.zip` (version 1.0.2) is a provider add-on for the **WordPress AI Client**, the AI layer bundled with WordPress 7.0 and later. It had no settings screen of its own. It:

- registered a provider with the ID `nvidia` on the `init` hook;
- listed a hand-picked set of 48 NVIDIA models (text, vision and FLUX image generation), shown only while NVIDIA's `/v1/models` catalogue still listed them;
- sent chat requests to NVIDIA's OpenAI-compatible endpoint and image requests to `ai.api.nvidia.com/v1/genai`;
- read the API key from the `NVIDIA_API_KEY` environment variable or constant (WordPress 7.0+ can also supply it from Settings > Connectors);
- added an Indian English editorial policy to every text request (version 1.0.1) and careful JSON handling (version 1.0.2).

All of this is kept. In 1.1.1 the old editorial policy was replaced by the new Chandan Digital writing style (see section 9). Kimi K3 was added **through the same provider**, so any plugin that uses the WordPress AI Client with provider `nvidia` can use Kimi K3. The dashboard is an addition, not a separate chatbot.

## 2. What was added for Kimi K3

| Area | What the plugin does |
|---|---|
| Model registration | `moonshotai/kimi-k3` added to the model registry as a multimodal (text and image input) model with reasoning. All 48 original models are still there. |
| Request format | Follows NVIDIA's published Kimi K3 sample: `model`, `messages` (text and `image_url` parts), `max_tokens`, `temperature`, `reasoning_effort`, `seed`, `stream`. A test checks that the plugin's request matches this structure exactly. |
| Default settings | Temperature 1, maximum output tokens 16384, reasoning effort `max`, seed 0, streaming on, image input on. All can be changed on the Kimi K3 Settings tab. |
| Reasoning effort | Dashboard offers Default, Low, Medium, High, Max. For Kimi K3, only `low`, `high` and `max` can be chosen; Medium is shown as "not supported". "Default" leaves the parameter out. For other built-in models the control is disabled, because their support is not verified. |
| Reasoning output | Kimi K3 returns its thinking in `reasoning_content` (also `reasoning` on some backends). The plugin shows it separately from the answer. Inline `<think>...</think>` tags from other models are also separated, even when a tag is split across two streamed chunks. |
| Multi-turn chats | Moonshot AI and NVIDIA ask clients to send Kimi K3's earlier reasoning back. The plugin does this in the Playground and through the WordPress AI Client, merging content, `reasoning_content` and `tool_calls` into one assistant message. |
| Image understanding | Uploaded images (sent as base64 data URIs) and public `https://` image URLs, together with a text question. |
| Streaming | Server-Sent Events from NVIDIA are read chunk by chunk and passed to the browser through an authenticated WordPress REST endpoint. |
| Non-streaming | Standard JSON replies, used when streaming is off or does not start. |
| AI Client defaults | When another plugin uses Kimi K3 and leaves a setting empty, the dashboard value is used. The caller's own values are kept. |

## 3. Request flow

**Playground (dashboard):**

1. The browser sends the conversation to `/wp-json/chandan-digital-ai/v1/chat` or `/chat/stream`, with the WordPress REST nonce. The API key never goes to the browser.
2. WordPress checks the user's permission, the request ID (to block duplicates), the model, the images and the generation settings.
3. WordPress sends the request to NVIDIA through the WordPress HTTP API, adding the `Authorization: Bearer` header.
4. For streaming, each network chunk is parsed as it arrives and forwarded to the browser as small events (`meta`, `reasoning`, `content`, `done`, `error`).

**Other plugins (WordPress AI Client):**

1. A plugin calls the AI Client with provider `nvidia` and a model.
2. The plugin's provider builds the same chat completions request (non-streaming, as the AI Client works that way) and applies dashboard defaults where the caller left values unset.
3. The result is returned to the calling plugin as normal AI Client output.

## 4. Streaming design

WordPress's HTTP API does not offer a "give me each chunk" option directly, so the plugin uses two documented hooks:

- `requests-request.progress`, which receives each body chunk as it arrives;
- `http_api_curl`, which gives access to the cURL handle so a progress callback can stop the transfer when the browser disconnects (the Stop button) and send keep-alive comments during long thinking pauses.

The SSE parser keeps unfinished text in a buffer, because one network chunk can contain half an event, several events, or half of a `\r\n` line ending. It handles comment lines, multi-line `data:` fields, the `[DONE]` marker, finish reasons and usage counts. A stream that ends without `[DONE]` or a finish reason is reported as interrupted, and the partial text is not used as context for the next turn.

Because proxies and hosting setups sometimes buffer output, the Diagnostics tab has a **Test streaming** button. It sends five events about 0.4 seconds apart, without contacting NVIDIA, and reports whether they arrived one by one. If streaming does not start at all, the Playground automatically falls back to the non-streaming endpoint. NVIDIA is not called twice in that case, because the fallback only happens when the streaming request failed before the server began working on it.

Without the PHP cURL extension, the stream is read in full and then shown (buffered fallback), and Stop cannot cancel the upstream request.

## 5. Model discovery and access

- **Refresh models** downloads NVIDIA's `/v1/models` catalogue on demand. NVIDIA lists its whole public catalogue here whatever the key, so being listed is not treated as access.
- **Check access** sends a tiny request (a few output tokens) with your key. Only a successful reply marks a model as "Access confirmed".
- Original built-in models are offered to other plugins while NVIDIA lists them, as before.
- Kimi K3 and custom models are offered to other plugins only after access is confirmed. A later 403 or 404 removes that status.
- Disabled models are never offered. The default model is listed first.
- Automatic daily refresh is available but **off by default**.

## 6. Error handling

Errors are classified from the HTTP status and NVIDIA's own message, so the administrator sees the real cause.

| Situation | Code shown | Notes |
|---|---|---|
| No key configured | `missing_api_key` | Checked before any request. |
| HTTP 401, or a 403 that mentions an expired or invalid key | `invalid_api_key` | Clearly says the key is the problem. |
| HTTP 403 otherwise | `access_denied` | Key works, but this model or feature is not allowed. |
| HTTP 404 "Function ... not found for account" | `model_not_available` | Says the key may be fine. |
| HTTP 404 or 400 about an unknown model | `invalid_model` | |
| HTTP 400 or 422 naming `reasoning_effort`, `temperature`, `seed` and so on | `unsupported_parameter` | NVIDIA's reason is shown. |
| HTTP 400 about context length or tokens | `token_limit` | |
| HTTP 400 or 422 about an image | `invalid_image` | |
| HTTP 413 | `payload_too_large` | |
| HTTP 429 | `rate_limited` | Retry-After is respected. |
| HTTP 408, 504, cURL timeout | `timeout` | |
| HTTP 500 / 502 / 503 | `service_error` / `service_unavailable` | |
| Network, DNS, TLS problems | `network_error`, `tls_error` | |
| WordPress blocks external requests | `blocked_by_wordpress` | Explains WP_ACCESSIBLE_HOSTS. |
| Empty body, bad JSON, broken stream | `empty_response`, `malformed_response`, `stream_interrupted` | |

**Retries:** only rate limits, NVIDIA outages, gateway timeouts and network errors are retried. The default is 1 retry, the maximum is 3, and the wait is capped (10 seconds by default). A retry is skipped when Retry-After is longer than the cap, or when the overall timeout would be passed. Streams are retried only if nothing has been received yet. Requests are never retried endlessly.

Upstream messages are shortened to 300 characters and scrubbed of `nvapi-` keys and Bearer tokens before they are shown or logged.

## 7. What was verified, and how

| Fact | Source | Verified live with NVIDIA? |
|---|---|---|
| Endpoint, request fields and sample values | NVIDIA's Kimi K3 sample request (supplied in the brief, and the model page on build.nvidia.com) | No (see below) |
| `reasoning_effort` accepts `low`, `high`, `max`; default `max` | Moonshot AI Kimi K3 quickstart and model card | No |
| Thinking is always on; reasoning comes back as `reasoning_content` | Moonshot AI documentation | No |
| Earlier reasoning must be passed back in multi-turn chats | Moonshot AI / NVIDIA model card | No |
| Temperature fixed at 1.0 on Moonshot's own API | Moonshot AI parameter reference | No |
| Context window 1,048,576 tokens | NVIDIA model card and Moonshot AI | No |
| JPEG, PNG and GIF images on NVIDIA-hosted Kimi vision models | NVIDIA NIM documentation for Kimi K2.6 (closest published reference) | No |

**Why no live test:** this build environment's network policy blocked `integrate.api.nvidia.com`, and no API key was supplied for this work. The key mentioned earlier in your conversation was deliberately not used. Every API behaviour was tested against a local mock server that imitates NVIDIA's documented request and response formats, including awkward network chunking (see the Testing Report). Please run the steps in the API Configuration Guide on your site to confirm live behaviour.

Some sources disagreed. A few third-party write-ups said Kimi K3 accepts only `max` reasoning effort, while Moonshot AI's newer documentation lists `low`, `high` and `max`. The plugin follows Moonshot AI's documentation. If NVIDIA rejects a value, the plugin shows NVIDIA's message and does not quietly change your setting.

## 8. Compatibility limits

- **WordPress AI Client 1.3.1** (bundled with WordPress 7.1.3) has no way to accept a model that is not in the provider's list. On these versions Kimi K3 reaches other plugins only after its access is confirmed. Newer AI Client versions can also use it when a plugin names it directly.
- **Temperature:** if NVIDIA enforces Moonshot AI's fixed temperature, other plugins that send a different temperature will get a clear error. The model settings include an opt-in "Always send the temperature set on this page" option for this case.
- **Default model:** fresh installs use Kimi K3 as the default. At maximum reasoning effort it can take a minute or more to answer. The plugin raises the request timeout for Kimi K3 requests made through the AI Client to the dashboard timeout (120 seconds by default).
- **Image size and count limits** for Kimi K3 on NVIDIA are not published. The plugin defaults (5 MB, 4 images, JPEG and PNG) are conservative and adjustable. WebP and GIF are off by default.
- **Image URLs** are checked with a DNS lookup on your server, but never downloaded by it. A server without outside DNS will reject image URLs; uploads still work.
- **Streaming in the AI Client:** the WordPress AI Client works with complete replies, so other plugins get non-streamed results. Streaming is used in the Playground.
- **FLUX image generation** is unchanged and is used through the AI Client only. The Playground is for chat models.

## 9. Writing style for AI answers (1.1.1)

The plugin sends a set of writing rules to the model as a system instruction. The rules ask for simple Indian English, a mix of short and long sentences, active voice, no stock AI phrases or padding, one clear caution instead of many, real examples, clean tables and lists, linked sources, extra care on health, legal, money and safety topics, and a silent self-check before answering. A separate rule tells the model to keep code, JSON, HTML, links and tool data exactly as they must be.

- Other plugins: added to every text request, on by default. Replies with an em dash are rejected, as in 1.0.1.
- Playground: on by default, and you can untick it for one request.
- The rules are about 1,300 words, roughly 1,900 tokens, added to each request that uses them.
- You can edit them on the Privacy & Security tab and go back to the built-in version at any time.

These are instructions to the model, so results depend on how well the model follows them. The plugin does not rewrite or grade the answer afterwards, apart from the em dash check.

## Sources

- [NVIDIA API reference: moonshotai/kimi-k3](https://docs.api.nvidia.com/nim/reference/moonshotai-kimi-k3)
- [NVIDIA Build: kimi-k3](https://build.nvidia.com/moonshotai/kimi-k3)
- [Moonshot AI: Kimi K3 quickstart](https://platform.kimi.ai/docs/guide/kimi-k3-quickstart)
- [Moonshot AI: model parameter reference](https://platform.kimi.ai/docs/api/models-overview)
- [Moonshot AI: Kimi-K3 on GitHub](https://github.com/MoonshotAI/Kimi-K3)
- [NVIDIA NIM: Query the Kimi-K2.6 API (image formats, images per request)](https://docs.nvidia.com/nim/vision-language-models/1.7.0/examples/kimi-k2.6/api.html)
- [WordPress: Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
