<?php
// No WordPress bootstrap on idle probes. Never accept paths or commands from HTTP.
header('Cache-Control: no-store, private');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('{"status":"method"}'); }
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024) { http_response_code(413); exit('{"status":"size"}'); }
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') { http_response_code(403); exit('{"status":"forbidden"}'); }
require_once __DIR__ . '/includes/class-ship-scss-admin-watch.php';
try {
    $watch = new Ship_SCSS_Admin_Watch();
    $result = $watch->poll($_POST['token'] ?? '', $_COOKIE, microtime(true), static function ($lease) {
        define('SHIP_SCSS_WATCH_REQUEST', true);
        define('DOING_AJAX', true);
        define('WP_ADMIN', true);
        ob_start();
        try { require $lease['config']['wp_root'] . '/wp-load.php'; }
        finally { ob_end_clean(); }
        $compiler = $GLOBALS['ship_scss_compiler'] ?? null;
        if (!$compiler || !defined('SHIP_SCSS_COMPILER_FILE') || realpath(SHIP_SCSS_COMPILER_FILE) !== realpath(__DIR__ . '/ship-scss-compiler.php') ||
            get_current_user_id() !== $lease['user'] || !Ship_SCSS_Admin_Watch::can_watch() ||
            Ship_SCSS_Admin_Watch::configuration($compiler) !== $lease['config']) {
            return array('renew' => true);
        }
        $report = $compiler->run(false, array(), 'external');
        $pending = false;
        foreach ($report['results'] ?? array() as $entry) { if (!empty($entry['retry_at'])) { $pending = true; } }
        return array('locked' => !empty($report['locked']) || !empty($report['duplicate']), 'ok' => !$pending && empty($report['counts']['failure']), 'compiled' => $report['counts']['success'] ?? 0);
    });
    http_response_code($result['http']); unset($result['http']);
    echo json_encode($result);
} catch (Throwable $error) {
    http_response_code(503); echo '{"status":"unavailable"}';
}
