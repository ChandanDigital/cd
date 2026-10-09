=== Chandan Digital AI for NVIDIA ===
Contributors: chandandigital
Tags: ai, nvidia, kimi, ai-provider, connector
Requires at least: 6.9
Tested up to: 7.1.3
Stable tag: 1.2.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private plugin by Chandan Digital. Connects WordPress to NVIDIA-hosted AI models, including Moonshot AI Kimi K3, with a dashboard, model manager, playground and diagnostics.

== Description ==

Chandan Digital AI for NVIDIA registers the "nvidia" provider with the WordPress AI Client (bundled with WordPress 7.0 and later), so plugins that use the AI Client can work with NVIDIA-hosted models. It also adds its own admin screen under "NVIDIA AI".

This is a private plugin. It is maintained by Chandan Digital (https://chandandigital.com/) and is updated manually by uploading a new ZIP file.

**What it does**

* SEO Assistant in every post and page: SEO titles, meta descriptions saved into Chandan Digital SEO, an SEO check, improved content, internal link suggestions to your real pages, and FLUX featured images with SEO alt text.
* Kimi K3 (`moonshotai/kimi-k3`) through NVIDIA's chat completions endpoint: text, chat, coding help, reasoning, image understanding, streaming and non-streaming replies.
* All original NVIDIA models from "AI Provider for NVIDIA" 1.0.2 are kept (Llama, Nemotron, Mistral, Qwen, GPT-OSS, Gemma, vision models and FLUX image generation).
* AI Model Manager: search, enable or disable models, choose the default model, see capabilities and access status, check access per model, register custom model IDs, refresh NVIDIA's catalogue on demand.
* Model-specific settings: temperature, maximum output tokens, top P, reasoning effort, seed, streaming and image limits.
* AI Playground: prompts, images (upload or public https URL), streaming output, stop, clear conversation and copy response.
* API Diagnostics: connection test that separates key problems from model-access problems, a streaming test for your host, environment details and optional local logs.
* Privacy & Security: no telemetry, no external dashboard assets, optional local logs (off by default), clear logs control.

**Requirements**

* PHP 7.4 or higher.
* WordPress 6.9 or higher. The WordPress AI Client is needed for other plugins to use NVIDIA models (bundled with WordPress 7.0+). The dashboard and Playground work without it.
* An NVIDIA API key from build.nvidia.com.

== Installation ==

1. In WordPress, go to Plugins > Add New > Upload Plugin and upload `chandan-digital-ai-for-nvidia-v1.2.1.zip`.
2. Activate the plugin.
3. Open NVIDIA AI > NVIDIA API Settings, paste your API key and save. For extra security, define `CHANDAN_NVIDIA_API_KEY` in wp-config.php instead.
4. Press "Test connection", then open the AI Models tab and press "Check access" on Kimi K3.
5. Try Kimi K3 in NVIDIA AI > AI Playground.

If the original "AI Provider for NVIDIA" plugin is active, deactivate it. Both plugins use the provider ID "nvidia" and only one can be registered.

== Updating ==

Updates are manual only. Upload the new ZIP under Plugins > Add New > Upload Plugin and choose "Replace current with uploaded". Settings, the saved API key and model choices are kept. WordPress.org never offers updates for this plugin, and WordPress auto-updates are turned off for it. Other plugins and WordPress core updates are not affected.

== Frequently Asked Questions ==

= Where is my API key stored? =

If you define `CHANDAN_NVIDIA_API_KEY` in wp-config.php, it is never stored in the database. A key saved on the settings screen is encrypted with libsodium, using a key derived from the secret salts in wp-config.php, and is never shown again or sent to the browser.

= Which key do other plugins use? =

By default this plugin's key is given to the WordPress AI Client only when WordPress has no NVIDIA key of its own (Settings > Connectors, or an `NVIDIA_API_KEY` environment variable or constant). You can change this on the NVIDIA API Settings tab.

= Why is Kimi K3 not offered to other plugins yet? =

A model is offered to other plugins only after its access is confirmed with your key. Press "Check access" on the AI Models or Kimi K3 tab, or send one Playground message.

= I can't find the SEO Assistant box in the editor. Where is it? =

In the block editor, WordPress 6.6 and newer put boxes like this one in a "Meta Boxes" pane at the bottom of the screen, and the pane starts closed. Click that bar to open it, or click "Open SEO Assistant" in the Post sidebar on the right. In the Classic Editor it sits under the content box.

= The AI buttons in posts and pages show a 403 error. Why? =

The WordPress "AI" plugin has a "Connector Approval" feature. When it is on, WordPress blocks every request to the NVIDIA connector, with a 403, until an administrator approves the plugin making the request. Open NVIDIA AI > API Diagnostics to see which plugin is blocked, then approve it under Tools > Connector Approvals, or turn Connector Approval off under Settings > AI. The SEO Assistant box in this plugin is not affected.

= Kimi K3 replied with random words in many languages. What happened? =

This is a known problem with Kimi K3 on NVIDIA's servers, reported by many users on NVIDIA's developer forum. From version 1.1.2 the plugin spots such replies, discards them and asks again once. If it keeps happening, wait a few minutes, set Reasoning effort to High or Low on the Kimi K3 Settings tab, or use another model for a while.

= Does streaming work on every host? =

Streaming needs a host that does not buffer PHP output. Use API Diagnostics > Test streaming. If output is buffered, turn streaming off in the model settings; the Playground then waits for the full reply.

== External services ==

This plugin connects only to NVIDIA, and only when someone makes an AI request, runs a test or refreshes the model list.

* `integrate.api.nvidia.com` (or the base URL you set): chat requests, access checks, connection tests and the model catalogue. Sent: your API key (Authorization header), model ID, messages, images you add and generation settings.
* `ai.api.nvidia.com`: only for FLUX image generation requested through the WordPress AI Client. Sent: your API key, the image prompt and image settings.

NVIDIA Terms of Service: https://www.nvidia.com/en-us/about-nvidia/terms-of-service/
NVIDIA Privacy Policy: https://www.nvidia.com/en-us/about-nvidia/privacy-policy/

NVIDIA is a trademark of NVIDIA Corporation. Kimi is a trademark of Moonshot AI. This plugin is an independent integration and is not affiliated with, endorsed by, or sponsored by NVIDIA Corporation or Moonshot AI.

== Credits and licence ==

Based on "AI Provider for NVIDIA" version 1.0.2 by Deepak Bhojwani, released under GPL-2.0-or-later. Modified and maintained by Chandan Digital. This modified version is also released under GPL-2.0-or-later; see LICENSE.

== Writing style for AI answers ==

Every text answer can follow the Chandan Digital writing style: simple Indian English that a class 10 student can read, a natural mix of short and long sentences, active voice, no stock AI phrases, no padding, no repeated warnings, real examples, clean tables and lists, sourced claims, and a silent self-check before the answer is returned. Code, JSON, HTML, links and other technical output are left exactly as they need to be.

* Other plugins (WordPress AI Client): the style goes with every text request, and replies containing an em dash are rejected. On by default.
* AI Playground: the "Use Chandan Digital writing style" box is ticked by default and can be unticked for any request.
* You can edit the rules under NVIDIA AI > Privacy & Security > Writing style for AI answers, and go back to the built-in rules at any time.

The style is an instruction to the model. How closely an answer follows it still depends on the model. The plugin does not check grammar, plagiarism or facts after the answer arrives, apart from the em dash check.

== Changelog ==

= 1.2.1 =

* Fix: the API key is now kept in sync. Saving a new key in this plugin or in Settings > Connectors updates the other place, so this plugin and the WordPress AI plugin always use the same key. Before, a new key saved in Settings > Connectors was ignored by this plugin while an older key was still saved here, and every request failed.
* If an old key is still saved in one place, a request that NVIDIA rejects is tried once with the other saved key, and the key that works is then saved in both places.
* Removed the "Chandan Digital SEO: Not active" status text from the SEO Assistant screens.

= 1.2.0 =

* New SEO Assistant box in the post and page editor (block editor and Classic Editor): SEO titles, meta descriptions, SEO check, content improvement, internal link suggestions and featured image generation. Saves the SEO title, meta description and focus keyword into Chandan Digital SEO. In the block editor, an "Open SEO Assistant" button in the Post sidebar opens the box, because WordPress 6.6+ keeps meta boxes in a pane at the bottom that starts closed.
* SEO skills adapted from claude-seo by AgriciDaniel (MIT License), used by the SEO Assistant and selectable in the AI Playground.
* New models: GLM-5.3 (z-ai/glm-5.3), GLM-5.3 Flash (z-ai/glm-5.3-flash) and DeepSeek V4.1 Flash (deepseek-ai/deepseek-v4.1-flash). Like Kimi K3, they are offered to other plugins after their access is confirmed.
* New editor AI check on the Diagnostics tab, and a warning, when the WordPress AI plugin's Connector Approval blocks requests to NVIDIA (the cause of 403 errors in the editor).
* The WordPress AI plugin now gets this plugin's NVIDIA models as preferred text, vision and image models, and knows that NVIDIA credentials exist when the key is stored here.
* Clearer error when a request is blocked by Connector Approval.

= 1.1.2 =

* Fix: garbled replies from NVIDIA's hosted Kimi K3 (random mixed-language text, internal markers such as <|close|>, or long runs of "!") are no longer shown. The plugin spots them as they arrive, throws the reply away and asks once more. If the second reply is also broken, the Playground shows a clear message, and other plugins get an error instead of the garbage, so it never ends up in a post.
* Kimi K3 now sends top P 0.95 by default, the value Moonshot AI documents as fixed for this model. Existing installs that never set top P get this value once on upgrade; a value you chose is kept.

= 1.1.1 =

* New Chandan Digital writing style for AI answers: simple Indian English, varied sentence rhythm, active voice, no repetition or hedging, real substance, clean structure and formatting, careful handling of health, legal, financial and safety topics, and a final self-check.
* The writing rules can now be edited on the Privacy & Security tab, with a reset to the built-in rules.
* The AI Playground uses the writing style by default (can be turned off per request or as a default).

= 1.1.0 =

* Rebranded as Chandan Digital AI for NVIDIA (new slug, namespace and text domain) for private use.
* Added Kimi K3 (`moonshotai/kimi-k3`) with dashboard defaults from NVIDIA's reference request: temperature 1, 16384 maximum output tokens, reasoning effort max, seed 0, streaming on.
* Reasoning effort control limited to values each model accepts (Kimi K3: low, high, max). Unsupported values are refused with a clear message.
* Kimi K3 receives its earlier reasoning back in multi-turn conversations, in the Playground and through the WordPress AI Client.
* New admin screen: Overview, NVIDIA API Settings, AI Models, Kimi K3 Settings, AI Playground, API Diagnostics, Privacy & Security.
* Encrypted API key storage, `CHANDAN_NVIDIA_API_KEY` constant support, masked display.
* Streaming (Server-Sent Events) through authenticated REST endpoints, with partial-chunk parsing, cancellation, keep-alive and a non-streaming fallback.
* Image input for vision models with type, size and count checks, and URL checks that keep private network addresses and unpublished media out of requests.
* Clear, separate error messages for key, model access, parameter, image, token limit, rate limit, timeout and service errors. Bounded retries that respect Retry-After.
* Optional local logs (off by default) with retention and a clear logs control.
* Model list for the WordPress AI Client follows the dashboard: disabled models are hidden, the default model is listed first, and Kimi K3 and custom models appear after access is confirmed.
* The NVIDIA catalogue is reused for 15 minutes instead of being fetched on every page load.
* Manual updates only: Update URI header and auto-updates turned off for this plugin.
* The configurable API base URL and request timeouts.

= 1.0.2 (original plugin) =

* Preserve valid JSON and embedded think tags without prose-only rejection.
* Add explicit JSON instructions and support schema-only configuration.
* Safely unwrap complete JSON responses and report malformed or truncated output.

= 1.0.1 (original plugin) =

* Add Indian English editorial rules and a silent final review to all text requests.
* Preserve caller context, structured output and function calls.
* Reject visible responses containing literal or encoded em dashes.

= 1.0.0 (original plugin) =

* Initial release by Deepak Bhojwani.
