<?php
// Integration tests for Chandan Digital AI for NVIDIA. Run with: wp eval-file integration.php
// Uses the local mock NVIDIA API (see mock/router.php). Not shipped with the plugin.

use ChandanDigital\NvidiaAi\Api\ApiError;
use ChandanDigital\NvidiaAi\Api\ImageInput;
use ChandanDigital\NvidiaAi\Api\NvidiaClient;
use ChandanDigital\NvidiaAi\Api\PayloadBuilder;
use ChandanDigital\NvidiaAi\Api\SseParser;
use ChandanDigital\NvidiaAi\Api\StreamState;
use ChandanDigital\NvidiaAi\Api\ThinkSplitter;
use ChandanDigital\NvidiaAi\Content\IndianEnglishPolicy;
use ChandanDigital\NvidiaAi\Plugin;
use ChandanDigital\NvidiaAi\Support\Logger;
use ChandanDigital\NvidiaAi\Support\ModelRegistry;
use ChandanDigital\NvidiaAi\Support\Settings;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

$GLOBALS['cdnv_pass'] = 0;
$GLOBALS['cdnv_fail'] = [];
$mockLog = getenv('CDNV_MOCK_LOG');

function check(string $name, bool $condition, string $info = ''): void {
    if ($condition) {
        $GLOBALS['cdnv_pass']++;
        echo "PASS  $name\n";
    } else {
        $GLOBALS['cdnv_fail'][] = $name;
        echo "FAIL  $name" . ($info !== '' ? "  [$info]" : '') . "\n";
    }
}
function last_request(string $log): array {
    $lines = array_filter(explode("\n", (string) file_get_contents($log)));
    return json_decode((string) end($lines), true) ?: [];
}
function reset_state(): void {
    delete_option(ModelRegistry::OPTION);
    delete_option(Settings::OPTION);
    add_option(Settings::OPTION, Settings::defaults());
    ModelRegistry::flush();
    Settings::flush();
}
function png_data_uri(int $w = 4, int $h = 4): string {
    $im = imagecreatetruecolor($w, $h);
    ob_start(); imagepng($im); $bytes = ob_get_clean();
    return 'data:image/png;base64,' . base64_encode($bytes);
}

reset_state();
Settings::delete_api_key();

echo "== Settings and key storage ==\n";
check('no key -> source none', Settings::key_source() === 'none');
check('save valid key', Settings::save_api_key('nvapi-TestKey_123-abcdef') === null);
$raw = get_option(Settings::KEY_OPTION);
check('key stored encrypted (not plaintext)', is_string($raw) && strpos($raw, 'nvapi-') === false && strpos($raw, 'v1:') === 0, (string) $raw);
check('key decrypts', Settings::api_key() === 'nvapi-TestKey_123-abcdef');
check('key not autoloaded', (function () { global $wpdb; return $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name='cdnv_api_key'"); })() !== 'yes'
    && !in_array((function () { global $wpdb; return $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name='cdnv_api_key'"); })(), ['yes', 'on', 'auto-on'], true));
check('source plugin', Settings::key_source() === 'plugin');
check('mask hides key', Settings::mask_key('nvapi-TestKey_123-abcdef') === 'nvapi-••••••••cdef');
check('reject key with spaces', Settings::save_api_key('nvapi-bad key') !== null);
check('bad key did not overwrite good key', Settings::api_key() === 'nvapi-TestKey_123-abcdef');
check('base url rejects http', Settings::sanitize_base_url('http://integrate.api.nvidia.com/v1') === null);
check('base url strips chat path', Settings::sanitize_base_url('https://integrate.api.nvidia.com/v1/chat/completions') === 'https://integrate.api.nvidia.com/v1');
check('base url rejects credentials', Settings::sanitize_base_url('https://user:pass@example.com/v1') === null);
$errs = Settings::update(['timeout' => 5]);
check('timeout below range rejected', isset($errs['timeout']) && Settings::get('timeout') === 120);
$errs = Settings::update(['default_model' => 'nope/nope']);
check('unknown default model rejected', isset($errs['default_model']));

echo "== Model registry ==\n";
$all = ModelRegistry::all();
check('original 48 models + Kimi K3 registered', count($all) === 49, (string) count($all));
check('kimi present with exact id', isset($all['moonshotai/kimi-k3']) && $all['moonshotai/kimi-k3']['name'] === 'Kimi K3');
check('kimi is vision + reasoning', $all['moonshotai/kimi-k3']['kind'] === 'vision' && in_array('reasoning', $all['moonshotai/kimi-k3']['capabilities'], true));
check('kimi reasoning values low/high/max', $all['moonshotai/kimi-k3']['reasoning_values'] === ['low', 'high', 'max']);
$k = ModelRegistry::settings('moonshotai/kimi-k3');
check('kimi defaults match spec', $k['temperature'] === 1.0 && $k['max_tokens'] === 16384 && $k['reasoning_effort'] === 'max' && $k['seed'] === 0 && $k['stream'] === 1 && $k['images'] === 1);
$errors = ModelRegistry::save_settings('moonshotai/kimi-k3', array_merge($k, ['reasoning_effort' => 'medium', 'stream' => 1, 'images' => 1, 'image_urls' => 1, 'ai_client_defaults' => 1]));
check('kimi medium reasoning rejected on save', isset($errors['reasoning_effort']));
check('kimi settings unchanged after rejected save', ModelRegistry::settings('moonshotai/kimi-k3')['reasoning_effort'] === 'max');
$errors = ModelRegistry::save_settings('meta/llama-3.3-70b-instruct', ['temperature' => '0.7', 'max_tokens' => '512', 'reasoning_effort' => 'high']);
check('reasoning rejected for model without verified control', isset($errors['reasoning_effort']));
$errors = ModelRegistry::save_settings('meta/llama-3.3-70b-instruct', ['temperature' => '3', 'max_tokens' => '512', 'reasoning_effort' => 'default']);
check('temperature > 2 rejected', isset($errors['temperature']));
$errors = ModelRegistry::save_settings('meta/llama-3.3-70b-instruct', ['temperature' => '0.7', 'max_tokens' => '512', 'reasoning_effort' => 'default', 'stream' => 1]);
check('valid settings saved', !$errors && ModelRegistry::settings('meta/llama-3.3-70b-instruct')['temperature'] === 0.7);
check('custom model invalid id rejected', ModelRegistry::add_custom('bad id', '', '', 'text', false) !== null);
check('custom model added', ModelRegistry::add_custom('acme/no-access', 'No Access', 'Acme', 'text', false) === null);
ModelRegistry::add_custom('acme/forbidden', 'Forbidden', 'Acme', 'text', false);
ModelRegistry::add_custom('acme/ratelimit', 'Rate', 'Acme', 'text', false);
ModelRegistry::add_custom('acme/error500', 'Err', 'Acme', 'text', false);
ModelRegistry::add_custom('acme/think-tags', 'Think', 'Acme', 'text', true);
ModelRegistry::add_custom('acme/interrupt', 'Interrupt', 'Acme', 'vision', false);
check('custom models listed', ModelRegistry::get('acme/think-tags') !== null && ModelRegistry::get('acme/think-tags')['reasoning_values'] === ['low', 'medium', 'high', 'max']);

echo "== SSE parser ==\n";
$p = new SseParser();
$events = [];
foreach (["data: {\"a\":", "1}\n", "\n: comment\n\ndata: x\r", "\ndata: y\r\n\r\n", "data: [DONE]\n\n"] as $chunk) {
    $events = array_merge($events, $p->push($chunk));
}
check('SSE split chunks + CRLF + multi-line data', $events === ['{"a":1}', "x\ny", '[DONE]'], json_encode($events));
$p2 = new SseParser();
$e2 = array_merge($p2->push("data: tail-without-blank-line"), $p2->finish());
check('SSE final event without terminator', $e2 === ['tail-without-blank-line']);
$p3 = new SseParser();
$ok = false;
try { $p3->push(str_repeat('x', 4194305)); } catch (\RuntimeException $e) { $ok = true; }
check('SSE oversize buffer guarded', $ok);

echo "== Think splitter ==\n";
$s = new ThinkSplitter();
$out = [];
foreach (['Hi <th', 'ink>plan', ' A</thi', 'nk>Answer <', 'b>'] as $c) { $out = array_merge($out, $s->feed($c)); }
$out = array_merge($out, $s->flush());
$content = ''; $reasoning = '';
foreach ($out as [$ch, $t]) { if ($ch === 'content') $content .= $t; else $reasoning .= $t; }
check('think tags split across chunks', $content === 'Hi Answer <b>' && $reasoning === 'plan A', "c=$content r=$reasoning");

echo "== Error classification ==\n";
check('401 -> invalid key', ApiError::from_http(401, '{"detail":"Authentication failed"}')->code === 'invalid_api_key');
check('403 -> access denied', ApiError::from_http(403, '{"detail":"needs subscription"}')->code === 'access_denied');
check('403 expired key -> invalid key', ApiError::from_http(403, '{"detail":"API key expired"}')->code === 'invalid_api_key');
check('404 function -> model not available', ApiError::from_http(404, '{"detail":"Function abc: Not found for account x"}')->code === 'model_not_available');
check('400 reasoning -> unsupported parameter', ApiError::from_http(400, '{"error":{"message":"Invalid reasoning_effort"}}')->code === 'unsupported_parameter');
check('400 context -> token limit', ApiError::from_http(400, '{"error":{"message":"maximum context length exceeded"}}')->code === 'token_limit');
check('413 -> payload too large', ApiError::from_http(413, '')->code === 'payload_too_large');
check('429 + Retry-After', ApiError::from_http(429, '', '7')->retryAfter === 7);
check('408 -> timeout', ApiError::from_http(408, '')->code === 'timeout');
check('422 image -> invalid image', ApiError::from_http(422, '{"detail":[{"loc":["body","messages",0],"msg":"invalid image_url"}]}')->code === 'invalid_image');
foreach ([500 => 'service_error', 502 => 'service_unavailable', 503 => 'service_unavailable', 504 => 'timeout'] as $code => $want) {
    check("$code -> $want", ApiError::from_http($code, '')->code === $want);
}
check('detail redacts keys', strpos(ApiError::extract_detail('{"detail":"bad key nvapi-SECRETSECRET123 used"}'), 'SECRETSECRET') === false);
check('wp_error timeout', ApiError::from_wp_error(new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'))->code === 'timeout');

echo "== Image validation ==\n";
$vs = ModelRegistry::settings('moonshotai/kimi-k3');
check('valid PNG upload accepted', is_string(ImageInput::validate_data_uri(png_data_uri(), $vs)));
$gif = 'data:image/gif;base64,' . base64_encode(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
$r = ImageInput::validate_data_uri($gif, $vs);
check('GIF rejected when not enabled', $r instanceof ApiError && $r->code === 'unsupported_image_format');
$fake = 'data:image/png;base64,' . base64_encode('<?php echo "not an image"; ?>');
$r = ImageInput::validate_data_uri($fake, $vs);
check('non-image bytes with image MIME rejected', $r instanceof ApiError);
$big = 'data:image/png;base64,' . base64_encode(str_repeat('A', 6 * 1048576));
$r = ImageInput::validate_data_uri($big, $vs);
check('image over size limit rejected', $r instanceof ApiError && $r->code === 'image_too_large');
check('https public url accepted', is_string(ImageInput::validate_url('https://assets.ngc.nvidia.com/products/api-catalog/phi-3-5-vision/example1b.jpg', $vs)));
$r = ImageInput::validate_url('http://example.com/a.jpg', $vs);
check('http url rejected', $r instanceof ApiError);
$r = ImageInput::validate_url('https://127.0.0.1/a.jpg', $vs);
check('loopback url rejected (SSRF guard)', $r instanceof ApiError);
$r = ImageInput::validate_url('https://192.168.1.10/a.png', $vs);
check('private network url rejected', $r instanceof ApiError);
$r = ImageInput::validate_url('https://user:pw@example.com/a.png', $vs);
check('url with credentials rejected', $r instanceof ApiError);
$r = ImageInput::validate_url('https://example.com/file.svg', $vs);
check('svg url rejected (format)', $r instanceof ApiError);
$r = ImageInput::validate_url('https://example.com:8443/a.png', $vs);
check('non-standard port rejected', $r instanceof ApiError);

echo "== Payload builder ==\n";
$kimi = ModelRegistry::get('moonshotai/kimi-k3');
$b = new PayloadBuilder();
$payload = $b->chat($kimi, ModelRegistry::settings('moonshotai/kimi-k3'), [
    ['role' => 'user', 'text' => 'What is in this image?', 'images' => [['type' => 'url', 'value' => 'https://example.com/example1b.jpg']]],
], true);
$expected = [
    'model' => 'moonshotai/kimi-k3',
    'messages' => [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => 'What is in this image?'],
        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/example1b.jpg']],
    ]]],
    'max_tokens' => 16384, 'temperature' => 1.0, 'reasoning_effort' => 'max', 'seed' => 0, 'stream' => true,
];
check('kimi payload matches NVIDIA reference structure', $payload === $expected, wp_json_encode($payload));
$payload = $b->chat($kimi, ModelRegistry::settings('moonshotai/kimi-k3'), [
    ['role' => 'user', 'text' => 'Hi'],
    ['role' => 'assistant', 'text' => 'Hello', 'reasoning' => 'greet back'],
    ['role' => 'user', 'text' => 'Again'],
], false, 'Be brief');
check('kimi multi-turn passes reasoning_content back', ($payload['messages'][2]['reasoning_content'] ?? '') === 'greet back' && $payload['messages'][0]['role'] === 'system');
$llama = ModelRegistry::get('meta/llama-3.3-70b-instruct');
$payload = (new PayloadBuilder())->chat($llama, ModelRegistry::settings('meta/llama-3.3-70b-instruct'), [
    ['role' => 'user', 'text' => 'Hi'], ['role' => 'assistant', 'text' => 'Hello', 'reasoning' => 'x'], ['role' => 'user', 'text' => 'Again'],
], false);
check('non-kimi does not send reasoning_content', !isset($payload['messages'][1]['reasoning_content']) && !isset($payload['reasoning_effort']));
$r = (new PayloadBuilder())->chat($llama, ModelRegistry::settings('meta/llama-3.3-70b-instruct'), [
    ['role' => 'user', 'text' => 'x', 'images' => [['type' => 'data', 'value' => png_data_uri()]]],
], false);
check('image on text-only model rejected', $r instanceof ApiError);
$five = array_fill(0, 5, ['type' => 'data', 'value' => png_data_uri()]);
$b5 = new PayloadBuilder();
$payload = $b5->chat($kimi, ModelRegistry::settings('moonshotai/kimi-k3'), [['role' => 'user', 'text' => 'x', 'images' => $five]], false);
check('image count limit keeps 4 and reports it', is_array($payload) && count($payload['messages'][0]['content']) === 5 && count($b5->notices) === 1);
$r = (new PayloadBuilder())->chat($kimi, ModelRegistry::settings('moonshotai/kimi-k3'), [['role' => 'system', 'text' => 'x']], false);
check('system role from browser rejected', $r instanceof ApiError);
$r = (new PayloadBuilder())->chat($kimi, ModelRegistry::settings('moonshotai/kimi-k3'), [['role' => 'user', 'text' => '   ']], false);
check('empty prompt rejected', $r instanceof ApiError);
$bad = ModelRegistry::settings('moonshotai/kimi-k3'); $bad['reasoning_effort'] = 'medium';
$r = PayloadBuilder::generation_params($kimi, $bad);
check('stored invalid reasoning value refused at request time', $r instanceof ApiError && $r->code === 'unsupported_parameter');

echo "== NVIDIA client against mock ==\n";
$client = NvidiaClient::create();
check('client created', $client instanceof NvidiaClient);
$list = $client->list_models();
check('list models', $list['ok'] && in_array('moonshotai/kimi-k3', $list['ids'], true), wp_json_encode($list['error'] ? $list['error']->to_array() : null));
$req = last_request($mockLog);
check('Authorization header sent as Bearer', $req['auth'] === 'Bearer nvapi-TestKey_123-abcdef');
check('user agent does not leak site URL', strpos($req['ua'], '127.0.0.1') === false && strpos($req['ua'], 'ChandanDigitalAIforNVIDIA/1.1.1') === 0, $req['ua']);
$probe = $client->probe('moonshotai/kimi-k3');
check('probe kimi ok', $probe['ok'] && $probe['finish_reason'] === 'length');
$chat = $client->chat((new PayloadBuilder())->chat($kimi, ModelRegistry::settings('moonshotai/kimi-k3'), [['role' => 'user', 'text' => 'Hello Kimi']], false));
check('non-stream chat content + reasoning', $chat['ok'] && strpos($chat['content'], 'Hello from moonshotai/kimi-k3') === 0 && strpos($chat['reasoning'], 'step by step') !== false);
check('usage parsed', $chat['usage'] === ['prompt_tokens' => 42, 'completion_tokens' => 17, 'total_tokens' => 59]);
$req = last_request($mockLog);
check('non-stream sends stream=false + Accept json', $req['body']['stream'] === false && $req['accept'] === 'application/json');

$collected = ['content' => '', 'reasoning' => ''];
$events = 0;
$res = $client->stream((new PayloadBuilder())->chat($kimi, ModelRegistry::settings('moonshotai/kimi-k3'), [['role' => 'user', 'text' => 'Stream please']], true),
    function ($ch, $t) use (&$collected, &$events) { $collected[$ch] .= $t; $events++; }, fn() => true);
check('stream ok', $res['ok'], wp_json_encode($res['error'] ? $res['error']->to_array() : null));
check('stream delivered live chunks', $res['streamed'] === true && $events > 5, "events=$events");
check('stream content equals streamed text', $collected['content'] === $res['content'] && strpos($res['content'], 'Hello from moonshotai/kimi-k3. You said: Stream please.') === 0, $res['content']);
check('stream reasoning separated', strpos($collected['reasoning'], 'step by step') !== false && strpos($collected['content'], 'step by step') === false);
check('stream finish + usage', $res['finish_reason'] === 'stop' && $res['usage']['total_tokens'] === 59);
$req = last_request($mockLog);
check('stream request headers/body', $req['body']['stream'] === true && $req['accept'] === 'text/event-stream');

$crlf = ['content' => ''];
$res = $client->stream(['model' => 'meta/llama-3.1-8b-instruct', 'messages' => [['role' => 'user', 'content' => 'crlf test']]],
    function ($ch, $t) use (&$crlf) { $crlf[$ch] = ($crlf[$ch] ?? '') . $t; }, fn() => true);
check('CRLF stream parsed', $res['ok'] && $crlf['content'] === 'Hello from meta/llama-3.1-8b-instruct. You said: crlf test. Turn 1, reasoning passed back: 0.', $crlf['content']);

$th = ['content' => '', 'reasoning' => ''];
$res = $client->stream(['model' => 'acme/think-tags', 'messages' => [['role' => 'user', 'content' => 'x']]],
    function ($ch, $t) use (&$th) { $th[$ch] .= $t; }, fn() => true);
check('streamed <think> tags routed to reasoning', $res['ok'] && $th['content'] === 'Final answer without tags.' && $th['reasoning'] === 'internal plan here', wp_json_encode($th));
$res = $client->chat(['model' => 'acme/think-tags', 'messages' => [['role' => 'user', 'content' => 'x']]]);
check('non-stream <think> tags split', $res['content'] === 'Final answer without tags.' && $res['reasoning'] === 'internal plan here');

$part = '';
$res = $client->stream(['model' => 'acme/interrupt', 'messages' => [['role' => 'user', 'content' => 'x']]],
    function ($ch, $t) use (&$part) { $part .= $t; }, fn() => true);
check('interrupted stream detected', !$res['ok'] && $res['error']->code === 'stream_interrupted' && $part !== '', $res['error'] ? $res['error']->code : 'none');

$ticks = 0;
$res = $client->stream((new PayloadBuilder())->chat($kimi, ModelRegistry::settings('moonshotai/kimi-k3'), [['role' => 'user', 'text' => 'cancel me']], true),
    function () {}, function () use (&$ticks) { $ticks++; return $ticks < 2; });
check('stream cancellation stops request', !$res['ok'] && $res['error']->code === 'cancelled', $res['error'] ? $res['error']->code : 'none');

foreach ([['acme/no-access', 'model_not_available', 404], ['acme/forbidden', 'access_denied', 403], ['acme/error500', 'service_error', 500]] as [$m, $code, $http]) {
    $r = $client->chat(['model' => $m, 'messages' => [['role' => 'user', 'content' => 'x']]]);
    check("chat $m -> $code", !$r['ok'] && $r['error']->code === $code && $r['status'] === $http, $r['error'] ? $r['error']->code . '/' . $r['status'] : '');
    $r = $client->stream(['model' => $m, 'messages' => [['role' => 'user', 'content' => 'x']]], function () {}, fn() => true);
    check("stream $m -> $code", !$r['ok'] && $r['error']->code === $code, $r['error'] ? $r['error']->code : '');
}
@unlink(dirname($mockLog) . '/ratelimit.count');
$start = microtime(true);
$r = $client->chat(['model' => 'acme/ratelimit', 'messages' => [['role' => 'user', 'content' => 'x']]]);
check('429 retried once after Retry-After then succeeds', $r['ok'] && (microtime(true) - $start) >= 1.0);
Settings::update(['max_retries' => 0]);
@unlink(dirname($mockLog) . '/ratelimit.count');
$r = NvidiaClient::create()->chat(['model' => 'acme/ratelimit', 'messages' => [['role' => 'user', 'content' => 'x']]]);
check('429 not retried when retries = 0', !$r['ok'] && $r['error']->code === 'rate_limited' && $r['error']->retryAfter === 1);
Settings::update(['max_retries' => 1]);
$r = NvidiaClient::create()->chat(['model' => 'moonshotai/kimi-k3', 'messages' => [['role' => 'user', 'content' => 'x']], 'reasoning_effort' => 'medium']);
check('NVIDIA-rejected parameter surfaces real reason', !$r['ok'] && $r['error']->code === 'unsupported_parameter' && strpos($r['error']->detail, 'reasoning_effort') !== false, $r['error'] ? $r['error']->detail : '');
$r = NvidiaClient::create()->chat(['model' => 'moonshotai/kimi-k3', 'messages' => [['role' => 'user', 'content' => 'x']], 'temperature' => 0.5]);
check('temperature rejection shows NVIDIA reason (no silent change)', !$r['ok'] && strpos($r['error']->detail, 'temperature') !== false);

Settings::save_api_key('nvapi-invalid-key-0000');
$r = NvidiaClient::create()->probe('moonshotai/kimi-k3');
check('invalid key -> invalid_api_key (not model error)', !$r['ok'] && $r['error']->code === 'invalid_api_key' && $r['error']->is_key_error());
Settings::delete_api_key();
check('missing key -> missing_api_key', NvidiaClient::create() instanceof ApiError && NvidiaClient::create()->code === 'missing_api_key');
Settings::save_api_key('nvapi-TestKey_123-abcdef');

echo "== WordPress AI Client integration ==\n";
$registry = AiClient::defaultRegistry();
Plugin::bind_ai_client_key();
$auth = $registry->getProviderRequestAuthentication('nvidia');
check('plugin key bound to AI Client (fallback, no WP key)', $auth instanceof ApiKeyRequestAuthentication && $auth->getApiKey() === 'nvapi-TestKey_123-abcdef');
check('AI Client sees nvidia provider configured', $registry->isProviderConfigured('nvidia'));

$dir = $registry->getProviderClassName('nvidia')::modelMetadataDirectory();
$ids = array_map(fn($m) => $m->getId(), $dir->listModelMetadata());
check('unverified Kimi K3 not offered yet', !in_array('moonshotai/kimi-k3', $ids, true));
check('catalogue-listed built-ins offered', in_array('meta/llama-3.3-70b-instruct', $ids, true) && !in_array('mistralai/mixtral-8x7b-instruct-v0.1', $ids, true));
check('FLUX image models offered (separate endpoint)', in_array('black-forest-labs/flux.1-dev', $ids, true));
if (method_exists(\WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory::class, 'createModelMetadataForExplicitModelIds')) {
    check('explicit Kimi K3 usable before verification (AI Client >= 1.4)', $dir->hasModelMetadata('moonshotai/kimi-k3'));
} else {
    check('AI Client ' . AiClient::VERSION . ': unverified Kimi K3 not usable until access confirmed', !$dir->hasModelMetadata('moonshotai/kimi-k3'));
}
ModelRegistry::set_status('moonshotai/kimi-k3', 'verified', 200);
$ids = array_map(fn($m) => $m->getId(), $dir->listModelMetadata());
check('verified Kimi K3 offered and listed first (default model)', ($ids[0] ?? '') === 'moonshotai/kimi-k3', $ids[0] ?? '');
ModelRegistry::set_enabled('meta/llama-3.3-70b-instruct', false);
$ids = array_map(fn($m) => $m->getId(), $dir->listModelMetadata());
check('disabled model removed from AI Client list immediately', !in_array('meta/llama-3.3-70b-instruct', $ids, true));
ModelRegistry::set_enabled('meta/llama-3.3-70b-instruct', true);

// Text generation through the AI Client with defaults from the dashboard.
$result = AiClient::prompt('Hello through the AI Client')->usingProvider('nvidia')->usingModelPreference('moonshotai/kimi-k3')->generateTextResult();
$req = last_request($mockLog);
check('AI Client -> Kimi K3 uses exact model id', ($req['body']['model'] ?? '') === 'moonshotai/kimi-k3');
check('AI Client gets dashboard defaults (temp 1, 16384, max, seed 0)', ($req['body']['temperature'] ?? null) == 1 && ($req['body']['max_tokens'] ?? 0) === 16384 && ($req['body']['reasoning_effort'] ?? '') === 'max' && ($req['body']['seed'] ?? -1) === 0, wp_json_encode($req['body']));
check('AI Client result text', strpos($result->toText(), 'Hello from moonshotai/kimi-k3') === 0, $result->toText());
check('writing style applied to AI Client requests (default on)', strpos($req['body']['messages'][0]['content'] ?? '', 'WRITING STYLE FOR ALL READER-FACING CONTENT') !== false);
$result = AiClient::prompt('caller temperature')->usingProvider('nvidia')->usingModelPreference('moonshotai/kimi-k3')->usingTemperature(1.0)->usingMaxTokens(100)->generateTextResult();
$req = last_request($mockLog);
check('caller-set max_tokens respected', ($req['body']['max_tokens'] ?? 0) === 100);

// Multi-turn with thought parts: Kimi must get reasoning_content back in one assistant message.
$history = [
    new Message(MessageRoleEnum::user(), [new MessagePart('First question')]),
    new Message(MessageRoleEnum::model(), [new MessagePart('my private reasoning', MessagePartChannelEnum::thought()), new MessagePart('First answer')]),
    new Message(MessageRoleEnum::user(), [new MessagePart('Follow up')]),
];
$result = AiClient::prompt($history)->usingProvider('nvidia')->usingModelPreference('moonshotai/kimi-k3')->generateTextResult();
$req = last_request($mockLog);
$assistant = array_values(array_filter($req['body']['messages'], fn($m) => $m['role'] === 'assistant'));
check('AI Client multi-turn passes reasoning_content to Kimi', count($assistant) === 1 && ($assistant[0]['reasoning_content'] ?? '') === 'my private reasoning' && $assistant[0]['content'] === 'First answer', wp_json_encode($assistant));
$result = AiClient::prompt($history)->usingProvider('nvidia')->usingModelPreference('meta/llama-3.1-8b-instruct')->generateTextResult();
$req = last_request($mockLog);
$assistant = array_values(array_filter($req['body']['messages'], fn($m) => $m['role'] === 'assistant'));
check('original models keep original behaviour (no reasoning_content, no defaults)', !isset($assistant[0]['reasoning_content']) && !isset($req['body']['temperature']) && !isset($req['body']['max_tokens']) && !isset($req['body']['seed']), wp_json_encode($req['body']));

// Image input through the AI Client (vision model).
$result = AiClient::prompt([new Message(MessageRoleEnum::user(), [new MessagePart('Describe'), new MessagePart(new \WordPress\AiClient\Files\DTO\File(png_data_uri(), 'image/png'))])])
    ->usingProvider('nvidia')->usingModelPreference('moonshotai/kimi-k3')->generateTextResult();
$req = last_request($mockLog);
check('AI Client image input reaches Kimi as image_url', is_array($req['body']['messages'][1]['content'] ?? null) && $req['body']['messages'][1]['content'][1]['type'] === 'image_url' && strpos($result->toText(), '1 image') !== false, $result->toText());

// Invalid reasoning via custom option -> clear SDK exception, no request sent.
$before = count(file($mockLog));
$caught = '';
try {
    AiClient::prompt('x')->usingProvider('nvidia')->usingModelPreference('moonshotai/kimi-k3')->usingModelConfig(\WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray(['customOptions' => ['reasoning_effort' => 'medium']]))->generateTextResult();
} catch (\Throwable $e) {
    $caught = $e->getMessage();
}
check('AI Client invalid reasoning effort rejected before sending', strpos($caught, 'not supported by Kimi K3') !== false && count(file($mockLog)) === $before, $caught);

// Access status tracking from AI Client errors.
// A model that was verified earlier and then loses access (the mock answers 404 for it).
ModelRegistry::set_status('acme/no-access', 'verified', 200);
$caught = false;
try {
    AiClient::prompt('x')->usingProvider('nvidia')->usingModelPreference('acme/no-access')->generateTextResult();
} catch (\Throwable $e) {
    $caught = true;
}
ModelRegistry::flush();
check('AI Client 404 marks model not available', $caught && (ModelRegistry::get('acme/no-access')['status']['state'] ?? '') === 'not_available');
$ids = array_map(fn($m) => $m->getId(), $dir->listModelMetadata());
check('model that lost access is no longer offered', !in_array('acme/no-access', $ids, true));

// Key binding modes.
update_option('connectors_ai_nvidia_api_key', 'nvapi-ConnectorsKey-999');
$registry->setProviderRequestAuthentication('nvidia', new ApiKeyRequestAuthentication('nvapi-ConnectorsKey-999'));
Settings::update(['ai_client_key_mode' => 'fallback']);
Plugin::bind_ai_client_key();
check('fallback mode keeps existing WordPress key', $registry->getProviderRequestAuthentication('nvidia')->getApiKey() === 'nvapi-ConnectorsKey-999');
Settings::update(['ai_client_key_mode' => 'always']);
Plugin::bind_ai_client_key();
check('always mode uses plugin key', $registry->getProviderRequestAuthentication('nvidia')->getApiKey() === 'nvapi-TestKey_123-abcdef');
delete_option('connectors_ai_nvidia_api_key');
Settings::update(['ai_client_key_mode' => 'fallback']);

echo "== Writing style ==\n";
delete_option(IndianEnglishPolicy::OPTION);
$style = IndianEnglishPolicy::instruction();
check('built-in writing style in use', !IndianEnglishPolicy::is_customised() && strpos($style, 'Write as a person who knows the subject') !== false);
check('style covers rhythm, active voice and self-check', strpos($style, '2. SENTENCE RHYTHM') !== false && strpos($style, '3. ACTIVE VOICE') !== false && strpos($style, '11. CHECK BEFORE DELIVERING') !== false);
check('style lists banned words', strpos($style, 'leverage, robust, seamless') !== false && strpos($style, 'In conclusion') !== false);
check('style keeps code and JSON intact', strpos($style, 'Keep code, commands, JSON, HTML, URLs') !== false);
check('style text itself has no em dash', !IndianEnglishPolicy::containsEmDash($style));
$kimiModel = ModelRegistry::get('moonshotai/kimi-k3');
$pl = (new PayloadBuilder())->chat($kimiModel, ModelRegistry::settings('moonshotai/kimi-k3'), [['role' => 'user', 'text' => 'Hi']], false, 'You help a Kolkata bakery.', true);
check('Playground sends system prompt + writing style', $pl['messages'][0]['role'] === 'system' && strpos($pl['messages'][0]['content'], 'You help a Kolkata bakery.') === 0 && strpos($pl['messages'][0]['content'], 'WRITING STYLE') !== false);
$pl = (new PayloadBuilder())->chat($kimiModel, ModelRegistry::settings('moonshotai/kimi-k3'), [['role' => 'user', 'text' => 'Hi']], false, '', false);
check('Playground without style sends no system message', $pl['messages'][0]['role'] === 'user');
check('saving custom rules works', IndianEnglishPolicy::save("MY RULES\r\nWrite short answers.") === null && IndianEnglishPolicy::is_customised() && IndianEnglishPolicy::instruction() === "MY RULES\nWrite short answers.");
AiClient::prompt('custom style check')->usingProvider('nvidia')->usingModelPreference('moonshotai/kimi-k3')->generateTextResult();
$req = last_request($mockLog);
check('AI Client uses edited rules', strpos($req['body']['messages'][0]['content'], 'MY RULES') !== false && strpos($req['body']['messages'][0]['content'], 'WRITING STYLE') === false);
check('too-long rules refused, old rules kept', IndianEnglishPolicy::save(str_repeat('a', IndianEnglishPolicy::MAX_LENGTH + 1)) !== null && IndianEnglishPolicy::instruction() === "MY RULES\nWrite short answers.");
IndianEnglishPolicy::save(IndianEnglishPolicy::default_instruction());
check('saving the built-in text clears the custom copy', !IndianEnglishPolicy::is_customised());
IndianEnglishPolicy::save('X'); IndianEnglishPolicy::save('');
check('reset (empty) returns to built-in rules', !IndianEnglishPolicy::is_customised() && IndianEnglishPolicy::instruction() === IndianEnglishPolicy::default_instruction());
Settings::update(['editorial_policy' => 0]);
AiClient::prompt('style off')->usingProvider('nvidia')->usingModelPreference('moonshotai/kimi-k3')->generateTextResult();
$req = last_request($mockLog);
check('style off: no system message for AI Client', $req['body']['messages'][0]['role'] === 'user');
Settings::update(['editorial_policy' => 1]);

echo "== Logging ==\n";
Logger::clear();
Logger::log(['type' => 't', 'model' => 'm/x', 'status' => 200]);
check('logging off by default -> nothing stored', Logger::entries() === []);
Settings::update(['logging' => 1]);
Logger::log(['type' => 'playground', 'model' => 'moonshotai/kimi-k3', 'status' => 401, 'error' => 'bad nvapi-TestKey_123-abcdef Bearer abc.def', 'prompt' => 'secret prompt', 'response' => 'r']);
$e = Logger::entries();
check('log entry stored without content by default', count($e) === 1 && !isset($e[0]['prompt']) && !isset($e[0]['response']));
check('log redacts key and bearer', strpos(wp_json_encode($e), 'TestKey') === false && strpos(wp_json_encode($e), 'abc.def') === false, wp_json_encode($e));
Settings::update(['log_content' => 1]);
Logger::log(['type' => 'playground', 'model' => 'm/x', 'prompt' => 'look data:image/png;base64,AAAA', 'response' => 'ok']);
$e = Logger::entries();
check('content logging opt-in works and strips image data', isset($e[1]['prompt']) && strpos($e[1]['prompt'], 'AAAA') === false);
update_option(Logger::OPTION, [['time' => time() - 30 * DAY_IN_SECONDS, 'type' => 'old'], ['time' => time(), 'type' => 'new']], false);
Logger::apply_retention();
check('retention prunes old entries', count(Logger::entries()) === 1);
Logger::clear();
check('clear logs', Logger::entries() === []);
Settings::update(['logging' => 0, 'log_content' => 0]);

echo "== Update / lifecycle safety ==\n";
check('auto-update forced off for this plugin', Plugin::disable_auto_update(true, (object) ['plugin' => 'chandan-digital-ai-for-nvidia/chandan-digital-ai-for-nvidia.php']) === false);
check('other plugins auto-update untouched', Plugin::disable_auto_update(true, (object) ['plugin' => 'akismet/akismet.php']) === true);
$headers = get_plugin_data(WP_PLUGIN_DIR . '/chandan-digital-ai-for-nvidia/chandan-digital-ai-for-nvidia.php', false, false);
check('Update URI header set off WordPress.org', strpos($headers['UpdateURI'], 'chandandigital.com') !== false);
check('plugin header branding', $headers['Name'] === 'Chandan Digital AI for NVIDIA' && $headers['Author'] === 'Chandan Digital' && $headers['Version'] === '1.1.1');
$before = get_option(Settings::OPTION);
Plugin::activate();
check('re-activation keeps existing settings', get_option(Settings::OPTION) === $before);
check('re-activation keeps saved key', Settings::api_key() === 'nvapi-TestKey_123-abcdef');
check('no cron scheduled by default', wp_next_scheduled(Plugin::REFRESH_EVENT) === false);

echo "\nRESULT: " . $GLOBALS['cdnv_pass'] . ' passed, ' . count($GLOBALS['cdnv_fail']) . " failed\n";
foreach ($GLOBALS['cdnv_fail'] as $f) { echo "  - $f\n"; }
