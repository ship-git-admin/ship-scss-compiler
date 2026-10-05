<?php
// Included by test_core.php. Count real walks/hashes, not just compilations.
class Ship_Scan_Counting_Compiler extends Ship_SCSS_Compiler {
    public $walks = 0;
    public $hashes = 0;
    public $fail_walk = '';
    public $fail_hash = false;

    protected function inventory_files($root) {
        $this->walks++;
        if ($this->fail_walk === 'open') { throw new RuntimeException('fixture iterator failure'); }
        $files = parent::inventory_files($root);
        if ($this->fail_walk === 'partial') {
            return (function () use ($files) {
                foreach ($files as $file) {
                    yield $file;
                    throw new RuntimeException('fixture partial traversal failure');
                }
            })();
        }
        return $files;
    }

    protected function hash_file($file) {
        $this->hashes++;
        return $this->fail_hash ? 'missing' : parent::hash_file($file);
    }
}

function ship_test_scan_context() {
    $ref = new ReflectionMethod('Ship_SCSS_Compiler', 'build_context');
    $ref->setAccessible(true);
    $ctx = $ref->invoke($GLOBALS['compiler_for_test']);
    $state = get_option(Ship_SCSS_Compiler::STATE_OPTION);
    return $state['contexts'][$ctx['key']];
}

function ship_test_scan_request($elapsed, $force = false, $action = 'auto') {
    $GLOBALS['ship_test_now'] = 1000000000 + $elapsed;
    ship_test_reset_guard();
    return $GLOBALS['compiler_for_test']->run($force, array(), $action);
}

function ship_test_scan_cache() {
    $old_root = $GLOBALS['theme_dir'];
    $GLOBALS['theme_dir'] = $old_root . '/scan-cache';
    mkdir($GLOBALS['theme_dir'] . '/scss', 0700, true);
    mkdir($GLOBALS['theme_dir'] . '/css', 0700, true);
    $source = $GLOBALS['theme_dir'] . '/scss/main.scss';
    $css = $GLOBALS['theme_dir'] . '/css/main.css';
    ship_test_write($source, '.scan { color: #112233; }');
    $GLOBALS['options'][Ship_SCSS_Compiler::SETTINGS_OPTION] = Ship_SCSS_Compiler::defaults();
    $GLOBALS['ship_test_scan_filter'] = function () { return 30; };
    $compiler = new Ship_Scan_Counting_Compiler();
    $GLOBALS['compiler_for_test'] = $compiler;

    $report = ship_test_scan_request(0);
    ship_test_assert($report['counts']['success'] === 1 && $compiler->walks === 1 &&
        ship_test_scan_context()['last_scan'] === 1000000000, 'scan clock: initial request walks and records t=0');
    $initial_css = file_get_contents($css);
    $hashes = $compiler->hashes;
    $report = ship_test_scan_request(31);
    ship_test_assert($report['counts']['success'] === 0 && $compiler->walks === 2 &&
        $compiler->hashes === $hashes + 1 && ship_test_scan_context()['last_scan'] === 1000000031 &&
        file_get_contents($css) === $initial_css, 'scan clock: unchanged successful walk advances to t=31');
    $hashes = $compiler->hashes;
    foreach (array(32, 33) as $elapsed) {
        ship_test_scan_request($elapsed);
        ship_test_assert($compiler->walks === 2 && $compiler->hashes === $hashes &&
            ship_test_scan_context()['last_scan'] === 1000000031,
            'scan clock: t=' . $elapsed . ' reuses cache without walking, hashing or extending its lifetime');
    }

    $mtime = filemtime($source); $size = filesize($source);
    ship_test_write($source, '.scan { color: #445566; }');
    touch($source, $mtime); clearstatcache(true, $source);
    ship_test_assert(filemtime($source) === $mtime && filesize($source) === $size,
        'scan clock: changed-content fixture retains source mtime and size');
    ship_test_scan_request(60);
    ship_test_assert($compiler->walks === 2 && $compiler->hashes === $hashes &&
        file_get_contents($css) === $initial_css && ship_test_scan_context()['last_scan'] === 1000000031,
        'scan clock: access one second before expiry still reuses the original cache');
    $report = ship_test_scan_request(61);
    ship_test_assert($compiler->walks === 3 && $compiler->hashes > $hashes &&
        $report['counts']['success'] === 1 && ship_test_scan_context()['last_scan'] === 1000000061 &&
        strpos(file_get_contents($css), '#445566') !== false,
        'scan clock: exact expiry hashes and detects equal-mtime/equal-size edits');

    $report = ship_test_scan_request(62, true, 'manual-all');
    ship_test_assert($compiler->walks === 4 && $report['counts']['success'] === 1 &&
        ship_test_scan_context()['last_scan'] === 1000000062, 'scan clock: force bypasses the fresh cache');
    $report = ship_test_scan_request(63, false, 'external');
    ship_test_assert($compiler->walks === 5 && $report['counts']['success'] === 0 &&
        ship_test_scan_context()['last_scan'] === 1000000063, 'scan clock: external trigger performs a fresh unchanged walk');
    $GLOBALS['ship_test_now'] = 1000000064;
    $report = $compiler->run(true, array('scss/main.scss'), 'saved-source');
    ship_test_assert($compiler->walks === 6 && $report['counts']['success'] === 1 &&
        ship_test_scan_context()['last_scan'] === 1000000064, 'scan clock: explicit save bypasses cache and same-request guard');
    $hashes = $compiler->hashes;
    $report = $compiler->run(false, array(), 'auto');
    ship_test_assert(!empty($report['duplicate']) && $compiler->walks === 6 && $compiler->hashes === $hashes &&
        ship_test_scan_context()['last_scan'] === 1000000064, 'scan clock: duplicate auto request neither scans nor advances time');

    $GLOBALS['ship_test_scan_filter'] = function () { return 0; };
    foreach (array(65, 66) as $elapsed) {
        $walks = $compiler->walks; $hashes = $compiler->hashes;
        ship_test_scan_request($elapsed);
        ship_test_assert($compiler->walks === $walks + 1 && $compiler->hashes === $hashes + 1 &&
            ship_test_scan_context()['last_scan'] === 1000000000 + $elapsed,
            'scan clock: interval=0 really scans unchanged input at t=' . $elapsed);
    }

    $state_before = get_option(Ship_SCSS_Compiler::STATE_OPTION);
    $css_before = file_get_contents($css);
    $hashes = $compiler->hashes;
    $compiler->fail_walk = 'open';
    $report = ship_test_scan_request(67, true, 'manual-all');
    ship_test_assert($report['counts']['failure'] === 1 && $compiler->hashes === $hashes &&
        get_option(Ship_SCSS_Compiler::STATE_OPTION) === $state_before && file_get_contents($css) === $css_before,
        'scan clock: forced iterator failure preserves last_scan, inventory, entries and CSS');
    $compiler->fail_walk = 'partial';
    $report = ship_test_scan_request(68, true, 'manual-all');
    ship_test_assert($report['counts']['failure'] === 1 && $compiler->hashes === $hashes + 1 &&
        get_option(Ship_SCSS_Compiler::STATE_OPTION) === $state_before && file_get_contents($css) === $css_before,
        'scan clock: partially completed walk is not persisted as successful inventory');
    $compiler->fail_walk = ''; $compiler->fail_hash = true;
    $report = ship_test_scan_request(69, true, 'manual-all');
    ship_test_assert($report['counts']['failure'] === 1 &&
        get_option(Ship_SCSS_Compiler::STATE_OPTION) === $state_before && file_get_contents($css) === $css_before,
        'scan clock: failed source hash preserves state and CSS instead of recording missing content');
    $compiler->fail_hash = false;
    ship_test_scan_request(70);
    ship_test_assert(ship_test_scan_context()['last_scan'] === 1000000070,
        'scan clock: successful retry after scan failures reacquires the released lock');

    ship_test_write($source, '.scan { color: ; }');
    $report = ship_test_scan_request(71, true, 'manual-all');
    ship_test_assert($report['counts']['failure'] === 1 && ship_test_scan_context()['last_scan'] === 1000000071 &&
        !empty(ship_test_scan_context()['entries']['scss/main.scss']['last_error']) && file_get_contents($css) === $css_before,
        'scan clock: successful inventory scan advances time even when compilation fails');
    $state_before = get_option(Ship_SCSS_Compiler::STATE_OPTION);
    $compiler->fail_walk = 'open';
    ship_test_scan_request(72, true, 'manual-all');
    ship_test_assert(get_option(Ship_SCSS_Compiler::STATE_OPTION) === $state_before &&
        !empty(get_option(Ship_SCSS_Compiler::LOG_OPTION)) && file_get_contents($css) === $css_before,
        'scan clock: scan failure preserves existing compile errors and retains diagnostics');
    $compiler->fail_walk = '';

    $ref = new ReflectionClass('Ship_SCSS_Compiler');
    $ctx_method = $ref->getMethod('build_context'); $ctx_method->setAccessible(true);
    $acquire = $ref->getMethod('acquire_lock'); $acquire->setAccessible(true);
    $release = $ref->getMethod('release_lock'); $release->setAccessible(true);
    $held = $acquire->invoke($compiler, $ctx_method->invoke($compiler)['key']);
    ship_test_assert(is_resource($held), 'scan clock: failures release the compiler lock');
    $walks = $compiler->walks; $hashes = $compiler->hashes;
    try {
        $report = ship_test_scan_request(73, true, 'auto');
        ship_test_assert(!empty($report['locked']) && $compiler->walks === $walks && $compiler->hashes === $hashes &&
            get_option(Ship_SCSS_Compiler::STATE_OPTION) === $state_before,
            'scan clock: lock failure does not walk, hash, alter state or advance last_scan');
    } finally {
        $release->invoke($compiler, $held);
    }
    ship_test_write($source, '.scan { color: #778899; }');
    ship_test_scan_request(74, true, 'manual-all');
    ship_test_assert(strpos(file_get_contents($css), '#778899') !== false,
        'scan clock: corrected source publishes after scan and lock failures');

    // A successful empty inventory is not a failed or uninitialized scan.
    $GLOBALS['theme_dir'] = $old_root . '/empty-scan-cache';
    mkdir($GLOBALS['theme_dir'] . '/scss', 0700, true);
    mkdir($GLOBALS['theme_dir'] . '/css', 0700, true);
    $compiler = new Ship_Scan_Counting_Compiler();
    $GLOBALS['compiler_for_test'] = $compiler;
    $GLOBALS['ship_test_scan_filter'] = function () { return 30; };
    ship_test_scan_request(100);
    ship_test_assert($compiler->walks === 1 && $compiler->hashes === 0 &&
        ship_test_scan_context()['inventory'] === array() && ship_test_scan_context()['last_scan'] === 1000000100,
        'scan clock: empty normal inventory is a successful scan');
    ship_test_scan_request(101);
    ship_test_assert($compiler->walks === 1 && $compiler->hashes === 0 &&
        ship_test_scan_context()['last_scan'] === 1000000100, 'scan clock: empty normal inventory is cached');
    ship_test_scan_request(130);
    ship_test_assert($compiler->walks === 2 && $compiler->hashes === 0 &&
        ship_test_scan_context()['last_scan'] === 1000000130, 'scan clock: unchanged empty inventory advances at exact expiry');
    ship_test_write($GLOBALS['theme_dir'] . '/scss/new.scss', '.new { color: red; }');
    $report = ship_test_scan_request(160);
    ship_test_assert($compiler->walks === 3 && $report['counts']['success'] === 1 &&
        is_file($GLOBALS['theme_dir'] . '/css/new.css'), 'scan clock: new entry is discovered after empty cache expires');
    unset($GLOBALS['ship_test_now']);
}
