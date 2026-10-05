<?php
// WP-CLI integration fixture: isolated SCSS/CSS/state, no production DB writes.
if (!class_exists('Ship_SCSS_Compiler')) {
    $candidate = getenv('SHIP_SCSS_CANDIDATE_DIR');
    if (!$candidate || !is_file($candidate . '/includes/class-ship-scss-compiler.php')) {
        throw new RuntimeException('Requires a candidate plugin directory.');
    }
    require_once $candidate . '/scssphp/scss.inc.php';
    require_once $candidate . '/includes/class-ship-scss-compiler.php';
}
$root = getenv('SHIP_SCSS_PROBE_ROOT');
if (!$root || !is_dir($root . '/scss') || strpos(basename($root), 'ship-scss-probe-') !== 0) {
    throw new RuntimeException('Requires an isolated ship-scss-probe directory.');
}
add_filter('stylesheet_directory', static function () use ($root) { return $root; });
add_filter('pre_option_' . Ship_SCSS_Compiler::SETTINGS_OPTION, static function () {
    return array_merge(Ship_SCSS_Compiler::defaults(), array('external_trigger_only' => true));
});
add_filter('pre_option_' . Ship_SCSS_Compiler::STATE_OPTION, static function () use ($root) {
    return is_file($root . '/compiler-state.json') ? json_decode(file_get_contents($root . '/compiler-state.json'), true) : array();
});
add_filter('pre_update_option_' . Ship_SCSS_Compiler::STATE_OPTION, static function ($state, $old) use ($root) {
    file_put_contents($root . '/compiler-state.json', wp_json_encode($state), LOCK_EX);
    return $old;
}, 10, 2);
foreach (array(Ship_SCSS_Compiler::LOG_OPTION, 'ship_scss_compiler_last_profile') as $option) {
    add_filter('pre_option_' . $option, static function () { return array(); });
    add_filter('pre_update_option_' . $option, static function ($value, $old) { return $old; }, 10, 2);
}
if (getenv('SHIP_SCSS_PROBE_SETUP_ONLY')) { return; }
$compiler = new Ship_SCSS_Compiler();
if (getenv('SHIP_SCSS_PROBE_LOCK')) {
    $ref = new ReflectionClass($compiler);
    $context = $ref->getMethod('build_context'); $context->setAccessible(true);
    $acquire = $ref->getMethod('acquire_lock'); $acquire->setAccessible(true);
    $lock = $acquire->invoke($compiler, $context->invoke($compiler)['key']);
    if (!$lock) { throw new RuntimeException('Cannot obtain isolated test lock.'); }
}
$compiler->cli_compile_changed(array(), array());
