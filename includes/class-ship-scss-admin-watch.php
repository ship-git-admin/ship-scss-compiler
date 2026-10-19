<?php
/** Lightweight, session-bound SCSS probing; WordPress is loaded only on change. */
class Ship_SCSS_Admin_Watch {
    private $directory;

    public function __construct($directory = null) {
        $this->directory = $directory ?: rtrim(sys_get_temp_dir(), '/') . '/ship-scss-admin-' . substr(hash('sha256', realpath(dirname(__DIR__))), 0, 32);
    }

    public function attach() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        add_action('template_redirect', array($this, 'prevent_frontend_cache'));
        add_action('admin_bar_menu', array($this, 'admin_bar'), 100);
        add_action('wp_ajax_ship_scss_watch_lease', array($this, 'ajax_lease'));
    }

    public function enqueue() {
        if (!self::can_watch() || is_network_admin()) { return; }
        wp_enqueue_script('ship-scss-admin-watch', plugins_url('assets/admin-watch.js', SHIP_SCSS_COMPILER_FILE), array(), SHIP_SCSS_COMPILER_VERSION, true);
        wp_localize_script('ship-scss-admin-watch', 'shipScssWatch', array(
            'ajax' => admin_url('admin-ajax.php'), 'probe' => plugins_url('admin-watch.php', SHIP_SCSS_COMPILER_FILE),
            'nonce' => wp_create_nonce('ship-scss-watch'),
        ));
    }

    public function admin_bar($bar) {
        if (self::can_watch() && !is_network_admin()) {
            $bar->add_node(array('id' => 'ship-scss-watch', 'title' => 'SCSS監視: 準備中', 'href' => admin_url('options-general.php?page=ship-scss-compiler')));
        }
    }

    public static function can_watch() {
        return is_user_logged_in() && current_user_can('manage_options') &&
            in_array('administrator', (array) wp_get_current_user()->roles, true);
    }

    public function prevent_frontend_cache() {
        if (is_admin() || !self::can_watch()) { return; }
        // Never let a compliant page cache serve an administrator's nonce and
        // monitoring script to ordinary visitors. Their responses are untouched.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
    }

    public static function configuration($compiler) {
        $paths = $compiler->paths();
        return array('wp_root' => realpath(ABSPATH), 'theme' => realpath($paths['root']), 'input' => realpath($paths['scss']),
            'settings' => hash('sha256', json_encode(array($compiler->settings(), $compiler->profile()))));
    }

    public function ajax_lease() {
        check_ajax_referer('ship-scss-watch', 'nonce');
        if (!self::can_watch()) { wp_send_json_error(array('message' => '監視権限がありません。'), 403); }
        try {
            $cookie = isset($_COOKIE[LOGGED_IN_COOKIE]) ? (string) $_COOKIE[LOGGED_IN_COOKIE] : '';
            if ($cookie === '') { throw new RuntimeException('管理者セッションを確認できません。'); }
            $previous = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
            $lease = $this->issue(self::configuration($GLOBALS['ship_scss_compiler']), LOGGED_IN_COOKIE, $cookie, get_current_user_id(), microtime(true), $previous);
            wp_send_json_success($lease);
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => 'SCSS監視を開始できません。管理画面を再読み込みしてください。'), 503);
        }
    }

    private function prepare() {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700)) { throw new RuntimeException('Private watch directory unavailable.'); }
        clearstatcache(true, $this->directory);
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (is_link($this->directory) || fileowner($this->directory) !== $uid || (fileperms($this->directory) & 0077)) {
            throw new RuntimeException('Unsafe watch directory.');
        }
    }

    private function read($name) {
        $path = $this->directory . '/' . $name;
        if (is_link($path) || !is_file($path) || filesize($path) > 32768) { return array(); }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : array();
    }

    private function write($name, $value) {
        $path = tempnam($this->directory, '.write-');
        if ($path === false) { throw new RuntimeException('Cannot store watch state.'); }
        try {
            if (!chmod($path, 0600) || file_put_contents($path, json_encode($value), LOCK_EX) === false || !rename($path, $this->directory . '/' . $name)) {
                throw new RuntimeException('Cannot store watch state.');
            }
        } finally {
            if (is_file($path)) { @unlink($path); }
        }
    }

    private function lease_name($token) { return 'lease-' . hash('sha256', $token) . '.json'; }
    private function valid_token($token) { return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token); }

    public function issue($config, $cookie_name, $cookie, $user_id, $now, $previous = '') {
        $this->prepare();
        if (empty($config['wp_root']) || empty($config['theme']) || empty($config['input']) || !is_dir($config['input']) ||
            strpos($config['input'] . '/', rtrim($config['theme'], '/') . '/') !== 0 || !$cookie || !$user_id) {
            throw new RuntimeException('Invalid watch configuration.');
        }
        // Tokens/configuration stay outside the web root; never use uploads as fallback.
        if (strpos(realpath($this->directory) . '/', rtrim($config['wp_root'], '/') . '/') === 0) {
            throw new RuntimeException('Watch state must not be public.');
        }
        $old = $this->valid_token($previous) ? $this->read($this->lease_name($previous)) : array();
        $cookie_hash = hash('sha256', $cookie);
        $reuse = isset($old['cookie_hash'], $old['user']) && hash_equals($old['cookie_hash'], $cookie_hash) && $old['user'] === (int) $user_id;
        $token = $reuse ? $previous : bin2hex(random_bytes(32));
        $this->write($this->lease_name($token), array('config' => $config, 'cookie_name' => $cookie_name, 'cookie_hash' => $cookie_hash,
            'user' => (int) $user_id, 'expires' => $now + 180));
        // Bound expired lease accumulation, without touching unrelated files.
        $count = 0;
        foreach (new DirectoryIterator($this->directory) as $file) {
            if (++$count > 100) { break; }
            if (preg_match('/\Alease-[a-f0-9]{64}\.json\z/D', $file->getFilename()) && !$file->isLink()) {
                $lease = $this->read($file->getFilename());
                if (($lease['expires'] ?? 0) <= $now) { @unlink($file->getPathname()); }
            } elseif (preg_match('/\Astate-[a-f0-9]{64}\.json\z/D', $file->getFilename()) && !$file->isLink() && $file->getMTime() < $now - 86400) {
                @unlink($file->getPathname());
            }
        }
        return array('token' => $token, 'expires_in' => 180);
    }

    private function fingerprint($root) {
        if (!is_dir($root) || !is_readable($root)) { throw new RuntimeException('Input unavailable.'); }
        $files = array();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'scss') { continue; }
            $real = realpath($file->getPathname());
            if (!$real || strpos($real, rtrim($root, '/') . '/') !== 0) { continue; }
            $hash = @hash_file('sha256', $real);
            if (!is_string($hash)) { throw new RuntimeException('Input unreadable.'); }
            $files[substr($real, strlen($root))] = $hash;
        }
        ksort($files);
        return hash('sha256', json_encode($files));
    }

    public function poll($token, $cookies, $now, $compile) {
        if (!$this->valid_token($token)) { return array('http' => 403, 'status' => 'forbidden'); }
        $this->prepare();
        $lease = $this->read($this->lease_name($token));
        if (!$lease || ($lease['expires'] ?? 0) <= $now) { return array('http' => 410, 'status' => 'expired'); }
        $cookie = $cookies[$lease['cookie_name']] ?? '';
        if (!is_string($cookie) || !hash_equals($lease['cookie_hash'], hash('sha256', $cookie))) {
            return array('http' => 403, 'status' => 'forbidden');
        }
        $lock_path = $this->directory . '/probe.lock';
        if (is_link($lock_path)) { throw new RuntimeException('Unsafe lock.'); }
        $lock = @fopen($lock_path, 'c');
        if (!$lock) { throw new RuntimeException('Watch lock unavailable.'); }
        @chmod($lock_path, 0600);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return array('http' => 200, 'status' => 'busy'); }
            $name = 'state-' . hash('sha256', json_encode($lease['config'])) . '.json';
            $state = $this->read($name);
            if (($state['checked'] ?? 0) > $now - 2) { return array('http' => 200, 'status' => $state['status'] ?? 'waiting'); }
            $fingerprint = $this->fingerprint($lease['config']['input']);
            $state['checked'] = $now;
            if (($state['ack'] ?? '') === $fingerprint) {
                $state['status'] = 'unchanged';
            } elseif (($state['retry_fp'] ?? '') === $fingerprint && ($state['retry_at'] ?? 0) > $now) {
                $state['status'] = 'error';
            } elseif (($state['candidate'] ?? '') !== $fingerprint || $now - ($state['since'] ?? $now) < 0.25) {
                if (($state['candidate'] ?? '') !== $fingerprint) { $state['since'] = $now; }
                $state['candidate'] = $fingerprint;
                $state['status'] = 'waiting';
            } else {
                // The callback must revalidate the WP session/capability/context.
                try { $result = call_user_func($compile, $lease); }
                catch (Throwable $error) { $result = array('ok' => false); }
                if (!empty($result['renew'])) { return array('http' => 409, 'status' => 'renew'); }
                if (!empty($result['locked'])) {
                    $state['status'] = 'busy';
                } elseif (!empty($result['ok'])) {
                    $state['ack'] = $fingerprint;
                    unset($state['retry_fp'], $state['retry_at']);
                    $state['status'] = !empty($result['compiled']) ? 'compiled' : 'unchanged';
                } else {
                    $state['retry_fp'] = $fingerprint;
                    $state['retry_at'] = $now + 60;
                    $state['status'] = 'error';
                }
            }
            $this->write($name, $state);
            return array('http' => 200, 'status' => $state['status']);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
