<?php

/**
 * Plugin Name: Chandan Digital AI for NVIDIA
 * Plugin URI: https://chandandigital.com/
 * Description: NVIDIA-hosted AI models for WordPress, including Moonshot AI Kimi K3. Registers the NVIDIA provider with the WordPress AI Client and adds a private admin dashboard, model manager, playground and diagnostics.
 * Version: 1.1.2
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Chandan Digital
 * Author URI: https://chandandigital.com/
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: chandan-digital-ai-for-nvidia
 * Domain Path: /languages
 * Update URI: https://chandandigital.com/private-plugins/chandan-digital-ai-for-nvidia/
 *
 * Private plugin maintained by Chandan Digital. Updates are installed manually
 * by uploading a new ZIP file. WordPress.org never offers updates for it,
 * because the Update URI header above points away from WordPress.org.
 *
 * Based on "AI Provider for NVIDIA" 1.0.2 by Deepak Bhojwani, released under
 * GPL-2.0-or-later. Modified by Chandan Digital in 2026 (see readme.txt).
 *
 * NVIDIA is a trademark of NVIDIA Corporation. Kimi is a trademark of Moonshot
 * AI. This plugin is an independent integration and is not affiliated with,
 * endorsed by, or sponsored by NVIDIA Corporation or Moonshot AI.
 *
 * @package ChandanDigital\NvidiaAi
 */

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi;

if (!defined('ABSPATH')) {
    return;
}

const VERSION = '1.1.2';
const PLUGIN_FILE = __FILE__;
const TEXT_DOMAIN = 'chandan-digital-ai-for-nvidia';

require_once __DIR__ . '/src/autoload.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

Plugin::boot();
