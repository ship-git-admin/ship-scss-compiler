<?php
// Remote-only HTTP gates against a private loopback PHP server, never public URLs.
list(, $plugin, $root, $wp_root, $port) = $argv;
require $plugin . '/includes/class-ship-scss-admin-watch.php';
$watch = new Ship_SCSS_Admin_Watch();
$config = array('wp_root' => $wp_root, 'theme' => $root, 'input' => $root . '/scss', 'settings' => 'http-fixture');
$lease = $watch->issue($config, 'fixture-session', 'fixture-cookie', 7, microtime(true));
function request_probe($port, $token, $cookie, $method = 'POST', $extra = array(), $body = null) {
    $body = $body === null ? http_build_query(array('token' => $token)) : $body;
    $headers = array_merge(array('Content-Type: application/x-www-form-urlencoded', 'Cookie: fixture-session=' . $cookie), $extra);
    $context = stream_context_create(array('http' => array('method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $method === 'POST' ? $body : '', 'ignore_errors' => true, 'timeout' => 15)));
    $data = file_get_contents('http://127.0.0.1:' . (int) $port . '/admin-watch.php', false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return array((int) $match[1], json_decode($data, true));
}
function http_expect($condition, $label) { if (!$condition) { throw new RuntimeException('FAIL: ' . $label); } echo 'ok: ' . $label . "\n"; }
http_expect(request_probe($port, '', '', 'GET')[0] === 405, 'HTTP rejects GET without bootstrapping WordPress');
http_expect(request_probe($port, 'invalid', '')[0] === 403, 'HTTP rejects malformed token');
http_expect(request_probe($port, str_repeat('b', 64), '')[0] === 410, 'HTTP rejects unknown lease');
http_expect(request_probe($port, $lease['token'], 'wrong')[0] === 403, 'HTTP requires the issuing cookie');
http_expect(request_probe($port, $lease['token'], 'fixture-cookie', 'POST', array('Sec-Fetch-Site: cross-site'))[0] === 403,
    'HTTP rejects cross-site probes');
http_expect(request_probe($port, '', '', 'POST', array(), str_repeat('x', 1025))[0] === 413, 'HTTP rejects oversized request body');
$waiting = request_probe($port, $lease['token'], 'fixture-cookie');
http_expect($waiting[0] === 200 && $waiting[1]['status'] === 'waiting', 'HTTP starts a stability window without a WordPress bootstrap');
usleep(2100000);
$renew = request_probe($port, $lease['token'], 'fixture-cookie');
http_expect($renew[0] === 409 && $renew[1]['status'] === 'renew', 'HTTP compiler gate rejects another installed plugin/context instead of modifying it');
// A separate acknowledged fixture proves the HTTP idle path, without compiling.
$config['settings'] = 'idle-http-fixture';
$lease = $watch->issue($config, 'fixture-session', 'fixture-cookie', 7, microtime(true), $lease['token']);
$compile_calls = 0;
$fake = function () use (&$compile_calls) { $compile_calls++; return array('ok' => true); };
$watch->poll($lease['token'], array('fixture-session' => 'fixture-cookie'), microtime(true) - 20, $fake);
$watch->poll($lease['token'], array('fixture-session' => 'fixture-cookie'), microtime(true) - 18, $fake);
$idle = request_probe($port, $lease['token'], 'fixture-cookie');
http_expect($idle[0] === 200 && $idle[1]['status'] === 'unchanged' && $compile_calls === 1, 'HTTP idle probe returns without loading WordPress');
echo "All private HTTP gate tests passed.\n";
