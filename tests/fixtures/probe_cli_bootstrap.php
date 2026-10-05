<?php
// Attach isolated inputs/state after normal WordPress boot, before dispatching
// the installed ship-scss compile-changed command. No settings are persisted.
WP_CLI::add_hook('after_wp_load', static function () {
    putenv('SHIP_SCSS_PROBE_SETUP_ONLY=1');
    require __DIR__ . '/compile_probe.php';
});
