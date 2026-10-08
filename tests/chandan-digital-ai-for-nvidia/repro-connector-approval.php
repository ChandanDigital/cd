<?php
/**
 * Reproduces the editor 403 with the real WordPress AI plugin (test site only, never a live site).
 *
 * Needs: the AI plugin active with its Title Generation feature on, this plugin pointed at the mock
 * server, and Connector Approval switched on or off with WP-CLI before each run:
 *   wp option update wpai_feature_title-generation_enabled 1
 *   wp option update wpai_feature_connector-approval_enabled 1
 *   wp eval-file repro-connector-approval.php
 *
 * Result on WordPress 7.1.3 with AI plugin 1.4.0 (8 October 2026):
 *   Connector Approval off                     -> HTTP 200, title returned
 *   Connector Approval on, AI not approved     -> request refused ("The "nvidia" AI connector has
 *                                                 not been approved for use by "ai/ai.php"")
 *   Connector Approval on, AI approved for NVIDIA -> HTTP 200, title returned
 */

use ChandanDigital\NvidiaAi\Support\{Settings, ModelRegistry};

Settings::save_api_key('nvapi-Repro_Key-403abc');
ModelRegistry::set_status('moonshotai/kimi-k3', 'verified', 200);
wp_set_current_user(1);
$req = new WP_REST_Request('POST', '/wp-abilities/v1/abilities/ai/title-generation/run');
$req->set_header('content-type', 'application/json');
$req->set_body(wp_json_encode(['input' => ['content' => str_repeat('Photosynthesis lets plants in Kolkata gardens turn sunlight, water and carbon dioxide into food. ', 6)]]));
$res = rest_do_request($req);
echo 'HTTP ', $res->get_status(), "\n", substr(wp_json_encode($res->get_data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 500), "\n";
echo 'pending: ', wp_json_encode(get_option('wpai_connector_approval_pending')), "\n";
