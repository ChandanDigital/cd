# API Integration Report: Kimi K3 on NVIDIA

**Plugin:** Chandan Digital AI for NVIDIA 1.2.0
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
| Default settings | Temperature 1, top P 0.95, maximum output tokens 16384, reasoning effort `max`, seed 0, streaming on, image input on. All can be changed on the Kimi K3 Settings tab. |
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
| The WordPress AI plugin's Connector Approval blocks the request (403 inside WordPress) | `connector_not_approved` | Says NVIDIA was never contacted and where to approve. See section 11. |
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
- **FLUX image generation** is used through the AI Client and, from 1.2.0, by the SEO Assistant's "Featured image" button. The Playground is for chat models.

## 9. Writing style for AI answers (1.1.1)

The plugin sends a set of writing rules to the model as a system instruction. The rules ask for simple Indian English, a mix of short and long sentences, active voice, no stock AI phrases or padding, one clear caution instead of many, real examples, clean tables and lists, linked sources, extra care on health, legal, money and safety topics, and a silent self-check before answering. A separate rule tells the model to keep code, JSON, HTML, links and tool data exactly as they must be.

- Other plugins: added to every text request, on by default. Replies with an em dash are rejected, as in 1.0.1.
- Playground: on by default, and you can untick it for one request.
- The rules are about 1,300 words, roughly 1,900 tokens, added to each request that uses them.
- You can edit them on the Privacy & Security tab and go back to the built-in version at any time.

These are instructions to the model, so results depend on how well the model follows them. The plugin does not rewrite or grade the answer afterwards, apart from the em dash check.

## 10. Garbled replies from NVIDIA's Kimi K3 (1.1.2)

On a live site, Kimi K3 answered "what is photosynthesis?" with random words in Chinese, Cyrillic and English mixed with internal markers such as `<|close|>`. The plugin did not create that text; it only joins the pieces NVIDIA sends. Other users have reported the same thing with Kimi K3 on NVIDIA: [leaked `<|close|>` and `<|sep|>` markers with mixed-script fragments](https://github.com/Alishahryar1/free-claude-code/issues/2018), [replies in Chinese and Russian that make no sense](https://forums.developer.nvidia.com/t/kimi-k3-is-dead/385279), and [endless "!" output](https://forums.developer.nvidia.com/t/kimi-k3-outputs-only/384298), which [another report links to reasoning set to max](https://github.com/anomalyco/opencode/issues/53426). No fix from NVIDIA or Moonshot AI had been published when this was written.

What the plugin does about it:

- It checks every streamed piece before showing it. Template markers, a run of 40 or more "!" or several broken characters stop the reply at once.
- It throws the broken reply away and sends the same request once more. The Playground clears the bubble and says why.
- If the second reply is also broken, the Playground shows one clear message, and a plugin using the WordPress AI Client receives an error instead of the text, so garbage never reaches a post.
- Kimi K3 now sends top P 0.95, the value [Moonshot AI documents as fixed](https://platform.kimi.ai/docs/guide/kimi-k3-quickstart) for this model. Earlier versions left it out, so NVIDIA's own default applied.

If it keeps happening, wait a few minutes, lower reasoning effort to High or Low, or switch to another model for a while.

## 11. The 403 error in posts and pages (1.2.0)

**What you saw:** the AI buttons in posts and pages (title, meta description, excerpt, content help, alt text, image generation) failed with a 403 every time.

**Cause:** these buttons come from the WordPress **AI** plugin, not from this plugin. Version 1.4.0 of the AI plugin has a feature called **Connector Approval**. When it is on, WordPress itself stops every request to an AI connector until an administrator approves the plugin that is asking. The stop happens inside WordPress (on the `pre_http_request` hook), before anything is sent to NVIDIA, and it returns the error `wpai_connector_not_approved` with HTTP status 403. The AI plugin's own editor features are treated like any other plugin, so "AI" itself has to be approved for NVIDIA. Your NVIDIA key and this plugin were not the problem.

This was reproduced on a test site with the real AI plugin: with approval on and nothing approved, title generation was refused with "The "nvidia" AI connector has not been approved for use by "ai/ai.php""; after approving "AI" for NVIDIA, the same request returned 200.

**Fix on your site (either one):**

1. Go to **Tools > Connector Approvals**, find **AI** next to **NVIDIA**, and approve it. Approve any other plugin listed there that you trust, too.
2. Or, if you do not need approvals, go to **Settings > AI** and turn **Connector Approval** off.

**What 1.2.0 adds to help:**

- **API Diagnostics > Editor AI check** shows whether the AI plugin is active, whether Connector Approval is on, which plugins are approved for NVIDIA and which were blocked, with buttons to the approval screen and the AI settings.
- The **Overview** tab shows a warning card while a block is recorded.
- A blocked request is reported as `connector_not_approved` with a plain explanation, instead of a vague network error.
- The AI plugin is told which NVIDIA models to use for text, vision and images (`wpai_preferred_text_models`, `wpai_preferred_vision_models`, `wpai_preferred_image_models`), and that NVIDIA credentials exist when the key is saved in this plugin (`wpai_has_ai_credentials`). Before this, the AI plugin only looked at Settings > Connectors and the `NVIDIA_API_KEY` constant.
- This plugin never approves anything by itself. Approving stays an administrator's decision.

The new SEO Assistant (section 12) uses this plugin's own connection, so Connector Approval does not affect it.

## 12. SEO Assistant (1.2.0)

A box called **SEO Assistant (NVIDIA AI)** is added to the post and page editor (block editor and Classic Editor). In the block editor, WordPress 6.6 and newer put boxes like this in a **Meta Boxes** pane at the bottom of the screen that starts closed, so the plugin also adds an **Open SEO Assistant** button to the Post sidebar.

| Button | What it does |
|---|---|
| SEO titles | Five options of 50 to 60 characters with the focus keyword near the start. "Use as post title" or "Use as SEO title". |
| Meta description | Three options of 150 to 160 characters. "Use" saves into Chandan Digital SEO. |
| SEO check | A score with the most important fixes first. |
| Improve content | A better version that keeps your facts, links and images. Preview first, then replace or copy. |
| Internal links | Up to 8 links to your real published posts and pages, using phrases already in the text. |
| Featured image | Writes an image brief, creates the image with FLUX, saves it to the Media Library with SEO alt text and file name, and can set it as the featured image. |

**How it works:**

- The browser sends the current title, content and focus keyword to `/wp-json/chandan-digital-ai/v1/seo/run` with the REST nonce. WordPress sends the request to NVIDIA with your key, which never reaches the browser.
- The system prompt contains the task, the matching SEO skill (below) and, when the writing style is on, the Chandan Digital writing rules. SEO tasks get at least 2,048 output tokens (8,192 for "Improve content") because reasoning models use tokens on thinking first.
- The model must answer in JSON. The plugin checks every answer: titles are de-duplicated, improved HTML is cleaned with `wp_kses_post`, and internal links are kept only when the URL is one of the real candidate pages given to the model and the link phrase is actually in your text and not already linked.
- Nothing changes in the post until you press a "Use" button. SEO title, description and focus keyword are saved through `/seo/apply` into Chandan Digital SEO's own fields (`_seom_title`, `_seom_description`, `_seom_keyword`), and the same values are put into its form so saving the post keeps them.
- Images are created at `https://ai.api.nvidia.com/v1/genai/{model}` (FLUX.1 Schnell by default; FLUX.1 Dev and Klein also work), the reply's base64 image is checked to be a real image, then saved through the Media Library.
- Who can use it: Administrators and Editors by default (can be changed to Administrators only, or to include Authors). People can only use it on posts they can edit; images also need permission to upload files.

**SEO skills.** The rules the model follows are adapted from [claude-seo](https://github.com/AgricIDaniel/claude-seo) by AgriciDaniel, which is MIT licensed (the full licence is in `CREDITS.md`). The original skills are written for Claude Code and also run Python scripts and paid SEO data services. Those parts cannot run inside WordPress, so only the on-page writing and checking rules were adapted, in six skills: on-page SEO, content quality and E-E-A-T, AI search (GEO), internal links, images, and schema. They can also be picked in the AI Playground.

## 13. New models (1.2.0)

| Model | ID | Type in this plugin |
|---|---|---|
| GLM-5.3 | `z-ai/glm-5.3` | Text |
| GLM-5.3 Flash | `z-ai/glm-5.3-flash` | Text and image input |
| DeepSeek V4.1 Flash | `deepseek-ai/deepseek-v4.1-flash` | Text and image input |

The IDs come from NVIDIA's API reference pages for these models (links below). Note that the IDs use a dot (`glm-5.3`), while the build.nvidia.com page addresses use dashes (`glm-5-3`). Like Kimi K3, these models show a "New" label, and they are offered to other plugins only after **Check access** on the AI Models tab succeeds with your key. Reasoning-effort control stays off for them, because their accepted values were not verified.

## Sources

- [NVIDIA API reference: moonshotai/kimi-k3](https://docs.api.nvidia.com/nim/reference/moonshotai-kimi-k3)
- [NVIDIA Build: kimi-k3](https://build.nvidia.com/moonshotai/kimi-k3)
- [Moonshot AI: Kimi K3 quickstart](https://platform.kimi.ai/docs/guide/kimi-k3-quickstart)
- [Moonshot AI: model parameter reference](https://platform.kimi.ai/docs/api/models-overview)
- [Moonshot AI: Kimi-K3 on GitHub](https://github.com/MoonshotAI/Kimi-K3)
- [NVIDIA NIM: Query the Kimi-K2.6 API (image formats, images per request)](https://docs.nvidia.com/nim/vision-language-models/1.7.0/examples/kimi-k2.6/api.html)
- [WordPress: Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
- [NVIDIA API reference: z-ai/glm-5.3](https://docs.api.nvidia.com/nim/reference/z-ai-glm-5-3)
- [NVIDIA API reference: z-ai/glm-5.3-flash](https://docs.api.nvidia.com/nim/reference/z-ai-glm-5-3-flash)
- [NVIDIA Build: glm-5-3-flash](https://build.nvidia.com/z-ai/glm-5-3-flash)
- [NVIDIA API reference: deepseek-v4.1-flash](https://docs.api.nvidia.com/nim/reference/nvidia-deepseek-v4_1-flash)
- [WordPress AI plugin source (Connector Approval)](https://github.com/WordPress/ai)
- [claude-seo by AgriciDaniel (MIT License)](https://github.com/AgricIDaniel/claude-seo)
