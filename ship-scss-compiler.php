<?php
/**
 * Plugin Name: Ship SCSS Compiler
 * Description: Compiles the active theme's SCSS files safely with scssphp.
 * Version: 1.2.0
 * Requires PHP: 7.2
 */

defined('ABSPATH') || exit;

define('SHIP_SCSS_COMPILER_VERSION', '1.2.0');
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
 * Return whether the optional debug output is enabled.
 *
 * Debug output is intentionally opt-in. The filter is useful for local or
 * environment-specific overrides without changing the stored setting.
 *
 * @return bool
 */
function ship_scss_compiler_debug_enabled() {
    return (bool) apply_filters(
        'ship_scss_compiler_debug',
        (bool) get_option(SHIP_SCSS_COMPILER_DEBUG_OPTION, false)
    );
}

/**
 * Return the output profile used for compile invalidation.
 *
 * @return string
 */
function ship_scss_compiler_profile() {
    return ship_scss_compiler_debug_enabled() ? 'debug' : 'production';
}

/**
 * Sanitize the settings checkbox value.
 *
 * @param mixed $value
 * @return int
 */
function ship_scss_compiler_sanitize_debug($value) {
    return empty($value) ? 0 : 1;
}

function ship_scss_compiler_register_settings() {
    register_setting(
        'ship_scss_compiler_settings',
        SHIP_SCSS_COMPILER_DEBUG_OPTION,
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'ship_scss_compiler_sanitize_debug',
            'default'           => false,
        )
    );
}
add_action('admin_init', 'ship_scss_compiler_register_settings');

function ship_scss_compiler_add_settings_page() {
    add_options_page(
        'Ship SCSS Compiler',
        'Ship SCSS Compiler',
        'manage_options',
        'ship-scss-compiler',
        'ship_scss_compiler_render_settings_page'
    );
}
add_action('admin_menu', 'ship_scss_compiler_add_settings_page');

function ship_scss_compiler_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $debug = ship_scss_compiler_debug_enabled();
    $profile = get_option(SHIP_SCSS_COMPILER_PROFILE_OPTION, '未コンパイル');
    ?>
    <div class="wrap">
        <h1>Ship SCSS Compiler</h1>
        <p>テーマ内のSCSSを、既存の構成を維持したままCSSへコンパイルします。</p>
        <form method="post" action="options.php">
            <?php settings_fields('ship_scss_compiler_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">CSSデバッグ</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(SHIP_SCSS_COMPILER_DEBUG_OPTION); ?>" value="1" <?php checked($debug); ?> />
                            展開形式CSSとソースマップ（.css.map）を生成する
                        </label>
                        <p class="description">有効にすると、ブラウザの開発者ツールからSCSSの元ファイル・行を追跡しやすくなります。通常時は圧縮CSSのままで、速度とファイル容量への影響はありません。</p>
                        <p class="description"><strong>切り替え後の反映:</strong> 設定保存後、次回のWordPressリクエストでCSSを安全に再生成します。無効化しても既存の.mapファイルは削除せず、CSSから参照されなくなります。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('設定を保存'); ?>
        </form>
        <h2>現在の状態</h2>
        <p>出力モード: <strong><?php echo esc_html($debug ? 'デバッグ（展開CSS＋ソースマップ）' : '通常（圧縮CSS）'); ?></strong></p>
        <p>最終コンパイルモード: <strong><?php echo esc_html($profile); ?></strong></p>
    </div>
    <?php
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

    $profile = ship_scss_compiler_profile();

    if (get_option(SHIP_SCSS_COMPILER_PROFILE_OPTION, '') !== $profile) {
        return true;
    }

    foreach ($entrypoints as $source) {
        $output = ship_scss_compiler_output_path($source, $css_dir);

        if (!is_file($output) || (int) @filemtime($output) < $latest_source_mtime) {
            return true;
        }

        if ($profile === 'debug' && !is_file($output . '.map')) {
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
    $temp       = null;
    $map_temp   = null;
    $map_output = $output . '.map';
    $debug      = ship_scss_compiler_debug_enabled();
    $state      = array(
        'done'      => false,
        'temp'      => null,
        'map_temp'  => null,
        'source'    => $source,
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

        if (!empty($state['map_temp']) && is_file($state['map_temp'])) {
            @unlink($state['map_temp']);
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

        if ($debug) {
            $compiler->setOutputStyle('expanded');
            $compiler->setSourceMap(Compiler::SOURCE_MAP_FILE);
            $compiler->setSourceMapOptions(
                array(
                    'sourceMapFilename' => basename($output),
                    'sourceMapURL'      => basename($map_output),
                    'outputSourceFiles' => true,
                    'sourceMapRootpath' => '../scss/',
                    'sourceMapBasepath' => trailingslashit($scss_dir),
                )
            );
        } else {
            $compiler->setOutputStyle('compressed');
            $compiler->setSourceMap(Compiler::SOURCE_MAP_NONE);
        }

        $result = $compiler->compileString($input, $source);
        $css    = $result->getCss();

        if (!is_string($css) || trim($css) === '') {
            throw new RuntimeException('Compiler returned empty CSS; existing CSS was preserved.');
        }

        $source_map = $debug ? $result->getSourceMap() : null;
        if ($debug && (!is_string($source_map) || trim($source_map) === '')) {
            throw new RuntimeException('Compiler returned an empty source map; existing CSS was preserved.');
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

        if ($debug) {
            $map_temp = tempnam(dirname($map_output), '.ship-scss-map-');
            if ($map_temp === false) {
                throw new RuntimeException('Unable to create a temporary source map file.');
            }
            $state['map_temp'] = $map_temp;

            $map_bytes = file_put_contents($map_temp, $source_map, LOCK_EX);
            if ($map_bytes === false || $map_bytes !== strlen($source_map) || (int) @filesize($map_temp) < 1) {
                throw new RuntimeException('Temporary source map validation failed.');
            }

            $map_mode = is_file($map_output) ? (@fileperms($map_output) & 0777) : 0644;
            @chmod($map_temp, $map_mode ?: 0644);

            // Publish the map first. If CSS replacement fails, the old CSS is
            // still valid and the new map remains harmlessly unreferenced.
            if (!@rename($map_temp, $map_output)) {
                throw new RuntimeException('Atomic source map replacement failed.');
            }
            $state['map_temp'] = null;
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

        if ($success) {
            update_option(SHIP_SCSS_COMPILER_PROFILE_OPTION, ship_scss_compiler_profile(), false);
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
