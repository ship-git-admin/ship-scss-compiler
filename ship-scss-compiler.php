<?php
/**
 * Plugin Name: Ship SCSS Compiler
 * Description: Compiles the active theme's SCSS files safely with scssphp.
 * Version: 1.3.1
 * Requires PHP: 7.2
 */

defined('ABSPATH') || exit;

define('SHIP_SCSS_COMPILER_VERSION', '1.3.1');
define('SHIP_SCSS_COMPILER_FILE', __FILE__);
define('SHIP_SCSS_COMPILER_DIR', plugin_dir_path(__FILE__));
define('SHIP_SCSS_COMPILER_REPOSITORY', 'https://github.com/ship-git-admin/ship-scss-compiler');
define('SHIP_SCSS_COMPILER_DEBUG_OPTION', 'ship_scss_compiler_debug');
define('SHIP_SCSS_COMPILER_PROFILE_OPTION', 'ship_scss_compiler_last_profile');

if (version_compare(PHP_VERSION, '7.2', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Ship SCSS Compiler requires PHP 7.2 or newer. Existing CSS files were left unchanged.</p></div>';
    });
    return;
}

require_once SHIP_SCSS_COMPILER_DIR . 'scssphp/scss.inc.php';
require_once SHIP_SCSS_COMPILER_DIR . 'includes/class-ship-scss-compiler.php';

/** @var Ship_SCSS_Compiler $ship_scss_compiler */
$ship_scss_compiler = new Ship_SCSS_Compiler();

/** Backwards-compatible helper wrappers used by previous deployments. */
function ship_scss_compiler_paths() {
    global $ship_scss_compiler;
    $paths = $ship_scss_compiler->paths();
    return array('scss' => trailingslashit($paths['scss']), 'css' => trailingslashit($paths['css']));
}

function ship_scss_compiler_debug_enabled() {
    global $ship_scss_compiler;
    return $ship_scss_compiler->debug_enabled();
}

function ship_scss_compiler_profile() {
    global $ship_scss_compiler;
    return $ship_scss_compiler->profile();
}

function ship_scss_compiler_log($message) {
    // Keep the old symbol available, but never recreate the former public
    // scss/error_log.log writer. WordPress/server error logging is private to
    // the hosting environment; compiler details are stored by the class.
    error_log('Ship SCSS Compiler: ' . wp_strip_all_tags((string) $message));
}

function ship_scss_compiler_sanitize_debug($value) {
    return empty($value) ? 0 : 1;
}

function ship_scss_compiler_register_settings() {
    global $ship_scss_compiler;
    $ship_scss_compiler->register_settings();
}

function ship_scss_compiler_add_settings_page() {
    global $ship_scss_compiler;
    $ship_scss_compiler->add_settings_page();
}

function ship_scss_compiler_render_settings_page() {
    global $ship_scss_compiler;
    $ship_scss_compiler->render_settings_page();
}

function ship_scss_compiler_maybe_run() {
    global $ship_scss_compiler;
    $ship_scss_compiler->maybe_run();
}

function ship_scss_compiler_entrypoints($scss_dir) {
    $entrypoints = array();
    if (!is_dir($scss_dir) || !is_readable($scss_dir)) {
        return $entrypoints;
    }
    try {
        foreach (new DirectoryIterator($scss_dir) as $file) {
            if ($file->isDot() || !$file->isFile() || strtolower($file->getExtension()) !== 'scss' || substr($file->getFilename(), 0, 1) === '_' || (int) @filesize($file->getPathname()) === 0) {
                continue;
            }
            $entrypoints[$file->getFilename()] = $file->getPathname();
        }
    } catch (Throwable $error) {
        ship_scss_compiler_log('Unable to scan legacy entrypoints: ' . $error->getMessage());
    }
    ksort($entrypoints);
    return $entrypoints;
}

function ship_scss_compiler_latest_source_mtime($scss_dir) {
    $latest = 0;
    if (!is_dir($scss_dir)) { return $latest; }
    try {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scss_dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'scss') {
                $latest = max($latest, (int) $file->getMTime());
            }
        }
    } catch (Throwable $error) {
        ship_scss_compiler_log('Unable to inspect legacy SCSS timestamps: ' . $error->getMessage());
    }
    return $latest;
}

function ship_scss_compiler_output_path($source, $css_dir) {
    return trailingslashit($css_dir) . pathinfo($source, PATHINFO_FILENAME) . '.css';
}

function ship_scss_compiler_needs_compile($entrypoints, $css_dir, $latest_source_mtime) {
    if ((int) $latest_source_mtime < 1) { return false; }
    foreach ((array) $entrypoints as $source) {
        $output = ship_scss_compiler_output_path($source, $css_dir);
        if (!is_file($output) || (int) @filemtime($output) < (int) $latest_source_mtime) { return true; }
    }
    return false;
}

function ship_scss_compiler_css_version($theme_relative_css) {
    global $ship_scss_compiler;
    return $ship_scss_compiler->css_version($theme_relative_css);
}

function ship_scss_compiler_run($force = false, $selected = array(), $action = 'auto') {
    global $ship_scss_compiler;
    return $ship_scss_compiler->run($force, $selected, $action);
}

function ship_scss_compiler_compile_one($source, $output, $scss_dir) {
    global $ship_scss_compiler;
    unset($output);
    $paths = $ship_scss_compiler->paths();
    $source = str_replace('\\', '/', (string) $source);
    $scss_dir = trailingslashit(str_replace('\\', '/', (string) $scss_dir));
    $input_dir = trailingslashit(str_replace('\\', '/', $paths['scss']));
    if (strpos($source, $scss_dir) !== 0 || strpos($scss_dir, $input_dir) !== 0) {
        return false;
    }
    $relative = ltrim(substr($source, strlen($input_dir)), '/');
    $settings = get_option(Ship_SCSS_Compiler::SETTINGS_OPTION, Ship_SCSS_Compiler::defaults());
    $relative = isset($settings['input_dir']) ? trim($settings['input_dir'], '/') . '/' . $relative : 'scss/' . $relative;
    $report = ship_scss_compiler_run(true, array($relative), 'manual-selected');
    return isset($report['results'][$relative]) && $report['results'][$relative]['status'] === 'success';
}

// GitHub Release Assetだけを配布元にする自動アップデータ。
if (file_exists(SHIP_SCSS_COMPILER_DIR . 'lib/plugin-update-checker/plugin-update-checker.php')) {
    require_once SHIP_SCSS_COMPILER_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

    $ship_scss_compiler_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        SHIP_SCSS_COMPILER_REPOSITORY,
        SHIP_SCSS_COMPILER_FILE,
        'ship-scss-compiler'
    );
    $ship_scss_compiler_update_checker->setBranch('main');
    $ship_scss_compiler_update_api = $ship_scss_compiler_update_checker->getVcsApi();
    $ship_scss_compiler_update_checker->addFilter('vcs_update_detection_strategies', static function ($strategies) {
        return isset($strategies['latest_release']) ? array('latest_release' => $strategies['latest_release']) : array();
    });
    if (method_exists($ship_scss_compiler_update_api, 'enableReleaseAssets')) {
        $ship_scss_compiler_update_api->enableReleaseAssets(
            '/^ship-scss-compiler-[0-9]+\.[0-9]+\.[0-9]+\.zip$/',
            $ship_scss_compiler_update_api::REQUIRE_RELEASE_ASSETS
        );
    }
}
