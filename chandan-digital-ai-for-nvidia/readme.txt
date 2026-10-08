=== Chandan Digital AI for NVIDIA ===
Contributors: chandandigital
Tags: ai, nvidia, kimi, ai-provider, connector
Requires at least: 6.9
Tested up to: 7.1.3
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private plugin by Chandan Digital. Connects WordPress to NVIDIA-hosted AI models, including Moonshot AI Kimi K3, with a dashboard, model manager, playground and diagnostics.

== Description ==

Chandan Digital AI for NVIDIA registers the "nvidia" provider with the WordPress AI Client (bundled with WordPress 7.0 and later), so plugins that use the AI Client can work with NVIDIA-hosted models. It also adds its own admin screen under "NVIDIA AI".

This is a private plugin. It is maintained by Chandan Digital (https://chandandigital.com/) and is updated manually by uploading a new ZIP file.

**What it does**

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

1. In WordPress, go to Plugins > Add New > Upload Plugin and upload `chandan-digital-ai-for-nvidia-v1.1.0.zip`.
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

== Content writing policy ==

Version 1.0.1 of the original plugin added a shared Indian English editorial instruction to every text request made through the WordPress AI Client, and rejects replies that contain an em dash. This behaviour is kept and is on by default. It can be turned off under Privacy & Security. In the Playground the policy is optional per request.

== Changelog ==

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
