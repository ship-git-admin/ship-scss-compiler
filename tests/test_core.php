<?php
/**
 * Small WordPress-free regression suite for the state/compile core.
 * Run with: php tests/test_core.php
 */

define('ABSPATH', __DIR__ . '/');
$options = array();
$theme_dir = sys_get_temp_dir() . '/ship-scss-test-' . uniqid('', true);
mkdir($theme_dir . '/scss/sub', 0755, true);
mkdir($theme_dir . '/css', 0755, true);

function add_action($a, $b, $c = 10, $d = 1) {}
function add_filter($a, $b, $c = 10, $d = 1) { if ($a === 'ship_scss_compiler_scan_interval') { $GLOBALS['ship_test_scan_filter'] = $b; } }
function apply_filters($tag, $value) { return isset($GLOBALS['ship_test_scan_filter']) && $tag === 'ship_scss_compiler_scan_interval' ? call_user_func($GLOBALS['ship_test_scan_filter'], $value) : $value; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['options'][$name]); }
function get_stylesheet_directory() { return $GLOBALS['theme_dir']; }
function get_stylesheet_directory_uri() { return 'https://example.test/wp-content/themes/test'; }
function trailingslashit($v) { return rtrim($v, '/') . '/'; }
function wp_json_encode($v) { return json_encode($v); }
function wp_strip_all_tags($v) { return strip_tags($v); }
function current_time($what) { return time(); }
function absint($v) { return abs((int) $v); }
function set_transient() {}
function get_transient() { return false; }
function delete_transient() {}
function get_current_user_id() { return 1; }
function wp_parse_url($url, $component = -1) { return $component === -1 ? parse_url($url) : (parse_url($url, $component) ?: null); }
function wp_date($format, $timestamp) { return date($format, $timestamp); }

$GLOBALS['theme_dir'] = $theme_dir;
require_once dirname(__DIR__) . '/scssphp/scss.inc.php';
require_once dirname(__DIR__) . '/includes/class-ship-scss-compiler.php';

function ship_test_assert($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "ok: {$message}\n";
}
function ship_test_reset_guard() {
    $ref = new ReflectionClass('Ship_SCSS_Compiler');
    $property = $ref->getProperty('ran_this_request');
    $property->setAccessible(true);
    $property->setValue(null, false);
    $settings = $ref->getProperty('settings_cache');
    $settings->setAccessible(true);
    $settings->setValue($GLOBALS['compiler_for_test'], null);
}
function ship_test_write($path, $contents) { file_put_contents($path, $contents); clearstatcache(true, $path); }

ship_test_write($theme_dir . '/scss/_shared.scss', '$color: #123456;');
ship_test_write($theme_dir . '/scss/home.scss', '@import "shared"; .home { color: $color; }');
ship_test_write($theme_dir . '/scss/other.scss', '.other { display: block; }');
ship_test_write($theme_dir . '/scss/empty.scss', '');
ship_test_write($theme_dir . '/scss/sub/page.scss', '.page { color: red; }');

$compiler = new Ship_SCSS_Compiler();
$GLOBALS['compiler_for_test'] = $compiler;
$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION] = Ship_SCSS_Compiler::defaults();
$GLOBALS['ship_test_scan_filter'] = function () { return 0; };

$report = $compiler->run(true, array(), 'manual-all');
ship_test_assert($report['counts']['success'] === 2 && $report['counts']['skipped'] === 1, 'default direct entrypoint compile and empty-file skip');
ship_test_assert(is_file($theme_dir . '/css/home.css') && is_file($theme_dir . '/css/other.css'), 'default CSS outputs exist');
ship_test_assert(!is_file($theme_dir . '/css/sub/page.css'), 'recursive entrypoint is not selected by default');

$home_before = file_get_contents($theme_dir . '/css/home.css');
$other_before = filemtime($theme_dir . '/css/other.css');
ship_test_write($theme_dir . '/scss/home.scss', '@import "shared"; .home { color: #654321; }');
ship_test_reset_guard();
$report = $compiler->run(false, array(), 'auto');
ship_test_assert($report['results']['scss/home.scss']['status'] === 'success', 'only changed entrypoint is recompiled');
ship_test_assert(file_get_contents($theme_dir . '/css/home.css') !== $home_before, 'changed CSS content is published');
ship_test_assert(filemtime($theme_dir . '/css/other.css') === $other_before, 'unrelated CSS is not regenerated');

$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['debug'] = true;
$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['embed_sources'] = false;
ship_test_reset_guard();
$report = $compiler->run(true, array(), 'manual-all');
ship_test_assert($report['counts']['success'] === 2, 'debug profile compiles successfully');
ship_test_assert(is_file($theme_dir . '/css/home.css.map'), 'debug source map is generated');
ship_test_assert(strpos(file_get_contents($theme_dir . '/css/home.css'), 'sourceMappingURL=home.css.map') !== false, 'debug CSS references external source map');
ship_test_assert(strpos(file_get_contents($theme_dir . '/css/home.css.map'), $theme_dir) === false && strpos(file_get_contents($theme_dir . '/css/home.css.map'), 'sourcesContent') === false, 'source map has no absolute path or embedded source by default');
ship_test_write($theme_dir . '/css/unmanaged.css.map', '{"version":3}');
$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['embed_sources'] = true;
ship_test_reset_guard();
$compiler->run(true, array(), 'manual-all');
ship_test_assert(strpos(file_get_contents($theme_dir . '/css/home.css.map'), 'sourcesContent') !== false, 'sourcesContent is independently opt-in');
$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['embed_sources'] = false;

$version = $compiler->css_version('css/home.css');
ship_test_assert(strlen($version) === 64, 'successful CSS content hash is persisted');
$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['cache_busting'] = true;
ship_test_reset_guard();
$busted = $compiler->filter_style_loader_src('https://example.test/wp-content/themes/test/css/home.css?foo=1#frag');
ship_test_assert(strpos($busted, 'foo=1') !== false && strpos($busted, 'ver=' . $version) !== false && substr($busted, -5) === '#frag', 'cache helper preserves query and fragment');
ship_test_assert($compiler->filter_style_loader_src('https://cdn.example.test/wp-content/themes/test/css/home.css?foo=1') === 'https://cdn.example.test/wp-content/themes/test/css/home.css?foo=1', 'external CSS URL is not rewritten');

ship_test_write($theme_dir . '/scss/_shared.scss', '$color: #abcdef;');
ship_test_reset_guard();
$report = $compiler->run(false, array(), 'auto');
ship_test_assert($report['results']['scss/home.scss']['status'] === 'success' && $report['results']['scss/other.scss']['status'] === 'skipped', 'partial change recompiles only its dependent entrypoint');

$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['debug'] = false;
ship_test_reset_guard();
$report = $compiler->run(true, array(), 'manual-all');
ship_test_assert(!is_file($theme_dir . '/css/home.css.map'), 'owned map is removed when debug is disabled');
ship_test_assert(is_file($theme_dir . '/css/unmanaged.css.map'), 'unmanaged map is not bulk-deleted');
ship_test_assert(strpos(file_get_contents($theme_dir . '/css/home.css'), 'sourceMappingURL') === false, 'production CSS has no source map URL');

ship_test_write($theme_dir . '/scss/safe.scss', '.safe { color: green; }');
ship_test_reset_guard();
$compiler->run(true, array(), 'manual-all');
$safe_before = file_get_contents($theme_dir . '/css/safe.css');
ship_test_write($theme_dir . '/scss/safe.scss', '.safe { color: ; }');
ship_test_reset_guard();
$report = $compiler->run(true, array(), 'manual-all');
ship_test_assert($report['results']['scss/safe.scss']['status'] === 'failure' && file_get_contents($theme_dir . '/css/safe.css') === $safe_before, 'failed replacement preserves existing public CSS');

ship_test_write($theme_dir . '/scss/broken.scss', '.broken { color: ; }');
ship_test_reset_guard();
$report = $compiler->run(true, array(), 'manual-all');
ship_test_assert($report['results']['scss/broken.scss']['status'] === 'failure', 'compile failure is reported');
ship_test_assert(!is_file($theme_dir . '/css/broken.css'), 'failed first compile does not create public CSS');
ship_test_reset_guard();
$report = $compiler->run(false, array(), 'auto');
ship_test_assert($report['results']['scss/broken.scss']['status'] === 'skipped', 'same failed input is retry-suppressed');

$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION] = array_merge(Ship_SCSS_Compiler::defaults(), array(
    'entry_mode' => 'explicit', 'include_subdirectories' => false,
    'entrypoints' => "sub/page.scss\nmissing.scss",
));
ship_test_reset_guard();
$report = $compiler->run(true, array(), 'manual-all');
ship_test_assert($report['results']['scss/sub/page.scss']['status'] === 'success', 'explicit nested entrypoint compiles');
ship_test_assert(is_file($theme_dir . '/css/sub/page.css'), 'nested output directory is mirrored safely');
ship_test_assert($report['results']['scss/missing.scss']['status'] === 'failure', 'missing explicit entrypoint is tracked as failure');

$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['entry_mode'] = 'auto';
$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['include_subdirectories'] = true;
$GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION]['entrypoints'] = '';
ship_test_reset_guard();
$report = $compiler->run(true, array('scss/sub/page.scss'), 'manual-selected');
ship_test_assert($report['results']['scss/sub/page.scss']['status'] === 'success', 'selected manual recompilation accepts only server-listed paths');

$lock_ref = new ReflectionClass('Ship_SCSS_Compiler');
$ctx_method = $lock_ref->getMethod('build_context'); $ctx_method->setAccessible(true);
$acquire_method = $lock_ref->getMethod('acquire_lock'); $acquire_method->setAccessible(true);
$release_method = $lock_ref->getMethod('release_lock'); $release_method->setAccessible(true);
$held_lock = $acquire_method->invoke($compiler, $ctx_method->invoke($compiler)['key']);
ship_test_reset_guard();
$report = $compiler->run(true, array(), 'manual-all');
ship_test_assert(!empty($report['locked']), 'concurrent lock is reported as not successful');
$release_method->invoke($compiler, $held_lock);

$logs = get_option(Ship_SCSS_Compiler::LOG_OPTION, array());
ship_test_assert(is_array($logs) && count($logs) >= 1 && count($logs) <= 100, 'failure logs are stored in bounded non-autoload state');
$valid_settings = $compiler->settings();
$rejected = $compiler->sanitize_settings(array_merge($valid_settings, array('input_dir' => '../outside')));
ship_test_assert($rejected === $valid_settings, 'unsafe settings preserve the previous valid configuration');

echo "All Ship SCSS Compiler core tests passed.\n";
