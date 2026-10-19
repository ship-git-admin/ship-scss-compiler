<?php
// Remote-only, bounded real-SFTP integration. No public theme/DB state writes.
list(, $root, $plugin, $wp_root) = $argv;
if (strpos(basename($root), 'ship-scss-probe-') !== 0) { throw new RuntimeException('Isolated root required.'); }
require $plugin . '/includes/class-ship-scss-admin-watch.php';
$watch = new Ship_SCSS_Admin_Watch($root . '/watch-state');
$config = array('wp_root' => $wp_root, 'theme' => $root, 'input' => $root . '/scss', 'settings' => 'integration');
$lease = $watch->issue($config, 'fixture', 'fixture-cookie', 1, microtime(true));
$calls = 0;
$compile = function () use ($root, $plugin, $wp_root, &$calls) {
    $calls++;
    $cmd = array('/usr/local/php/8.3/bin/php', '-d', 'error_reporting=0', '/home/users/0/ship0028/bin/wp',
        '--path=' . $wp_root, '--skip-plugins=ship-scss-compiler', 'eval-file', $plugin . '/tests/fixtures/compile_probe.php');
    $env = array_merge(getenv(), array('SHIP_SCSS_PROBE_ROOT' => $root, 'SHIP_SCSS_CANDIDATE_DIR' => $plugin));
    $process = proc_open(implode(' ', array_map('escapeshellarg', $cmd)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $wp_root, $env);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    echo 'COMPILER_EXIT=' . $code . "\n";
    if ($code) { echo substr($stdout . $stderr, -200), "\n"; }
    if (!$code && is_file($root . '/css/probe.css')) {
        clearstatcache();
        echo 'SOURCE_TO_CSS_SECONDS=' . round(filemtime($root . '/css/probe.css') - filemtime($root . '/scss/_colors.scss'), 3) . "\n";
        echo 'CSS=' . file_get_contents($root . '/css/probe.css') . "\n";
    }
    return array('ok' => $code === 0, 'locked' => $code === 75, 'compiled' => $code === 0 ? 1 : 0);
};
$until = microtime(true) + 48;
do {
    $result = $watch->poll($lease['token'], array('fixture' => 'fixture-cookie'), microtime(true), $compile);
    echo 'POLL=' . $result['status'] . "\n";
    flush();
    usleep(2000000);
} while (microtime(true) < $until);
echo 'COMPILER_STARTS=' . $calls . "\n";
