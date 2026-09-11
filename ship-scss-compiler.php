<?php
/**
 * Plugin Name: Ship SCSS Compiler
 * Description: Compiles the active theme's SCSS files safely with scssphp.
 * Version: 1.1.0
 * Requires PHP: 7.2
 */

defined('ABSPATH') || exit;

define('SHIP_SCSS_COMPILER_VERSION', '1.1.0');
define('SHIP_SCSS_COMPILER_FILE', __FILE__);
define('SHIP_SCSS_COMPILER_DIR', plugin_dir_path(__FILE__));
define('SHIP_SCSS_COMPILER_REPOSITORY', 'https://github.com/ship-git-admin/ship-scss-compiler');

if (version_compare(PHP_VERSION, '7.2', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Ship SCSS Compiler requires PHP 7.2 or newer. Existing CSS files were left unchanged.</p></div>';
    });
    return;
}

require_once SHIP_SCSS_COMPILER_DIR . 'scssphp/scss.inc.php';

use ScssPhp\ScssPhp\Compiler;

/**
 * Return the fixed, theme-relative directories used by the existing workflow.
 *
 * @return array{scss:string,css:string}
 */
function ship_scss_compiler_paths() {
    $theme_dir = trailingslashit(get_stylesheet_directory());

    return array(
        'scss' => $theme_dir . 'scss/',
        'css'  => $theme_dir . 'css/',
    );
}

/**
 * Append an error to the existing SCSS error log without interrupting a request.
 *
 * @param string $message
 * @return void
 */
function ship_scss_compiler_log($message) {
    $paths = ship_scss_compiler_paths();
    $line  = '[' . current_time('mysql') . '] ' . $message . PHP_EOL;
    $log   = $paths['scss'] . 'error_log.log';

    if (is_dir($paths['scss']) && is_writable($paths['scss'])) {
        @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
    } else {
        error_log('Ship SCSS Compiler: ' . $message);
    }
}

/**
 * Get top-level SCSS entrypoints. Partials beginning with an underscore are
 * imported by entrypoints and are never written as standalone CSS files.
 *
 * @param string $scss_dir
 * @return array<string,string>
 */
function ship_scss_compiler_entrypoints($scss_dir) {
    $entrypoints = array();

    if (!is_dir($scss_dir) || !is_readable($scss_dir)) {
        return $entrypoints;
    }

    try {
        foreach (new DirectoryIterator($scss_dir) as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            if (substr($name, 0, 1) === '_' || strtolower($file->getExtension()) !== 'scss') {
                continue;
            }

            // Empty top-level files are intentional placeholders in this theme.
            // They have no CSS to compile, so leave any existing output untouched.
            if ((int) @filesize($file->getPathname()) === 0) {
                continue;
            }

            $entrypoints[$name] = $file->getPathname();
        }
    } catch (Throwable $error) {
        ship_scss_compiler_log('Unable to scan SCSS directory: ' . $error->getMessage());
    }

    ksort($entrypoints);
    return $entrypoints;
}

/**
 * Return the newest SCSS modification time, including imported partials.
 *
 * @param string $scss_dir
 * @return int
 */
function ship_scss_compiler_latest_source_mtime($scss_dir) {
    $latest = 0;

    if (!is_dir($scss_dir) || !is_readable($scss_dir)) {
        return $latest;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scss_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'scss') {
                continue;
            }

            $latest = max($latest, (int) $file->getMTime());
        }
    } catch (Throwable $error) {
        ship_scss_compiler_log('Unable to inspect SCSS timestamps: ' . $error->getMessage());
    }

    return $latest;
}

/**
 * @param string $source
 * @param string $css_dir
 * @return string
 */
function ship_scss_compiler_output_path($source, $css_dir) {
    return trailingslashit($css_dir) . pathinfo($source, PATHINFO_FILENAME) . '.css';
}

/**
 * @param array<string,string> $entrypoints
 * @param string $css_dir
 * @param int $latest_source_mtime
 * @return bool
 */
function ship_scss_compiler_needs_compile($entrypoints, $css_dir, $latest_source_mtime) {
    if ($latest_source_mtime < 1) {
        return false;
    }

    foreach ($entrypoints as $source) {
        $output = ship_scss_compiler_output_path($source, $css_dir);

        if (!is_file($output) || (int) @filemtime($output) < $latest_source_mtime) {
            return true;
        }
    }

    return false;
}

/**
 * Compile one entrypoint and replace its CSS only after a complete, non-empty
 * result has been written to a same-directory temporary file.
 *
 * @param string $source
 * @param string $output
 * @param string $scss_dir
 * @return bool
 */
function ship_scss_compiler_compile_one($source, $output, $scss_dir) {
    $temp  = null;
    $state = array(
        'done'   => false,
        'temp'   => null,
        'source' => $source,
    );

    register_shutdown_function(function () use (&$state) {
        if ($state['done']) {
            return;
        }

        $error = error_get_last();
        if (!$error || !in_array($error['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE), true)) {
            return;
        }

        if (!empty($state['temp']) && is_file($state['temp'])) {
            @unlink($state['temp']);
        }

        ship_scss_compiler_log(
            'Fatal error while compiling ' . basename($state['source']) . ': ' . $error['message']
        );
    });

    try {
        if (!is_readable($source)) {
            throw new RuntimeException('Source file is not readable.');
        }

        if (!is_dir(dirname($output)) || !is_writable(dirname($output))) {
            throw new RuntimeException('CSS directory is not writable.');
        }

        $input = file_get_contents($source);
        if ($input === false) {
            throw new RuntimeException('Unable to read source file.');
        }

        $compiler = new Compiler();
        $compiler->setImportPaths($scss_dir);
        $compiler->setOutputStyle('compressed');
        $compiler->setSourceMap(Compiler::SOURCE_MAP_NONE);

        $result = $compiler->compileString($input, $source);
        $css    = $result->getCss();

        if (!is_string($css) || trim($css) === '') {
            throw new RuntimeException('Compiler returned empty CSS; existing CSS was preserved.');
        }

        $temp = tempnam(dirname($output), '.ship-scss-');
        if ($temp === false) {
            throw new RuntimeException('Unable to create a temporary CSS file.');
        }
        $state['temp'] = $temp;

        $bytes = file_put_contents($temp, $css, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($css) || (int) @filesize($temp) < 1) {
            throw new RuntimeException('Temporary CSS validation failed.');
        }

        $mode = is_file($output) ? (@fileperms($output) & 0777) : 0644;
        @chmod($temp, $mode ?: 0644);

        if (!@rename($temp, $output)) {
            throw new RuntimeException('Atomic CSS replacement failed.');
        }

        $state['temp'] = null;
        $state['done'] = true;
        return true;
    } catch (Throwable $error) {
        if ($temp && is_file($temp)) {
            @unlink($temp);
        }

        $state['done'] = true;
        ship_scss_compiler_log(basename($source) . ': ' . $error->getMessage());
        return false;
    }
}

/**
 * Compile all entrypoints when any source or imported partial is newer than
 * its output. Each output is protected independently, so one failure cannot
 * erase or replace the other CSS files.
 *
 * @param bool $force
 * @return bool
 */
function ship_scss_compiler_run($force = false) {
    if (function_exists('wp_scss_compile')) {
        // Prevent two compilers from racing during the transition period.
        return false;
    }

    $paths       = ship_scss_compiler_paths();
    $scss_dir    = $paths['scss'];
    $css_dir     = $paths['css'];
    $entrypoints = ship_scss_compiler_entrypoints($scss_dir);

    if (empty($entrypoints)) {
        return true;
    }

    $latest_source_mtime = ship_scss_compiler_latest_source_mtime($scss_dir);
    if (!$force && !ship_scss_compiler_needs_compile($entrypoints, $css_dir, $latest_source_mtime)) {
        return true;
    }

    $lock_path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/ship-scss-' . md5($scss_dir) . '.lock';
    $lock      = @fopen($lock_path, 'c');

    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            @fclose($lock);
        }
        return true;
    }

    $success = true;

    try {
        // Re-check after acquiring the lock so concurrent requests do not do
        // the same work twice.
        $latest_source_mtime = ship_scss_compiler_latest_source_mtime($scss_dir);
        if (!$force && !ship_scss_compiler_needs_compile($entrypoints, $css_dir, $latest_source_mtime)) {
            return true;
        }

        foreach ($entrypoints as $source) {
            $output = ship_scss_compiler_output_path($source, $css_dir);
            if (!ship_scss_compiler_compile_one($source, $output, $scss_dir)) {
                $success = false;
            }
        }
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    return $success;
}

function ship_scss_compiler_maybe_run() {
    ship_scss_compiler_run(false);
}
add_action('wp_loaded', 'ship_scss_compiler_maybe_run', 1);

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

    // mainブランチやGitHub自動生成ZIPへフォールバックせず、Releaseだけを検出する。
    $ship_scss_compiler_update_checker->addFilter('vcs_update_detection_strategies', static function ($strategies) {
        return isset($strategies['latest_release'])
            ? array('latest_release' => $strategies['latest_release'])
            : array();
    });

    if (method_exists($ship_scss_compiler_update_api, 'enableReleaseAssets')) {
        // 指定AssetがないReleaseは更新候補にしない。
        $ship_scss_compiler_update_api->enableReleaseAssets(
            '/^ship-scss-compiler-[0-9]+\.[0-9]+\.[0-9]+\.zip$/',
            $ship_scss_compiler_update_api::REQUIRE_RELEASE_ASSETS
        );
    }
}
