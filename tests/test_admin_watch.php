<?php
// WordPress-free session, throttling and upload-stability regression tests.
require_once dirname(__DIR__) . '/includes/class-ship-scss-admin-watch.php';
$watch_capability = false; $watch_admin = true; $watch_logged_in = true; $watch_roles = array('administrator');
$watch_nonce = false; $watch_hooks = array(); $watch_scripts = array(); $watch_headers = 0;
function current_user_can($capability) { return $GLOBALS['watch_capability']; }
function is_user_logged_in() { return $GLOBALS['watch_logged_in']; }
function wp_get_current_user() { return (object) array('roles' => $GLOBALS['watch_roles']); }
function is_admin() { return $GLOBALS['watch_admin']; }
function is_network_admin() { return false; }
function nocache_headers() { $GLOBALS['watch_headers']++; }
function add_action($hook, $callback, $priority = 10) { $GLOBALS['watch_hooks'][] = $hook; }
function wp_enqueue_script() { $GLOBALS['watch_scripts'][] = 'script'; }
function wp_localize_script() { $GLOBALS['watch_scripts'][] = 'config'; }
function plugins_url($path, $file) { return '/plugin/' . $path; }
function admin_url($path) { return '/admin/' . $path; }
function wp_create_nonce($action) { return 'fixture-nonce'; }
function check_ajax_referer($action, $query) { if (!$GLOBALS['watch_nonce']) { throw new RuntimeException('bad_nonce'); } }
function wp_send_json_error($data, $status) { throw new RuntimeException('http_' . $status); }
define('SHIP_SCSS_COMPILER_FILE', dirname(__DIR__) . '/ship-scss-compiler.php');
define('SHIP_SCSS_COMPILER_VERSION', '1.3.9');
function watch_assert($test, $name) {
    if (!$test) { throw new RuntimeException('FAIL: ' . $name); }
    echo 'ok: ' . $name . "\n";
}
$fixture = sys_get_temp_dir() . '/ship-watch-test-' . bin2hex(random_bytes(6));
mkdir($fixture . '/site/scss', 0700, true);
mkdir($fixture . '/site/css', 0700, true);
$config = array('wp_root' => $fixture . '/site', 'theme' => $fixture . '/site', 'input' => $fixture . '/site/scss', 'settings' => 'test');
$source = $config['input'] . '/_colors.scss';
file_put_contents($source, '$color: #112233;');
$watch = new Ship_SCSS_Admin_Watch($fixture . '/private');
$watch->attach();
watch_assert(in_array('wp_ajax_ship_scss_watch_lease', $watch_hooks, true) &&
    !in_array('wp_ajax_nopriv_ship_scss_watch_lease', $watch_hooks, true) && in_array('wp_enqueue_scripts', $watch_hooks, true),
    'monitor supports frontend enqueue but never anonymous AJAX');
$watch->enqueue();
watch_assert(!$watch_scripts, 'non-administrators receive no monitoring script or configuration');
$watch_capability = true; $watch->enqueue();
watch_assert($watch_scripts === array('script', 'config'), 'administrators receive the polling script and nonce configuration');
$watch_scripts = array(); $watch_admin = false;
$watch->enqueue();
watch_assert($watch_scripts === array('script', 'config'), 'logged-in administrator receives frontend monitoring');
$bar = new class {
    public $nodes = array();
    public function add_node($node) { $this->nodes[] = $node; }
};
$watch->admin_bar($bar);
watch_assert(count($bar->nodes) === 1, 'frontend administrator sees the monitoring status in the admin bar');
$watch->prevent_frontend_cache();
watch_assert($watch_headers === 1 && defined('DONOTCACHEPAGE') && DONOTCACHEPAGE,
    'administrator monitoring page is marked private/non-cacheable');
foreach (array('editor', 'subscriber', 'shop_manager') as $role) {
    $watch_roles = array($role); $watch_scripts = array(); $bar->nodes = array();
    $watch->enqueue(); $watch->admin_bar($bar);
    $watch->prevent_frontend_cache();
    watch_assert(!$watch_scripts && !$bar->nodes && !Ship_SCSS_Admin_Watch::can_watch() && $watch_headers === 1,
        $role . ' is excluded even when granted manage_options');
}
$watch_roles = array('administrator'); $watch_logged_in = false; $watch_scripts = array();
$watch->enqueue();
$watch->prevent_frontend_cache();
watch_assert(!$watch_scripts && !Ship_SCSS_Admin_Watch::can_watch() && $watch_headers === 1,
    'anonymous visitor receives no monitoring or cache-header changes even with stale role data');
$watch_logged_in = true; $watch_admin = true;
try { $watch->ajax_lease(); watch_assert(false, 'bad nonce accepted'); }
catch (RuntimeException $error) { watch_assert($error->getMessage() === 'bad_nonce', 'lease issuance requires a valid WordPress nonce'); }
$watch_nonce = true; $watch_roles = array('editor');
try { $watch->ajax_lease(); watch_assert(false, 'editor accepted'); }
catch (RuntimeException $error) { watch_assert($error->getMessage() === 'http_403', 'editor cannot issue a lease despite manage_options'); }
$watch_roles = array('administrator');
$watch_nonce = true; $watch_capability = false;
try { $watch->ajax_lease(); watch_assert(false, 'non-admin accepted'); }
catch (RuntimeException $error) { watch_assert($error->getMessage() === 'http_403', 'lease issuance requires manage_options'); }
$lease = $watch->issue($config, 'session', 'admin-cookie', 7, 100);
$cookies = array('session' => 'admin-cookie');
$calls = 0; $mode = 'ok';
$compile = function () use (&$calls, &$mode, $fixture) {
    $calls++;
    if ($mode === 'throw') { throw new RuntimeException('fixture failure'); }
    if ($mode === 'error') { return array('ok' => false); }
    if ($mode === 'busy') { return array('locked' => true); }
    file_put_contents($fixture . '/site/css/main.css', '.test{color:red}');
    return array('ok' => true, 'compiled' => 1);
};
$poll = function ($time, $token = null, $session = null) use ($watch, $lease, $cookies, $compile) {
    return $watch->poll($token === null ? $lease['token'] : $token, $session === null ? $cookies : $session, $time, $compile);
};
watch_assert($poll(100, '../escape')['http'] === 403 && $calls === 0, 'malformed token cannot probe or bootstrap WordPress');
watch_assert($poll(100, str_repeat('a', 64))['http'] === 410 && $calls === 0, 'unknown token cannot bootstrap WordPress');
watch_assert($poll(100, null, array('session' => 'other'))['http'] === 403, 'token is bound to the issuing login cookie');
watch_assert($poll(100, null, array())['http'] === 403, 'logout cookie removal stops probing');
watch_assert($poll(100)['status'] === 'waiting' && $calls === 0, 'initial input waits for stable content');
watch_assert($poll(101)['status'] === 'waiting' && $calls === 0, 'shared two-second throttle avoids repeated scans');
watch_assert($poll(102)['status'] === 'compiled' && $calls === 1, 'stable initial input starts a single compiler callback');
watch_assert($poll(104)['status'] === 'unchanged' && $calls === 1, 'unchanged input does not bootstrap WordPress');
$mtime = filemtime($source);
file_put_contents($source, '$color: #445566;'); touch($source, $mtime); clearstatcache();
watch_assert($poll(106)['status'] === 'waiting' && $calls === 1, 'content hash detects equal-size equal-mtime FTP edits');
file_put_contents($source, '$color: #778899;'); clearstatcache();
watch_assert($poll(108)['status'] === 'waiting' && $calls === 1, 'continued transfer resets the stability window');
watch_assert($poll(110)['status'] === 'compiled' && $calls === 2, 'completed transfer compiles without force');
$other = $watch->issue($config, 'session2', 'other-admin', 8, 111);
$watch->poll($other['token'], array('session2' => 'other-admin'), 111, $compile);
watch_assert($calls === 2, 'multiple admin sessions share the probe throttle and acknowledgment');
file_put_contents($source, 'invalid scss'); clearstatcache();
$mode = 'error'; $old_css = file_get_contents($fixture . '/site/css/main.css');
$poll(112); $poll(114);
watch_assert($calls === 3 && $poll(116)['status'] === 'error' && $calls === 3 &&
    file_get_contents($fixture . '/site/css/main.css') === $old_css, 'identical failure is cooled down while old CSS survives');
file_put_contents($source, '$color: red;'); clearstatcache(); $mode = 'ok';
$poll(118); $poll(120);
watch_assert($calls === 4, 'corrected FTP content bypasses the failure cooldown');
file_put_contents($source, '$color: blue;'); clearstatcache(); $mode = 'busy';
$poll(122); $poll(124); $mode = 'ok'; $poll(126);
watch_assert($calls === 6, 'compiler lock conflict is retried without acknowledging or cooling down');
file_put_contents($source, '$color: pink;'); clearstatcache(); $mode = 'throw';
$poll(128); $poll(130); $poll(132);
watch_assert($calls === 7, 'bootstrap exception is throttled instead of repeatedly starting WordPress');
$renewed = $watch->issue($config, 'session', 'admin-cookie', 7, 140, $lease['token']);
watch_assert($renewed['token'] === $lease['token'], 'lease renewal reuses the session token instead of growing files');
watch_assert($poll(321)['http'] === 410, 'expired lease cannot scan or compile');
$bad = new Ship_SCSS_Admin_Watch($fixture . '/site/public-state');
try { $bad->issue($config, 'session', 'admin-cookie', 7, 140); watch_assert(false, 'public state forbidden'); }
catch (RuntimeException $error) { watch_assert($error->getMessage() === 'Watch state must not be public.', 'leases cannot be stored under the public WordPress root'); }
mkdir($fixture . '/unsafe', 0777); chmod($fixture . '/unsafe', 0777);
try { (new Ship_SCSS_Admin_Watch($fixture . '/unsafe'))->issue($config, 'session', 'admin-cookie', 7, 140); watch_assert(false, 'unsafe state forbidden'); }
catch (RuntimeException $error) { watch_assert($error->getMessage() === 'Unsafe watch directory.', 'world-readable or writable lease directory is rejected'); }
$watch->issue($config, 'session', 'admin-cookie', 7, 400);
watch_assert(count(glob($fixture . '/private/lease-*.json')) === 1, 'expired lease files are cleaned during issuance');
echo "All admin watch tests passed.\n";
