<?php

defined('ABSPATH') || exit;

/**
 * The application layer for Ship SCSS Compiler.
 *
 * The class deliberately keeps WordPress calls at the edges.  The compiler
 * state is versioned and keyed by the active stylesheet theme plus the
 * complete input/output configuration, so a theme switch or a path change
 * cannot reuse the state of another layout.
 */
class Ship_SCSS_Compiler {
    const SETTINGS_OPTION = 'ship_scss_compiler_settings';
    const STATE_OPTION    = 'ship_scss_compiler_state_v2';
    const LOG_OPTION      = 'ship_scss_compiler_logs';
    const NOTICE_PREFIX   = 'ship_scss_compiler_notice_';
    const RESULT_PREFIX   = 'ship_scss_compiler_result_';
    const LOCK_PREFIX     = 'ship-scss-compiler-';
    const SCHEMA_VERSION  = 2;

    /** @var bool */
    private static $ran_this_request = false;

    /** @var array|null */
    private $settings_cache = null;

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_post_ship_scss_compiler_recompile_all', array($this, 'handle_recompile_all'));
        add_action('admin_post_ship_scss_compiler_recompile_selected', array($this, 'handle_recompile_selected'));
        add_action('admin_post_ship_scss_compiler_clear_logs', array($this, 'handle_clear_logs'));
        add_action('admin_post_ship_scss_compiler_delete_legacy_log', array($this, 'handle_delete_legacy_log'));
        add_filter('style_loader_src', array($this, 'filter_style_loader_src'), 20, 2);
        add_action('wp_loaded', array($this, 'maybe_run'), 1);
    }

    public static function defaults() {
        return array(
            'debug'                 => false,
            'embed_sources'         => false,
            'delete_maps_on_disable'=> true,
            'cache_busting'         => false,
            'input_dir'             => 'scss',
            'output_dir'            => 'css',
            'entry_mode'            => 'auto',
            'entrypoints'           => '',
            'include_subdirectories'=> false,
            'retry_interval'        => 300,
        );
    }

    public function register_settings() {
        register_setting(
            'ship_scss_compiler_settings',
            self::SETTINGS_OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default'           => self::defaults(),
            )
        );
    }

    public function sanitize_settings($value) {
        $old = $this->settings();
        $value = is_array($value) ? $value : array();
        $next = self::defaults();

        foreach (array('debug', 'embed_sources', 'delete_maps_on_disable', 'cache_busting', 'include_subdirectories') as $key) {
            $next[$key] = !empty($value[$key]);
        }

        foreach (array('input_dir', 'output_dir') as $key) {
            $normalized = $this->normalize_relative_path(isset($value[$key]) ? $value[$key] : '');
            if ($normalized === false || $normalized === '') {
                $this->admin_notice('設定を保存できませんでした。' . $key . ' はテーマ内の相対ディレクトリで指定してください。', 'error');
                return $old;
            }
            if ($key === 'input_dir') {
                $input_path = $this->theme_root() . '/' . $normalized;
                $input_real = realpath($input_path);
                if (!is_dir($input_path) || $input_real === false || !$this->within($input_real, $this->theme_root()) || !is_readable($input_real)) {
                    $this->admin_notice('設定を保存できませんでした。SCSS入力ディレクトリが存在しないか、テーマ外を参照しています。', 'error');
                    return $old;
                }
            }
            $next[$key] = $normalized;
        }

        $next['entry_mode'] = (isset($value['entry_mode']) && $value['entry_mode'] === 'explicit') ? 'explicit' : 'auto';
        $lines = isset($value['entrypoints']) ? preg_split('/\r\n|\r|\n/', (string) $value['entrypoints']) : array();
        $clean_lines = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $path = $this->normalize_relative_path($line);
            if ($path === false || strtolower(substr($path, -5)) !== '.scss' || substr(basename($path), 0, 1) === '_') {
                $this->admin_notice('設定を保存できませんでした。エントリーポイントは _ で始まらない相対SCSSパスを1行ずつ指定してください。', 'error');
                return $old;
            }
            $clean_lines[$path] = $path;
        }
        $next['entrypoints'] = implode("\n", array_values($clean_lines));

        $interval = isset($value['retry_interval']) ? absint($value['retry_interval']) : 300;
        $next['retry_interval'] = max(30, min(86400, $interval ?: 300));

        $this->settings_cache = $next;
        return $next;
    }

    public function settings() {
        if ($this->settings_cache !== null) {
            return $this->settings_cache;
        }

        $saved = get_option(self::SETTINGS_OPTION, null);
        $settings = self::defaults();
        if (is_array($saved)) {
            $settings = array_merge($settings, $saved);
        } elseif ($saved === null && defined('SHIP_SCSS_COMPILER_DEBUG_OPTION')) {
            // Migration from 1.2.0: preserve the old debug checkbox.
            $settings['debug'] = (bool) get_option(SHIP_SCSS_COMPILER_DEBUG_OPTION, false);
        }

        foreach (array('debug', 'embed_sources', 'delete_maps_on_disable', 'cache_busting', 'include_subdirectories') as $key) {
            $settings[$key] = !empty($settings[$key]);
        }
        $settings['entry_mode'] = $settings['entry_mode'] === 'explicit' ? 'explicit' : 'auto';
        $settings['retry_interval'] = max(30, min(86400, absint($settings['retry_interval']) ?: 300));
        $this->settings_cache = $settings;
        return $settings;
    }

    public function paths() {
        $root = $this->theme_root();
        $settings = $this->settings();
        return array(
            'root'        => $root,
            'scss'        => $root . '/' . $settings['input_dir'],
            'css'         => $root . '/' . $settings['output_dir'],
            'input_rel'   => $settings['input_dir'],
            'output_rel'  => $settings['output_dir'],
        );
    }

    public function debug_enabled() {
        return (bool) apply_filters('ship_scss_compiler_debug', !empty($this->settings()['debug']));
    }

    public function profile() {
        return $this->debug_enabled() ? 'debug' : 'production';
    }

    public function retry_interval() {
        return max(30, (int) apply_filters('ship_scss_compiler_retry_interval', $this->settings()['retry_interval']));
    }

    public function css_version($theme_relative_css) {
        $relative = $this->normalize_relative_path($theme_relative_css);
        if ($relative === false || strtolower(substr($relative, -4)) !== '.css') {
            return '';
        }
        $state = $this->load_state();
        $context = $this->context_key();
        if (!isset($state['contexts'][$context]['managed'][$relative]['hash'])) {
            return '';
        }
        return (string) $state['contexts'][$context]['managed'][$relative]['hash'];
    }

    public function filter_style_loader_src($src, $handle = '') {
        unset($handle);
        if (!$this->settings()['cache_busting'] || !is_string($src) || $src === '') {
            return $src;
        }

        $parts = preg_split('/([?#])/', $src, 2, PREG_SPLIT_DELIM_CAPTURE);
        $base = isset($parts[0]) ? $parts[0] : $src;
        $rest = substr($src, strlen($base));
        $path = wp_parse_url($base, PHP_URL_PATH);
        if (!is_string($path)) {
            return $src;
        }

        $source_host = wp_parse_url($base, PHP_URL_HOST);
        $theme_host = wp_parse_url(get_stylesheet_directory_uri(), PHP_URL_HOST);
        if ($source_host && (!$theme_host || strtolower($source_host) !== strtolower($theme_host))) {
            return $src;
        }

        $theme_uri = wp_parse_url(get_stylesheet_directory_uri(), PHP_URL_PATH);
        if (!is_string($theme_uri)) {
            return $src;
        }
        $prefix = rtrim($theme_uri, '/') . '/';
        if (strpos($path, $prefix) !== 0) {
            return $src;
        }

        $relative = ltrim(substr($path, strlen($prefix)), '/');
        $version = $this->css_version($relative);
        if ($version === '') {
            return $src;
        }

        $query = '';
        $fragment = '';
        if ($rest !== '') {
            if (strpos($rest, '#') !== false) {
                list($rest, $fragment) = explode('#', $rest, 2);
                $fragment = '#' . $fragment;
            }
            if (strpos($rest, '?') === 0) {
                $query = substr($rest, 1);
            }
        }
        $pairs = $query === '' ? array() : explode('&', $query);
        $found = false;
        foreach ($pairs as $index => $pair) {
            if (strpos($pair, '=') === false) {
                continue;
            }
            list($key) = explode('=', $pair, 2);
            if (rawurldecode($key) === 'ver') {
                $pairs[$index] = rawurlencode($key) . '=' . rawurlencode($version);
                $found = true;
            }
        }
        if (!$found) {
            $pairs[] = 'ver=' . rawurlencode($version);
        }
        return $base . '?' . implode('&', array_filter($pairs, 'strlen')) . $fragment;
    }

    public function maybe_run() {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }
        $this->run(false, array(), 'auto');
    }

    /**
     * Compile all or selected entrypoints.
     *
     * @param bool $force
     * @param array $selected theme-relative source paths
     * @param string $action
     * @return array
     */
    public function run($force = false, $selected = array(), $action = 'auto') {
        if (self::$ran_this_request) {
            return array('locked' => false, 'duplicate' => true, 'counts' => array('success' => 0, 'failure' => 0, 'skipped' => 0), 'results' => array());
        }
        self::$ran_this_request = true;

        if (function_exists('wp_scss_compile')) {
            return $this->report_error('旧WP-SCSSが有効なため処理を停止しました。どちらか一方だけを有効にしてください。');
        }

        $ctx = $this->build_context();
        if (!empty($ctx['error'])) {
            return $this->report_error($ctx['error']);
        }

        $lock = $this->acquire_lock($ctx['key']);
        if (!$lock) {
            $this->log_event('別の処理が実行中のため、今回の処理を開始できませんでした。', $ctx, '', 'lock');
            return array('locked' => true, 'counts' => array('success' => 0, 'failure' => 0, 'skipped' => 0), 'results' => array());
        }

        $state = $this->load_state();
        if (!isset($state['contexts'][$ctx['key']])) {
            $state['contexts'][$ctx['key']] = $this->new_context_state($ctx);
        }
        $context_state = $state['contexts'][$ctx['key']];
        $inventory = $this->scan_inventory($ctx, (bool) $force);
        $plans = $this->entry_plans($ctx, $inventory);
        $selected_map = array();
        foreach ((array) $selected as $path) {
            $path = $this->normalize_relative_path($path);
            if ($path !== false) {
                $selected_map[$path] = true;
            }
        }

        $report = array('locked' => false, 'action' => $action, 'counts' => array('success' => 0, 'failure' => 0, 'skipped' => 0), 'results' => array());
        foreach ($plans as $plan) {
            $source_rel = $plan['source_rel'];
            if (!empty($plan['collision'])) {
                $result = $this->failure_result($context_state, $plan, '出力先が重複しています。設定を確認してください。', array(), $ctx, $inventory);
            } elseif ($action === 'manual-selected' && !isset($selected_map[$source_rel])) {
                $result = array('status' => 'skipped', 'source' => $source_rel, 'message' => '未選択');
            } else {
                $result = $this->process_plan($context_state, $plan, $ctx, $inventory, (bool) $force, $action);
            }
            $report['results'][$source_rel] = $result;
            $bucket = isset($result['status']) ? $result['status'] : 'skipped';
            if ($bucket === 'success') {
                $report['counts']['success']++;
            } elseif ($bucket === 'failure') {
                $report['counts']['failure']++;
            } else {
                $report['counts']['skipped']++;
            }
        }

        if ($force || $context_state['inventory'] !== $inventory || empty($context_state['last_scan'])) {
            $context_state['last_scan'] = $this->now();
        }
        $context_state['inventory'] = $inventory;
        if (!$this->debug_enabled() && $this->settings()['delete_maps_on_disable']) {
            $this->cleanup_owned_maps($state, $ctx, $context_state);
        }
        $state['contexts'][$ctx['key']] = $context_state;
        $this->save_state($state);
        if ($report['counts']['success'] > 0) {
            update_option('ship_scss_compiler_last_profile', $this->profile(), false);
        }
        $this->release_lock($lock);
        return $report;
    }

    private function process_plan(&$context_state, $plan, $ctx, $inventory, $force, $action) {
        $source_rel = $plan['source_rel'];
        $old = isset($context_state['entries'][$source_rel]) && is_array($context_state['entries'][$source_rel]) ? $context_state['entries'][$source_rel] : array();
        $current_fp = $this->input_fingerprint($plan, $old, $inventory, $ctx);

        $suppressed = !$force && $this->retry_suppressed($old, $current_fp, $ctx);
        if ($suppressed) {
            $context_state['entries'][$source_rel] = array_merge($old, array('status' => 'retry_wait', 'last_result' => 'retry_suppressed'));
            return array('status' => 'skipped', 'source' => $source_rel, 'message' => '同じ入力状態の失敗を再試行抑制中です。', 'retry_at' => $old['next_retry']);
        }

        if ($plan['missing']) {
            return $this->failure_result($context_state, $plan, '入力SCSSが見つからないか、読み取れません。', array(), $ctx, $inventory, $current_fp);
        }
        if ($plan['empty']) {
            $context_state['entries'][$source_rel] = array_merge($old, array(
                'status' => 'skipped', 'last_attempt' => $this->now(), 'last_result' => 'empty',
                'source_rel' => $source_rel, 'output_rel' => $plan['output_rel'],
                'settings_sig' => $ctx['settings_sig'], 'mode' => $this->profile(),
            ));
            return array('status' => 'skipped', 'source' => $source_rel, 'message' => '空ファイルのため既存CSSを維持しました。');
        }

        $needs = $this->needs_compile($plan, $old, $current_fp, $ctx);
        if (!$force && !$needs) {
            $context_state['entries'][$source_rel] = array_merge($old, array('status' => 'skipped', 'last_result' => 'unchanged'));
            return array('status' => 'skipped', 'source' => $source_rel, 'message' => '変更なし。');
        }

        $started = microtime(true);
        $compiled = $this->compile_plan($plan, $ctx);
        $duration = round((microtime(true) - $started) * 1000, 2);
        if (!$compiled['ok']) {
            return $this->failure_result($context_state, $plan, $compiled['error'], $compiled, $ctx, $inventory, $current_fp, $duration);
        }

        $dependencies = array($source_rel => $this->hash_file($plan['source_abs']));
        $unknown = false;
        foreach ($compiled['included'] as $file) {
            $rel = $this->relative_to_input($file, $ctx);
            if ($rel === false) {
                $unknown = true;
                continue;
            }
            $dependencies[$rel] = $this->hash_file($file);
        }
        $published_fp = $this->fingerprint_for_dependencies($source_rel, $dependencies, $inventory, $ctx, $unknown);
        $output_abs = $plan['output_abs'];
        $output_hash = hash_file('sha256', $output_abs);
        if (!is_string($output_hash)) {
            $output_hash = hash('sha256', $compiled['css']);
        }
        $now = $this->now();
        $context_state['entries'][$source_rel] = array(
            'status' => 'success', 'source_rel' => $source_rel, 'output_rel' => $plan['output_rel'],
            'last_attempt' => $now, 'last_success' => $now, 'duration_ms' => $duration,
            'output_hash' => $output_hash, 'output_mtime' => (int) @filemtime($output_abs),
            'output_size' => (int) @filesize($output_abs), 'version' => $output_hash,
            'mode' => $this->profile(), 'last_success_mode' => $this->profile(),
            'settings_sig' => $ctx['settings_sig'], 'input_fp' => $published_fp,
            'dependencies' => $dependencies, 'deps_unknown' => $unknown,
            'failure_fp' => '', 'failure_settings_sig' => '', 'failure_count' => 0,
            'next_retry' => 0, 'last_error' => '', 'error_location' => array(),
            'last_result' => 'success',
        );
        $context_state['managed'][$plan['output_rel']] = array(
            'hash' => $output_hash, 'source_rel' => $source_rel, 'updated' => $now,
            'map_rel' => $this->debug_enabled() ? $plan['output_rel'] . '.map' : '',
        );
        if ($this->debug_enabled()) {
            $context_state['managed_maps'][$plan['output_rel'] . '.map'] = array('source_rel' => $source_rel, 'updated' => $now);
        }
        return array('status' => 'success', 'source' => $source_rel, 'output' => $plan['output_rel'], 'duration_ms' => $duration, 'version' => $output_hash);
    }

    private function compile_plan($plan, $ctx) {
        $temp = $map_temp = $backup_css = $backup_map = null;
        $published_css = false;
        $published_map = false;
        $shutdown_done = false;
        $debug = $this->debug_enabled();
        register_shutdown_function(function () use (&$shutdown_done, &$temp, &$map_temp, &$backup_css, &$backup_map, &$published_css, &$published_map, $plan) {
            if ($shutdown_done) { return; }
            if ($temp && is_file($temp)) { @unlink($temp); }
            if ($map_temp && is_file($map_temp)) { @unlink($map_temp); }
            if ($published_css && is_file($plan['output_abs'])) { @unlink($plan['output_abs']); }
            if ($published_map && is_file($plan['output_abs'] . '.map')) { @unlink($plan['output_abs'] . '.map'); }
            if ($backup_css && is_file($backup_css) && !is_file($plan['output_abs'])) { @rename($backup_css, $plan['output_abs']); }
            if ($backup_map && is_file($backup_map) && !is_file($plan['output_abs'] . '.map')) { @rename($backup_map, $plan['output_abs'] . '.map'); }
            $error = error_get_last();
            if ($error && in_array($error['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE), true)) {
                error_log('Ship SCSS Compiler: fatal error while compiling ' . basename($plan['source_rel']));
            }
        });
        try {
            $input = file_get_contents($plan['source_abs']);
            if ($input === false) {
                throw new RuntimeException('入力SCSSを読み取れません。');
            }
            $compiler = new \ScssPhp\ScssPhp\Compiler();
            $compiler->setImportPaths(array($ctx['input_root']));
            if ($debug) {
                $compiler->setOutputStyle('expanded');
                $compiler->setSourceMap(\ScssPhp\ScssPhp\Compiler::SOURCE_MAP_FILE);
                $compiler->setSourceMapOptions(array(
                    'sourceMapFilename' => basename($plan['output_rel'] . '.map'),
                    'sourceMapURL' => basename($plan['output_rel'] . '.map'),
                    'outputSourceFiles' => (bool) $this->settings()['embed_sources'],
                    'sourceMapRootpath' => $this->relative_directory($plan['output_abs'], $ctx['input_root']),
                    'sourceMapBasepath' => trailingslashit($ctx['input_root']),
                ));
            } else {
                $compiler->setOutputStyle('compressed');
                $compiler->setSourceMap(\ScssPhp\ScssPhp\Compiler::SOURCE_MAP_NONE);
            }
            $result = $compiler->compileString($input, $plan['source_abs']);
            $css = $result->getCss();
            if (!is_string($css) || trim($css) === '') {
                throw new RuntimeException('コンパイラが空のCSSを返しました。既存CSSを維持しました。');
            }
            $map = $debug ? $result->getSourceMap() : null;
            if ($debug && (!is_string($map) || trim($map) === '')) {
                throw new RuntimeException('ソースマップを生成できません。既存CSSを維持しました。');
            }

            $output_dir = dirname($plan['output_abs']);
            $this->ensure_directory($output_dir, $ctx['root']);
            if (is_link($plan['output_abs'])) {
                $linked = realpath($plan['output_abs']);
                if ($linked === false || !$this->within($linked, $ctx['root'])) {
                    throw new RuntimeException('既存CSSがテーマ外へのシンボリックリンクです。');
                }
            }
            $temp = tempnam($output_dir, '.ship-scss-');
            if ($temp === false || file_put_contents($temp, $css, LOCK_EX) !== strlen($css) || (int) @filesize($temp) < 1) {
                throw new RuntimeException('CSS一時ファイルの検証に失敗しました。');
            }
            if ($debug) {
                $map_temp = tempnam($output_dir, '.ship-scss-map-');
                if ($map_temp === false || file_put_contents($map_temp, $map, LOCK_EX) !== strlen($map) || (int) @filesize($map_temp) < 1) {
                    throw new RuntimeException('ソースマップ一時ファイルの検証に失敗しました。');
                }
            }
            $mode = is_file($plan['output_abs']) ? (@fileperms($plan['output_abs']) & 0777) : 0644;
            @chmod($temp, $mode ?: 0644);
            if ($debug) {
                @chmod($map_temp, is_file($plan['output_abs'] . '.map') ? (@fileperms($plan['output_abs'] . '.map') & 0777) : 0644);
            }
            if (is_file($plan['output_abs'])) {
                $backup_css = tempnam($output_dir, '.ship-scss-old-');
                @unlink($backup_css);
                if (!@rename($plan['output_abs'], $backup_css)) {
                    throw new RuntimeException('既存CSSを保護できません。');
                }
            }
            if ($debug && is_file($plan['output_abs'] . '.map')) {
                $backup_map = tempnam($output_dir, '.ship-scss-old-map-');
                @unlink($backup_map);
                if (!@rename($plan['output_abs'] . '.map', $backup_map)) {
                    if ($backup_css && is_file($backup_css)) { @rename($backup_css, $plan['output_abs']); }
                    throw new RuntimeException('既存ソースマップを保護できません。');
                }
            }
            if ($debug && !@rename($map_temp, $plan['output_abs'] . '.map')) {
                throw new RuntimeException('ソースマップの公開に失敗しました。');
            }
            if ($debug) { $published_map = true; }
            if (!@rename($temp, $plan['output_abs'])) {
                throw new RuntimeException('CSSの公開に失敗しました。');
            }
            $published_css = true;
            if ($backup_css && is_file($backup_css)) { @unlink($backup_css); }
            if ($backup_map && is_file($backup_map)) { @unlink($backup_map); }
            $included = method_exists($result, 'getIncludedFiles') ? (array) $result->getIncludedFiles() : array();
            $shutdown_done = true;
            return array('ok' => true, 'css' => $css, 'included' => $included);
        } catch (Throwable $error) {
            if ($temp && is_file($temp)) { @unlink($temp); }
            if ($map_temp && is_file($map_temp)) { @unlink($map_temp); }
            if ($published_css && is_file($plan['output_abs'])) { @unlink($plan['output_abs']); }
            if ($published_map && is_file($plan['output_abs'] . '.map')) { @unlink($plan['output_abs'] . '.map'); }
            if ($backup_css && is_file($backup_css) && !is_file($plan['output_abs'])) { @rename($backup_css, $plan['output_abs']); }
            if ($backup_map && is_file($backup_map) && !is_file($plan['output_abs'] . '.map')) { @rename($backup_map, $plan['output_abs'] . '.map'); }
            $shutdown_done = true;
            return array_merge(array('ok' => false, 'error' => $this->format_exception($error), 'included' => array()), $this->exception_location($error));
        }
    }

    private function failure_result(&$context_state, $plan, $message, $compiled, $ctx, $inventory, $current_fp = '', $duration = 0) {
        $source_rel = $plan['source_rel'];
        $old = isset($context_state['entries'][$source_rel]) && is_array($context_state['entries'][$source_rel]) ? $context_state['entries'][$source_rel] : array();
        $now = $this->now();
        $count = isset($old['failure_count']) ? ((int) $old['failure_count'] + 1) : 1;
        $next = $now + $this->retry_interval();
        $location = isset($compiled['location']) ? $compiled['location'] : array();
        $entry = array_merge($old, array(
            'status' => 'failed', 'source_rel' => $source_rel, 'output_rel' => $plan['output_rel'],
            'last_attempt' => $now, 'duration_ms' => $duration, 'mode' => $this->profile(),
            'settings_sig' => $ctx['settings_sig'], 'last_error' => $message,
            'error_location' => $location, 'failure_fp' => $current_fp ?: $this->input_fingerprint($plan, $old, $inventory, $ctx),
            'failure_settings_sig' => $ctx['settings_sig'], 'failure_count' => $count,
            'next_retry' => $next, 'last_result' => 'failure',
            'output_available' => is_file($plan['output_abs']),
        ));
        $context_state['entries'][$source_rel] = $entry;
        $this->log_event($source_rel . ': ' . $message, $ctx, $source_rel, hash('sha256', $entry['failure_fp'] . '|' . $message));
        return array('status' => 'failure', 'source' => $source_rel, 'message' => $message, 'location' => $location, 'output_available' => is_file($plan['output_abs']), 'preserved' => is_file($plan['output_abs']));
    }

    private function needs_compile($plan, $old, $fingerprint, $ctx) {
        if (!is_file($plan['output_abs'])) { return true; }
        if (!is_array($old) || empty($old['last_success']) || ($old['settings_sig'] ?? '') !== $ctx['settings_sig']) { return true; }
        if (($old['input_fp'] ?? '') !== $fingerprint) { return true; }
        $size = (int) @filesize($plan['output_abs']);
        $mtime = (int) @filemtime($plan['output_abs']);
        if ($size !== (int) ($old['output_size'] ?? -1) || $mtime !== (int) ($old['output_mtime'] ?? -1)) { return true; }
        return $this->debug_enabled() && !is_file($plan['output_abs'] . '.map');
    }

    private function retry_suppressed($old, $fingerprint, $ctx) {
        return is_array($old) && ($old['failure_fp'] ?? '') !== '' && ($old['failure_fp'] ?? '') === $fingerprint
            && ($old['failure_settings_sig'] ?? '') === $ctx['settings_sig'] && (int) ($old['next_retry'] ?? 0) > $this->now();
    }

    private function input_fingerprint($plan, $old, $inventory, $ctx) {
        $items = array('source' => isset($inventory[$plan['source_rel']]['hash']) ? $inventory[$plan['source_rel']]['hash'] : 'missing');
        if (!empty($old['dependencies']) && is_array($old['dependencies'])) {
            foreach ($old['dependencies'] as $rel => $hash) {
                $items['dep:' . $rel] = isset($inventory[$rel]['hash']) ? $inventory[$rel]['hash'] : 'missing';
            }
            if (!empty($old['deps_unknown'])) {
                $items['inventory'] = $this->inventory_signature($inventory);
            }
        } else {
            $items['inventory'] = $this->inventory_signature($inventory);
        }
        return hash('sha256', wp_json_encode(array('settings' => $ctx['settings_sig'], 'files' => $items)));
    }

    private function fingerprint_for_dependencies($source_rel, $dependencies, $inventory, $ctx, $unknown) {
        $items = array('source' => isset($inventory[$source_rel]['hash']) ? $inventory[$source_rel]['hash'] : 'missing');
        foreach ((array) $dependencies as $rel => $hash) {
            $items['dep:' . $rel] = isset($inventory[$rel]['hash']) ? $inventory[$rel]['hash'] : $hash;
        }
        if ($unknown) { $items['inventory'] = $this->inventory_signature($inventory); }
        return hash('sha256', wp_json_encode(array('settings' => $ctx['settings_sig'], 'files' => $items)));
    }

    private function inventory_signature($inventory) {
        $items = array();
        foreach ((array) $inventory as $rel => $info) {
            $items[$rel] = isset($info['hash']) ? $info['hash'] : 'unknown';
        }
        ksort($items);
        return hash('sha256', wp_json_encode($items));
    }

    private function scan_inventory($ctx, $force) {
        $state = $this->load_state();
        $old = isset($state['contexts'][$ctx['key']]['inventory']) && is_array($state['contexts'][$ctx['key']]['inventory']) ? $state['contexts'][$ctx['key']]['inventory'] : array();
        $last = isset($state['contexts'][$ctx['key']]['last_scan']) ? (int) $state['contexts'][$ctx['key']]['last_scan'] : 0;
        $interval = (int) apply_filters('ship_scss_compiler_scan_interval', 30);
        if (!$force && $interval > 0 && $old && $last > $this->now() - $interval) {
            return $old;
        }
        $found = array();
        try {
            if (is_dir($ctx['input_root'])) {
                // Inventory is recursive even when automatic entrypoint discovery
                // is direct-only. Imported partials can live below the input root;
                // the discovery filter decides which files become standalone CSS.
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ctx['input_root'], FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'scss') { continue; }
                    $path = $file->getPathname();
                    $real = realpath($path);
                    if ($real === false || !$this->within($real, $ctx['input_real'])) { continue; }
                    $rel = $this->relative_to_input($real, $ctx);
                    if ($rel === false) { continue; }
                    $mtime = (int) $file->getMTime(); $size = (int) $file->getSize();
                    // Once a real scan is due, hash the content even when
                    // mtime/size are unchanged. This avoids missing an edit
                    // made within a coarse timestamp resolution window.
                    $hash = $this->hash_file($real);
                    $found[$rel] = array('hash' => $hash, 'mtime' => $mtime, 'size' => $size, 'exists' => true);
                }
            }
        } catch (Throwable $error) {
            $this->log_event('SCSS入力ディレクトリの検査に失敗しました: ' . $error->getMessage(), $ctx, '', 'scan-' . $ctx['key']);
            return $old;
        }
        if ($ctx['settings']['entry_mode'] === 'explicit') {
            foreach ($this->explicit_entries($ctx['settings']) as $rel) {
                if (!isset($found[$rel])) {
                    $abs = $ctx['input_root'] . '/' . substr($rel, strlen($ctx['settings']['input_dir']) + 1);
                    $found[$rel] = array('hash' => 'missing', 'mtime' => 0, 'size' => 0, 'exists' => false);
                }
            }
        }
        ksort($found);
        return $found;
    }

    private function entry_plans($ctx, $inventory) {
        $plans = array(); $outputs = array();
        foreach ($this->entrypoint_paths($ctx, $inventory) as $source_rel) {
            $within_input = substr($source_rel, strlen($ctx['settings']['input_dir']) + 1);
            $output_rel = $ctx['settings']['output_dir'] . '/' . substr($within_input, 0, -5) . '.css';
            $source_abs = $ctx['root'] . '/' . $source_rel;
            $output_abs = $ctx['root'] . '/' . $output_rel;
            $collision = isset($outputs[$output_rel]);
            if ($collision) {
                foreach ($plans as &$previous_plan) {
                    if ($previous_plan['output_rel'] === $output_rel) {
                        $previous_plan['collision'] = true;
                    }
                }
                unset($previous_plan);
            }
            $outputs[$output_rel] = $source_rel;
            $plans[] = array(
                'source_rel' => $source_rel, 'output_rel' => $output_rel, 'source_abs' => $source_abs,
                'output_abs' => $output_abs, 'missing' => !isset($inventory[$source_rel]) || empty($inventory[$source_rel]['exists']),
                'empty' => isset($inventory[$source_rel]) && !$this->source_nonempty($source_abs), 'collision' => $collision,
            );
        }
        return $plans;
    }

    private function entrypoint_paths($ctx, $inventory) {
        $out = array();
        if ($ctx['settings']['entry_mode'] === 'explicit') {
            $out = $this->explicit_entries($ctx['settings']);
        } else {
            foreach ($inventory as $rel => $info) {
                $base = basename($rel);
                if (strtolower(substr($rel, -5)) === '.scss' && substr($base, 0, 1) !== '_') {
                    if ($ctx['settings']['include_subdirectories'] || strpos(substr($rel, strlen($ctx['settings']['input_dir']) + 1), '/') === false) {
                        $out[] = $rel;
                    }
                }
            }
        }
        sort($out); return array_values(array_unique($out));
    }

    private function explicit_entries($settings) {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $settings['entrypoints']) as $line) {
            $line = trim($line); if ($line === '') { continue; }
            $path = $this->normalize_relative_path($line); if ($path === false) { continue; }
            $out[] = $settings['input_dir'] . '/' . $path;
        }
        return array_values(array_unique($out));
    }

    private function build_context() {
        $paths = $this->paths(); $settings = $this->settings(); $root = $paths['root'];
        if (!$root || !is_dir($root) || !$this->normalize_relative_path($settings['input_dir']) || !$this->normalize_relative_path($settings['output_dir'])) {
            return array('error' => 'テーマの入出力パス設定が不正です。');
        }
        $input_root = $root . '/' . $settings['input_dir'];
        if (!is_dir($input_root) || !is_readable($input_root)) {
            return array('error' => 'SCSS入力ディレクトリが存在しないか、読み取れません。');
        }
        $input_real = realpath($input_root);
        if ($input_real === false || !$this->within($input_real, $root)) {
            return array('error' => 'SCSS入力ディレクトリがテーマ外を参照しています。');
        }
        $sig_data = array('root' => $root, 'input' => $settings['input_dir'], 'output' => $settings['output_dir'], 'mode' => $settings['entry_mode'], 'entries' => $settings['entrypoints'], 'recursive' => $settings['include_subdirectories'], 'debug' => $this->debug_enabled(), 'embed' => $settings['embed_sources']);
        $sig = hash('sha256', wp_json_encode($sig_data));
        return array('key' => md5($root . '|' . $sig), 'root' => $root, 'input_root' => $input_root, 'input_real' => $input_real, 'settings' => $settings, 'settings_sig' => $sig, 'error' => '');
    }

    private function new_context_state($ctx) {
        return array('schema' => self::SCHEMA_VERSION, 'root' => isset($ctx['root']) ? $ctx['root'] : '', 'settings_sig' => isset($ctx['settings_sig']) ? $ctx['settings_sig'] : '', 'entries' => array(), 'managed' => array(), 'managed_maps' => array(), 'inventory' => array(), 'last_scan' => 0, 'warnings' => array());
    }

    private function load_state() {
        $state = get_option(self::STATE_OPTION, array());
        if (!is_array($state) || (int) ($state['schema'] ?? 0) !== self::SCHEMA_VERSION) {
            $state = array('schema' => self::SCHEMA_VERSION, 'contexts' => array());
        }
        if (!isset($state['contexts']) || !is_array($state['contexts'])) { $state['contexts'] = array(); }
        return $state;
    }

    private function save_state($state) {
        $state['schema'] = self::SCHEMA_VERSION;
        update_option(self::STATE_OPTION, $state, false);
    }

    private function context_key() {
        $ctx = $this->build_context(); return isset($ctx['key']) ? $ctx['key'] : '';
    }

    private function cleanup_owned_maps(&$state, $ctx, &$context_state) {
        foreach ($state['contexts'] as $key => &$old_context) {
            if (!empty($old_context['root']) && $old_context['root'] !== $ctx['root']) {
                continue;
            }
            if (!isset($old_context['managed_maps']) || !is_array($old_context['managed_maps'])) { continue; }
            foreach ($old_context['managed_maps'] as $rel => $meta) {
                $path = $ctx['root'] . '/' . $rel;
                if (!$this->safe_theme_file($path, $ctx['root']) || substr($rel, -4) !== '.map') { continue; }
                if (is_file($path) && !@unlink($path)) {
                    $context_state['warnings'][] = '管理対象ソースマップを削除できませんでした: ' . $rel;
                    continue;
                }
                unset($old_context['managed_maps'][$rel]);
            }
        }
        unset($old_context);
        foreach ($context_state['managed'] as $css_rel => $meta) {
            if (is_file($ctx['root'] . '/' . $css_rel . '.map') && empty($context_state['managed_maps'][$css_rel . '.map'])) {
                $context_state['warnings'][] = '所有情報のない既存.mapは削除していません: ' . $css_rel . '.map';
            }
        }
    }

    private function log_event($message, $ctx, $entry, $dedupe) {
        $logs = get_option(self::LOG_OPTION, array()); if (!is_array($logs)) { $logs = array(); }
        $message = $this->sanitize_message($message, $ctx);
        $now = $this->now();
        foreach ($logs as $log) { if (($log['fingerprint'] ?? '') === $dedupe && $now - (int) ($log['time'] ?? 0) < $this->retry_interval()) { return; } }
        $logs[] = array('time' => $now, 'entry' => $entry, 'message' => substr($message, 0, 4096), 'fingerprint' => $dedupe);
        $cut = $now - 30 * (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);
        $logs = array_values(array_filter($logs, function ($log) use ($cut) { return (int) ($log['time'] ?? 0) >= $cut; }));
        if (count($logs) > 100) { $logs = array_slice($logs, -100); }
        update_option(self::LOG_OPTION, $logs, false);
    }

    private function sanitize_message($message, $ctx) {
        $message = wp_strip_all_tags((string) $message);
        $replacements = array();
        if (!empty($ctx['root'])) { $replacements[$ctx['root']] = '[theme]'; }
        if (!empty($ctx['input_root'])) { $replacements[$ctx['input_root']] = '[scss]'; }
        if ($replacements) { $message = str_replace(array_keys($replacements), array_values($replacements), $message); }
        $message = preg_replace('~(?:[A-Za-z]:)?[\\/][^\s:]+~', '[path]', $message);
        return trim($message);
    }

    private function report_error($message) {
        return array('locked' => false, 'counts' => array('success' => 0, 'failure' => 1, 'skipped' => 0), 'results' => array('_global' => array('status' => 'failure', 'message' => $message)));
    }

    private function acquire_lock($key) {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/' . self::LOCK_PREFIX . md5($key) . '.lock';
        $handle = @fopen($path, 'c');
        if (!$handle || !@flock($handle, LOCK_EX | LOCK_NB)) { if (is_resource($handle)) { @fclose($handle); } return false; }
        return $handle;
    }

    private function release_lock($lock) { if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); } }

    private function exception_location($error) {
        $location = array();
        $cursor = $error;
        for ($depth = 0; $cursor && $depth < 4; $depth++) {
            if (method_exists($cursor, 'getSourcePosition')) {
                $position = $cursor->getSourcePosition();
                if (is_array($position)) { $location = array('file' => $this->safe_location_file(isset($position[0]) ? $position[0] : ''), 'line' => isset($position[1]) ? ((int) $position[1] + 1) : 0, 'column' => isset($position[2]) ? ((int) $position[2] + 1) : 0); }
            } elseif (method_exists($cursor, 'getSpan')) {
                $span = $cursor->getSpan(); $start = $span->getStart();
                $location = array('file' => $this->safe_location_file($start->getSourceUrl()), 'line' => $start->getLine() + 1, 'column' => $start->getColumn() + 1);
            }
            if (!empty($location)) { break; }
            $cursor = $cursor->getPrevious();
        }
        return array('location' => $location);
    }

    private function safe_location_file($file) {
        if (!is_string($file) || $file === '') { return ''; }
        return basename(parse_url($file, PHP_URL_PATH) ?: $file);
    }

    private function format_exception($error) {
        $message = method_exists($error, 'getOriginalMessage') ? $error->getOriginalMessage() : $error->getMessage();
        return substr($this->sanitize_message($message, array('root' => '', 'input_root' => '')), 0, 4096);
    }

    private function hash_file($file) { $hash = is_file($file) ? @hash_file('sha256', $file) : false; return is_string($hash) ? $hash : 'missing'; }
    private function source_nonempty($file) { return is_file($file) && (int) @filesize($file) > 0; }
    private function now() { return function_exists('current_time') ? (int) current_time('timestamp') : time(); }
    private function admin_notice($message, $type) { set_transient('ship_scss_compiler_admin_notice_' . get_current_user_id(), array('message' => $message, 'type' => $type), 60); }

    private function theme_root() { $path = realpath(get_stylesheet_directory()); return $path !== false ? rtrim($path, '/') : rtrim(get_stylesheet_directory(), '/'); }
    private function normalize_relative_path($path) {
        $path = str_replace('\\', '/', trim((string) $path));
        if ($path === '' || strpos($path, "\0") !== false || preg_match('#^(?:/|[A-Za-z]:/|[A-Za-z][A-Za-z0-9+.-]*://)#', $path)) { return false; }
        $parts = explode('/', $path); $clean = array();
        foreach ($parts as $part) { if ($part === '' || $part === '.') { continue; } if ($part === '..') { return false; } $clean[] = $part; }
        return empty($clean) ? false : implode('/', $clean);
    }
    private function within($path, $root) { $path = rtrim(str_replace('\\', '/', $path), '/'); $root = rtrim(str_replace('\\', '/', $root), '/'); return $path === $root || strpos($path, $root . '/') === 0; }
    private function safe_theme_file($path, $root) { $real = realpath($path); return $real !== false && $this->within($real, $root); }
    private function relative_to_input($file, $ctx) { $real = realpath($file); if ($real === false || !$this->within($real, $ctx['input_real'])) { return false; } return $ctx['settings']['input_dir'] . '/' . ltrim(substr(str_replace('\\', '/', $real), strlen(rtrim(str_replace('\\', '/', $ctx['input_real']), '/'))), '/'); }
    private function relative_directory($output_file, $input_root) {
        $from = dirname($output_file); $from_parts = explode('/', trim(str_replace('\\', '/', $from), '/')); $to_parts = explode('/', trim(str_replace('\\', '/', $input_root), '/')); while ($from_parts && $to_parts && $from_parts[0] === $to_parts[0]) { array_shift($from_parts); array_shift($to_parts); } return str_repeat('../', count($from_parts)) . implode('/', $to_parts) . '/';
    }
    private function ensure_directory($dir, $root) {
        if (is_dir($dir)) { $real = realpath($dir); if ($real === false || !$this->within($real, $root)) { throw new RuntimeException('出力ディレクトリがテーマ外を参照しています。'); } return true; }
        $parent = dirname($dir); if ($parent === $dir) { throw new RuntimeException('出力ディレクトリを作成できません。'); }
        $this->ensure_directory($parent, $root); if (!@mkdir($dir, 0755) && !is_dir($dir)) { throw new RuntimeException('出力ディレクトリを作成できません。'); }
        $real = realpath($dir); if ($real === false || !$this->within($real, $root)) { throw new RuntimeException('出力ディレクトリがテーマ外を参照しています。'); } return true;
    }

    public function add_settings_page() { add_options_page('Ship SCSS Compiler', 'Ship SCSS Compiler', 'manage_options', 'ship-scss-compiler', array($this, 'render_settings_page')); }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) { return; }
        $settings = $this->settings(); $ctx = $this->build_context(); $state = $this->load_state(); $context = isset($state['contexts'][$ctx['key']]) ? $state['contexts'][$ctx['key']] : $this->new_context_state($ctx); $plans = !empty($ctx['error']) ? array() : $this->entry_plans($ctx, $this->scan_inventory($ctx, false));
        $notice_key = self::NOTICE_PREFIX . get_current_user_id(); $notice = get_transient($notice_key); if ($notice) { delete_transient($notice_key); }
        if ($notice) { echo '<div class="notice notice-' . esc_attr($notice['type']) . '"><p>' . esc_html($notice['message']) . '</p></div>'; }
        $manual_report = get_transient(self::RESULT_PREFIX . get_current_user_id());
        if ($manual_report) {
            delete_transient(self::RESULT_PREFIX . get_current_user_id());
            echo '<h2>直近の手動実行結果</h2><ul>';
            foreach ((array) (isset($manual_report['results']) ? $manual_report['results'] : array()) as $entry => $result) {
                $status = isset($result['status']) ? $this->status_label($result['status']) : '不明';
                $message = isset($result['message']) ? ': ' . $result['message'] : '';
                echo '<li><code>' . esc_html($entry) . '</code> — ' . esc_html($status . $message) . '</li>';
            }
            echo '</ul>';
        }
        $validation_notice = get_transient('ship_scss_compiler_admin_notice_' . get_current_user_id());
        if ($validation_notice) { delete_transient('ship_scss_compiler_admin_notice_' . get_current_user_id()); echo '<div class="notice notice-' . esc_attr($validation_notice['type']) . '"><p>' . esc_html($validation_notice['message']) . '</p></div>'; }
        $logs = get_option(self::LOG_OPTION, array());
        ?>
        <div class="wrap">
            <h1>Ship SCSS Compiler</h1>
            <p>SCSS入力、差分コンパイル、公開CSSの保護状態を管理します。</p>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('ship_scss_compiler_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">SCSS入力ディレクトリ</th><td><input class="regular-text" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[input_dir]" value="<?php echo esc_attr($settings['input_dir']); ?>" /></td></tr>
                    <tr><th scope="row">CSS出力ディレクトリ</th><td><input class="regular-text" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[output_dir]" value="<?php echo esc_attr($settings['output_dir']); ?>" /></td></tr>
                    <tr><th scope="row">対象の選択方法</th><td><select name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[entry_mode]"><option value="auto" <?php selected($settings['entry_mode'], 'auto'); ?>>自動検出</option><option value="explicit" <?php selected($settings['entry_mode'], 'explicit'); ?>>明示指定</option></select><p class="description">自動検出では _ で始まるpartialを除外します。</p></td></tr>
                    <tr><th scope="row">明示エントリーポイント</th><td><textarea class="large-text code" rows="5" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[entrypoints]"><?php echo esc_textarea($settings['entrypoints']); ?></textarea><p class="description">入力ディレクトリからの相対SCSSパスを1行ずつ指定します。</p></td></tr>
                    <tr><th scope="row">サブディレクトリ</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[include_subdirectories]" value="1" <?php checked($settings['include_subdirectories']); ?> /> 自動検出で含める</label></td></tr>
                    <tr><th scope="row">CSSデバッグ</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[debug]" value="1" <?php checked($settings['debug']); ?> /> 展開CSSと外部ソースマップを生成する</label><p class="description">元SCSSの内容・構造がURLから取得可能になる可能性があるため、本番では通常モードを推奨します。</p></td></tr>
                    <tr><th scope="row">sourcesContent</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[embed_sources]" value="1" <?php checked($settings['embed_sources']); ?> /> ソースマップにSCSS内容を埋め込む</label></td></tr>
                    <tr><th scope="row">デバッグ解除時の.map削除</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[delete_maps_on_disable]" value="1" <?php checked($settings['delete_maps_on_disable']); ?> /> このプラグインの所有が確認できる.mapだけ削除する</label></td></tr>
                    <tr><th scope="row">CSSキャッシュ更新補助</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[cache_busting]" value="1" <?php checked($settings['cache_busting']); ?> /> 管理対象CSSのverに内容ハッシュを使用する</label></td></tr>
                    <tr><th scope="row">失敗の再試行間隔</th><td><input type="number" min="30" max="86400" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[retry_interval]" value="<?php echo esc_attr($settings['retry_interval']); ?>" /> 秒</td></tr>
                </table>
                <?php submit_button('設定を保存'); ?>
            </form>
            <h2>手動再コンパイル</h2>
            <p>手動実行では変更判定と再試行抑制を無視します。実行後はこの画面へ戻ります。</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1em;"><?php wp_nonce_field('ship_scss_compiler_recompile_all'); ?><input type="hidden" name="action" value="ship_scss_compiler_recompile_all" /><?php submit_button('すべて再コンパイル', 'secondary', 'submit', false); ?></form>
            <?php if ($plans) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ship_scss_compiler_recompile_selected'); ?><input type="hidden" name="action" value="ship_scss_compiler_recompile_selected" /><table class="widefat striped"><thead><tr><th></th><th>入力SCSS</th><th>出力CSS</th><th>状態</th><th>最終成功</th><th>処理時間</th><th>依存数</th><th>詳細</th></tr></thead><tbody><?php foreach ($plans as $plan) : $e = isset($context['entries'][$plan['source_rel']]) ? $context['entries'][$plan['source_rel']] : array(); ?><tr><td><input type="checkbox" name="entrypoints[]" value="<?php echo esc_attr($plan['source_rel']); ?>" /></td><td><code><?php echo esc_html($plan['source_rel']); ?></code></td><td><code><?php echo esc_html($plan['output_rel']); ?></code></td><td><?php echo esc_html($this->status_label(isset($e['status']) ? $e['status'] : '未実行')); ?></td><td><?php echo esc_html($this->format_time(isset($e['last_success']) ? $e['last_success'] : 0)); ?></td><td><?php echo esc_html(isset($e['duration_ms']) ? $e['duration_ms'] . ' ms' : '—'); ?></td><td><?php echo esc_html(isset($e['dependencies']) && is_array($e['dependencies']) ? count($e['dependencies']) : 0); ?></td><td><?php echo esc_html('モード: ' . (isset($e['last_success_mode']) ? $e['last_success_mode'] : '—') . ' / CSS ver: ' . (isset($e['version']) ? $e['version'] : '—')); ?><?php if (!empty($e['last_error'])) : ?><br /><span style="color:#b32d2e"><?php echo esc_html('エラー: ' . $e['last_error']); ?></span><?php $loc = isset($e['error_location']) && is_array($e['error_location']) ? $e['error_location'] : array(); if (!empty($loc['file']) || !empty($loc['line'])) : ?><br /><?php echo esc_html('位置: ' . (isset($loc['file']) ? $loc['file'] : '') . ' ' . (isset($loc['line']) ? $loc['line'] : '') . ':' . (isset($loc['column']) ? $loc['column'] : '')); ?><?php endif; ?><?php endif; ?><?php if (!empty($e['next_retry'])) : ?><br /><?php echo esc_html('次回試行: ' . $this->format_time($e['next_retry'])); ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><p><?php submit_button('選択したファイルを再コンパイル', 'secondary', 'submit', false); ?></p></form><?php endif; ?>
            <h2>ログ</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ship_scss_compiler_clear_logs'); ?><input type="hidden" name="action" value="ship_scss_compiler_clear_logs" /><?php submit_button('保存ログを削除', 'secondary', 'submit', false); ?></form><?php if (is_array($logs) && $logs) : ?><ul><?php foreach (array_reverse($logs) as $log) : ?><li><code><?php echo esc_html($this->format_time(isset($log['time']) ? $log['time'] : 0)); ?></code> <?php echo esc_html(isset($log['message']) ? $log['message'] : ''); ?></li><?php endforeach; ?></ul><?php else : ?><p>保存されたエラーはありません。</p><?php endif; ?>
            <?php if (!empty($context['warnings'])) : ?><h2>警告</h2><ul><?php foreach ($context['warnings'] as $warning) : ?><li><?php echo esc_html($warning); ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php $legacy = !empty($ctx['input_root']) && is_file($ctx['input_root'] . '/error_log.log'); if ($legacy) : ?><h2>旧ログ</h2><p>旧バージョンの公開ディレクトリ内ログが残っています。自動削除はしていません。内容を確認したうえで個別に削除してください。</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ship_scss_compiler_delete_legacy_log'); ?><input type="hidden" name="action" value="ship_scss_compiler_delete_legacy_log" /><?php submit_button('旧error_log.logを削除', 'delete', 'submit', false); ?></form><?php endif; ?>
        </div>
        <?php
    }

    public function handle_recompile_all() { $this->handle_manual('all'); }
    public function handle_recompile_selected() { $this->handle_manual('selected'); }
    private function handle_manual($kind) {
        if (!current_user_can('manage_options')) { wp_die('権限がありません。'); }
        check_admin_referer('ship_scss_compiler_recompile_' . ($kind === 'all' ? 'all' : 'selected'));
        $selected = $kind === 'selected' ? (isset($_POST['entrypoints']) ? (array) $_POST['entrypoints'] : array()) : array();
        self::$ran_this_request = false;
        $report = $this->run(true, $selected, $kind === 'all' ? 'manual-all' : 'manual-selected');
        set_transient(self::RESULT_PREFIX . get_current_user_id(), $report, 60);
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('message' => $this->report_message($report), 'type' => !empty($report['locked']) || !empty($report['counts']['failure']) ? 'error' : 'success'), 60);
        wp_safe_redirect(admin_url('options-general.php?page=ship-scss-compiler')); exit;
    }
    public function handle_clear_logs() { if (!current_user_can('manage_options')) { wp_die('権限がありません。'); } check_admin_referer('ship_scss_compiler_clear_logs'); delete_option(self::LOG_OPTION); wp_safe_redirect(admin_url('options-general.php?page=ship-scss-compiler')); exit; }
    public function handle_delete_legacy_log() { if (!current_user_can('manage_options')) { wp_die('権限がありません。'); } check_admin_referer('ship_scss_compiler_delete_legacy_log'); $ctx = $this->build_context(); $file = !empty($ctx['input_root']) ? $ctx['input_root'] . '/error_log.log' : ''; if ($file && $this->safe_theme_file($file, $ctx['root']) && is_file($file)) { @unlink($file); } wp_safe_redirect(admin_url('options-general.php?page=ship-scss-compiler')); exit; }
    private function report_message($report) { if (!empty($report['locked'])) { return '別の処理が実行中です。'; } $c = isset($report['counts']) ? $report['counts'] : array(); return '再コンパイル完了: 成功 ' . (int) ($c['success'] ?? 0) . '、失敗 ' . (int) ($c['failure'] ?? 0) . '、スキップ ' . (int) ($c['skipped'] ?? 0); }
    private function status_label($status) { $labels = array('success' => '成功', 'failure' => '失敗（既存CSS維持）', 'failed' => '失敗（既存CSS維持）', 'retry_wait' => '再試行待ち', 'skipped' => 'スキップ', 'running' => '実行中', '未実行' => '未実行'); return isset($labels[$status]) ? $labels[$status] : $status; }
    private function format_time($timestamp) { return $timestamp ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '—'; }
}
