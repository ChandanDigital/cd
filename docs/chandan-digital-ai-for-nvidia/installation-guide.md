# Installation Guide

**Plugin:** Chandan Digital AI for NVIDIA 1.1.0
**File:** `chandan-digital-ai-for-nvidia-v1.1.0.zip`
**SHA-256:** `c9cd94cdd43d1b9146eb9ddf3dc9db9d5ce21c54ee840eaa5f102c55dc7243cc`

## Before you start

You need:

- WordPress 6.9 or newer. WordPress 7.0 or newer is recommended, because it includes the WordPress AI Client that lets other plugins use NVIDIA models. The plugin's own dashboard and Playground work on 6.9 as well.
- PHP 7.4 or newer, with the cURL extension (most hosts have it). cURL is needed for live streaming and the Stop button.
- An administrator account on the site.
- An NVIDIA API key. The API Configuration Guide explains how to create one.

If the original **AI Provider for NVIDIA** plugin is installed, deactivate it first. Both plugins use the provider ID `nvidia`, and only one can register it. If both are active, this plugin shows a notice and the Playground still works, but other plugins will keep using the original.

## Install

1. Log in to WordPress as an administrator.
2. Go to **Plugins > Add New Plugin > Upload Plugin**.
3. Choose `chandan-digital-ai-for-nvidia-v1.1.0.zip` and press **Install Now**.
4. Press **Activate Plugin**.
5. A new menu item, **NVIDIA AI**, appears in the admin sidebar.

## First-time setup (about five minutes)

1. Open **NVIDIA AI > NVIDIA API Settings**.
2. Paste your NVIDIA API key and press **Save configuration**. (Or add it to wp-config.php; see the API Configuration Guide.)
3. Press **Test connection**. You should see three green results: endpoint reachable, API key accepted, and access to Kimi K3.
4. Open **AI Models**. Press **Refresh models** to load NVIDIA's catalogue, and **Check access** on any model you plan to use.
5. Open **Kimi K3 Settings** and review the defaults (temperature 1, 16384 maximum tokens, reasoning effort max, seed 0, streaming on).
6. Open **API Diagnostics** and press **Test streaming**. If it reports that output is buffered, turn streaming off in the Kimi K3 settings.
7. Open **AI Playground**, type a question and press **Generate response**.

## Updating (manual only)

This plugin never updates itself, and WordPress.org will never offer an update for it.

1. Get the new ZIP file from Chandan Digital.
2. Go to **Plugins > Add New Plugin > Upload Plugin** and upload it.
3. WordPress says the plugin is already installed. Press **Replace current with uploaded**.

Your API key, settings, model choices and access results are kept. This was tested with the real WordPress upload screen.

## Deactivating and deleting

- **Deactivate:** settings are kept. The optional daily catalogue refresh, if you turned it on, is stopped.
- **Delete** (from the Plugins screen): logs, cached data and temporary data are removed. Your API key and settings are kept, so reinstalling later works straight away. To remove everything, first tick **Also delete the saved API key and all settings** under **Privacy & Security**, save, and then delete the plugin.

A key in wp-config.php or in WordPress Settings > Connectors is never removed by this plugin.

## Troubleshooting

| Problem | What to do |
|---|---|
| "NVIDIA rejected the API key (HTTP 401)" | The key is wrong, expired or revoked. Create a new key and save it again. |
| "This model is not available to your NVIDIA account (HTTP 404)" | Your key works, but this model is not enabled for your account. Try another model or check your NVIDIA account. |
| "WordPress blocked the outgoing request" | Your site has `WP_HTTP_BLOCK_EXTERNAL` on. Add `define( 'WP_ACCESSIBLE_HOSTS', 'integrate.api.nvidia.com,ai.api.nvidia.com' );` to wp-config.php. |
| Replies time out | Raise **Request timeout** on the NVIDIA API Settings tab (for example 300), or lower Kimi K3's reasoning effort. Your host may also limit how long PHP can run. |
| Text appears all at once instead of word by word | Your host buffers output. Run **Test streaming**, and turn streaming off for the model if it is buffered. |
| "A key was saved here earlier but can no longer be decrypted" | Your WordPress security salts changed. Enter the key again. |
| The plugin's notice says another plugin registered "nvidia" | Deactivate the other NVIDIA provider plugin. |
