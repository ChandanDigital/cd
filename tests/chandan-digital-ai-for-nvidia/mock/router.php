<?php
// Mock of NVIDIA's OpenAI-compatible API for local testing only. Not part of the plugin.
// Behaviour switches on the model ID and API key so each error path can be exercised.

$dir = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$raw = file_get_contents('php://input');

file_put_contents($dir . '/requests.log', json_encode([
    'time' => microtime(true),
    'method' => $method,
    'path' => $path,
    'auth' => $auth,
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
    'body' => json_decode($raw, true),
]) . "\n", FILE_APPEND);

function out_json(int $status, array $data, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo json_encode($data);
    exit;
}

if (!preg_match('/^Bearer (.+)$/', $auth, $m)) {
    out_json(401, ['status' => 401, 'title' => 'Unauthorized', 'detail' => 'Missing Authorization header']);
}
$key = $m[1];
if ($key === 'nvapi-invalid-key-0000') {
    out_json(401, ['status' => 401, 'title' => 'Unauthorized', 'detail' => 'Authentication failed']);
}

if ($method === 'GET' && $path === '/v1/models') {
    $ids = ['meta/llama-3.3-70b-instruct', 'meta/llama-3.1-8b-instruct', 'moonshotai/kimi-k3', 'moonshotai/kimi-k2.6',
        'nvidia/nemotron-3-super-120b-a12b', 'meta/llama-3.2-90b-vision-instruct', 'acme/new-model-1', 'acme/new-model-2'];
    out_json(200, ['object' => 'list', 'data' => array_map(fn($id) => ['id' => $id, 'object' => 'model', 'owned_by' => explode('/', $id)[0]], $ids)]);
}

if ($method !== 'POST' || $path !== '/v1/chat/completions') {
    out_json(404, ['status' => 404, 'title' => 'Not Found', 'detail' => 'No route']);
}

$body = json_decode($raw, true);
if (!is_array($body) || empty($body['model']) || empty($body['messages'])) {
    out_json(400, ['error' => ['message' => 'model and messages are required', 'type' => 'invalid_request_error']]);
}
$model = $body['model'];
$stream = !empty($body['stream']);

if ($model === 'acme/no-access') {
    out_json(404, ['status' => 404, 'title' => 'Not Found', 'detail' => "Function 'abc-123': Not found for account 'acct-xyz'"]);
}
if ($model === 'acme/forbidden') {
    out_json(403, ['status' => 403, 'title' => 'Forbidden', 'detail' => 'This model requires an enterprise subscription.']);
}
if ($model === 'acme/error500') {
    out_json(500, ['status' => 500, 'title' => 'Internal Server Error', 'detail' => 'Inference worker crashed']);
}
if ($model === 'acme/ratelimit') {
    $counter = $dir . '/ratelimit.count';
    $n = (int) @file_get_contents($counter);
    file_put_contents($counter, (string) ($n + 1));
    if ($n % 2 === 0) {
        out_json(429, ['status' => 429, 'title' => 'Too Many Requests', 'detail' => 'Rate limit exceeded'], ['Retry-After' => '1']);
    }
}
if ($model === 'moonshotai/kimi-k3') {
    if (isset($body['reasoning_effort']) && !in_array($body['reasoning_effort'], ['low', 'high', 'max'], true)) {
        out_json(400, ['error' => ['message' => "Invalid value for 'reasoning_effort': '{$body['reasoning_effort']}'. Supported values are low, high, max.", 'param' => 'reasoning_effort', 'type' => 'invalid_request_error']]);
    }
    if (isset($body['temperature']) && (float) $body['temperature'] !== 1.0) {
        out_json(400, ['error' => ['message' => 'temperature must be 1 for this model', 'param' => 'temperature', 'type' => 'invalid_request_error']]);
    }
}

// Inspect the last user message.
$last = end($body['messages']);
$images = 0;
$text = '';
if (is_array($last['content'])) {
    foreach ($last['content'] as $part) {
        if (($part['type'] ?? '') === 'image_url') {
            $images++;
            $url = $part['image_url']['url'] ?? '';
            if (strpos($url, 'data:image/') !== 0 && strpos($url, 'https://') !== 0) {
                out_json(400, ['error' => ['message' => 'Invalid image_url: must be https or data URI']]);
            }
        } elseif (($part['type'] ?? '') === 'text') {
            $text .= $part['text'];
        }
    }
} else {
    $text = (string) $last['content'];
}
$assistantTurns = count(array_filter($body['messages'], fn($m) => $m['role'] === 'assistant'));
$passedBack = count(array_filter($body['messages'], fn($m) => $m['role'] === 'assistant' && isset($m['reasoning_content'])));

$reasoning = $model === 'moonshotai/kimi-k3'
    ? 'The user asked: "' . mb_substr($text, 0, 40) . '". Let me think step by step about this.'
    : '';
$answer = $images > 0
    ? "I can see {$images} image(s). Kimi says: नमस्ते, the picture looks clear."
    : "Hello from {$model}. You said: {$text}. Turn " . ($assistantTurns + 1) . ", reasoning passed back: {$passedBack}.";
$maxTokens = (int) ($body['max_tokens'] ?? 0);
$finish = ($maxTokens > 0 && $maxTokens <= 16) ? 'length' : 'stop';
if ($finish === 'length') {
    $answer = 'OK';
}
if ($model === 'acme/think-tags') {
    $reasoning = '';
    $answer = '<think>internal plan here</think>Final answer without tags.';
}
// Reproduce NVIDIA's hosted Kimi K3 failure: token salad with leaked template markers, or "!" loops.
$salad = "<|close|>我都.inline店oteric身贝rangSat\nWant武器зpa jpegadmin,X+cURRE丹LabelvoidASSEMBERambleinjections蛋inistrator The user asked a veryVictimresponse问询疗愈 options<|close|>contextMYASimplemy懦弱 fans<|close|>";
$flip = function (string $name) use ($dir): bool {
    $file = $dir . '/' . $name . '.count';
    $n = (int) @file_get_contents($file);
    file_put_contents($file, (string) ($n + 1));
    return $n % 2 === 0;
};
if (strpos($text, 'GARBLE_ALWAYS') !== false || (strpos($text, 'GARBLE_ONCE') !== false && $flip('garble'))) {
    $answer = 'Photosynthesis ' . $salad . ' more salad 文中ctionsXMLA';
}
if (strpos($text, 'BANG_ONCE') !== false && $flip('bang')) {
    $reasoning = 'Let me think ' . str_repeat('!', 80);
}
$usage = ['prompt_tokens' => 42, 'completion_tokens' => 17, 'total_tokens' => 59];

if (!$stream) {
    $message = ['role' => 'assistant', 'content' => $answer];
    if ($reasoning !== '') {
        $message['reasoning_content'] = $reasoning;
    }
    out_json(200, [
        'id' => 'chatcmpl-mock', 'object' => 'chat.completion', 'model' => $model,
        'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
        'usage' => $usage,
    ]);
}

// Streaming: deliberately awkward chunking to test the parser.
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
while (ob_get_level()) { ob_end_flush(); }
$crlf = $model === 'meta/llama-3.1-8b-instruct';
$nl = $crlf ? "\r\n" : "\n";

function send_raw(string $s): void { echo $s; flush(); }
function chunk_event(array $delta, ?string $finish, string $model, string $nl, ?array $usage = null): string {
    $data = ['id' => 'chatcmpl-mock', 'object' => 'chat.completion.chunk', 'model' => $model,
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]];
    if ($usage) { $data['usage'] = $usage; }
    return 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . $nl . $nl;
}

send_raw(': mock comment' . $nl . $nl);
send_raw(chunk_event(['role' => 'assistant', 'content' => ''], null, $model, $nl));
$pieces = [];
foreach (preg_split('/(?<= )/', $reasoning) as $w) { if ($w !== '') $pieces[] = ['reasoning_content' => $w]; }
// Split the answer into small pieces, including inside multi-byte characters' events and think tags.
$len = strlen($answer);
for ($i = 0; $i < $len; $i += 7) { $pieces[] = ['content' => substr($answer, $i, 7)]; }
// Repair any broken UTF-8 boundaries by merging pieces (JSON needs valid UTF-8).
$fixed = []; $carry = '';
foreach ($pieces as $p) {
    if (isset($p['content'])) {
        $c = $carry . $p['content'];
        if (!mb_check_encoding($c, 'UTF-8')) { $carry = $c; continue; }
        $carry = ''; $fixed[] = ['content' => $c];
    } else { $fixed[] = $p; }
}
if ($carry !== '') { $fixed[] = ['content' => $carry]; }

$n = 0;
foreach ($fixed as $delta) {
    $event = chunk_event($delta, null, $model, $nl);
    // Split each event at an awkward point (mid-JSON, and sometimes between \r and \n).
    $cut = $crlf ? strlen($event) - 3 : (int) (strlen($event) / 2);
    send_raw(substr($event, 0, $cut));
    usleep(30000);
    send_raw(substr($event, $cut));
    usleep(60000);
    $n++;
    if ($model === 'acme/interrupt' && $n === 4) {
        exit; // connection drops without [DONE]
    }
}
// Two events in one write, then the [DONE] marker.
send_raw(chunk_event([], $finish, $model, $nl) . chunk_event([], null, $model, $nl, $usage));
send_raw('data: [DONE]' . $nl . $nl);
