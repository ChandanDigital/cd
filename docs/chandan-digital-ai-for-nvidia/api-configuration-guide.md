# API Configuration Guide

This guide explains how to create a new NVIDIA API key, add it to the plugin, and check that Kimi K3 works on your site.

## 1. Create a new NVIDIA API key

1. Go to [build.nvidia.com](https://build.nvidia.com) and sign in (or create a free NVIDIA account).
2. Open the Kimi K3 page: [build.nvidia.com/moonshotai/kimi-k3](https://build.nvidia.com/moonshotai/kimi-k3).
3. Choose **Get API Key** (or open your account's API keys page) and create a key.
4. Copy the key. It starts with `nvapi-`.

**Important:** if a key was ever pasted into a chat, email, document or screenshot, treat it as public. Revoke it on build.nvidia.com and create a new one. Do not reuse the key that was shared earlier in your conversation.

## 2. Add the key to WordPress

Choose **one** of these methods.

### Option A: wp-config.php (most secure)

Add this line to `wp-config.php`, above the line that says `That's all, stop editing!`:

```php
define( 'CHANDAN_NVIDIA_API_KEY', 'nvapi-your-new-key' );
```

The key then never touches the database, and the settings screen shows it as "wp-config.php constant CHANDAN_NVIDIA_API_KEY". To change it, edit wp-config.php.

### Option B: the settings screen

1. Open **NVIDIA AI > NVIDIA API Settings**.
2. Paste the key into **NVIDIA API key** and press **Save configuration**.
3. The field goes empty and only a masked version is shown. The key is stored encrypted.

To remove it later, tick **Remove the saved key** and save.

### Which key wins?

The plugin uses the first key it finds, in this order:

1. `CHANDAN_NVIDIA_API_KEY` constant in wp-config.php
2. The key saved on the plugin settings screen
3. An `NVIDIA_API_KEY` environment variable or constant (the method the original plugin used)
4. WordPress **Settings > Connectors** (WordPress 7.0+)

The **Key for the WordPress AI Client** setting decides what other plugins use:

- **Recommended:** use this plugin's key only when WordPress has no NVIDIA key of its own.
- **Always** use this plugin's key.
- **Never** share it; other plugins use only Connectors or `NVIDIA_API_KEY`.

## 3. Other connection settings

| Setting | Default | Notes |
|---|---|---|
| API base URL | `https://integrate.api.nvidia.com/v1` | Leave as is unless you run your own NVIDIA NIM. Only `https://` is accepted. |
| Request timeout | 120 seconds | Kimi K3 at maximum reasoning can take a few minutes. 120 to 300 works well. |
| Connection timeout | 15 seconds | |
| Automatic retries | 1 retry, wait up to 10 seconds | Only for rate limits, NVIDIA outages and network errors. |

## 4. Test Kimi K3

1. **Connection test.** On **NVIDIA API Settings** (or **API Diagnostics**), press **Test connection**. You should see:
   - API endpoint reachable (NVIDIA lists its catalogue)
   - API key accepted
   - Access to Kimi K3
2. **Access check.** On **Kimi K3 Settings**, press **Check Kimi K3 access**. The status changes to **Access confirmed**. From now on, other plugins using the WordPress AI Client can choose Kimi K3.
3. **Text.** In **AI Playground**, choose **Kimi K3 (moonshotai/kimi-k3)**, type a question and press **Generate response**. With streaming on, the reasoning appears first (in the collapsible "Reasoning" box), then the answer word by word.
4. **Conversation.** Ask a follow-up question. Kimi K3 receives its earlier reasoning automatically.
5. **Image.** Press **Upload image** and choose a JPEG or PNG, or paste a public `https://` image URL and press **Add URL**. Ask "Describe this image" and press **Generate response**.
6. **Non-streaming.** Untick **Stream the reply** and send another message.
7. **Reasoning effort.** On **Kimi K3 Settings**, change reasoning effort to **Low**, save, and ask a question in the Playground. If NVIDIA rejects the value, the Playground shows NVIDIA's own message. Change it back to **Max** or **High** in that case.

## 5. New models and the SEO Assistant (1.2.0)

1. **New models.** On **AI Models**, press **Refresh models**, then **Check access** next to **GLM-5.3** (`z-ai/glm-5.3`), **GLM-5.3 Flash** (`z-ai/glm-5.3-flash`) and **DeepSeek V4.1 Flash** (`deepseek-ai/deepseek-v4.1-flash`). Each one shows **Access confirmed** if your key can use it. Only then are they offered to other plugins.
2. **SEO Assistant.** Open a draft post with some text, click **Open SEO Assistant** in the Post sidebar, and press **SEO titles**. A list of five titles should appear. Try **Featured image** to check that FLUX image generation works with your key (it uses `ai.api.nvidia.com`).
3. **WordPress AI plugin.** If you use it, open **API Diagnostics > Editor AI check**. If it shows **Blocked (403)**, approve the listed plugin for NVIDIA under **Tools > Connector Approvals** (or turn Connector Approval off under **Settings > AI**), then try the AI plugin's title button in a post again.

## 6. Kimi K3 settings explained

| Setting | Default | What it does |
|---|---|---|
| Temperature | 1 | Randomness of the reply. Moonshot AI fixes this at 1.0 on its own API, and NVIDIA's sample uses 1, so keep 1 unless a test shows other values work. |
| Maximum output tokens | 16384 | Upper limit for the reply, including reasoning. Raise it if replies stop early with "reached the maximum output tokens". |
| Top P | 0.95 | Moonshot AI documents 0.95 as fixed for Kimi K3, so keep it. |
| Reasoning effort | Max | Low, High or Max. Lower values answer faster. "Default" leaves the parameter out. |
| Seed | 0 | Makes replies more repeatable. Empty means it is not sent. |
| Streaming | On | Text appears as it is generated in the Playground. |
| Image input | On | JPEG and PNG, up to 5 MB each, up to 4 per request. |
| Use these settings for other plugins | On | Fills in values that other plugins leave unset. |
| Temperature from other plugins | Send theirs | Switch to "Always send the temperature set on this page" if other plugins fail because they send a different temperature. |

## 7. Firewalls and hosting

- If your site uses `WP_HTTP_BLOCK_EXTERNAL`, add `integrate.api.nvidia.com` (and `ai.api.nvidia.com` for FLUX images, including the SEO Assistant's featured images) to `WP_ACCESSIBLE_HOSTS`.
- If your server's firewall limits outgoing traffic, allow HTTPS (port 443) to those hosts.
- Some hosts and CDNs buffer output, which stops streaming. Use **API Diagnostics > Test streaming** to check.
