<?php
/**
 * Verify the custom command is registered when WordPress runs under WP-CLI.
 * Run with: php tests/test_wp_cli_registration.php
 */

define('ABSPATH', __DIR__ . '/');
define('WP_CLI', true);

class WP_CLI {
    public static $commands = array();
    public static function add_command($name, $callback) { self::$commands[$name] = $callback; }
}

function add_action($a, $b, $c = 10, $d = 1) {}
function add_filter($a, $b, $c = 10, $d = 1) {}

require_once dirname(__DIR__) . '/includes/class-ship-scss-compiler.php';
new Ship_SCSS_Compiler();

if (!isset(WP_CLI::$commands['ship-scss compile-changed']) || !is_callable(WP_CLI::$commands['ship-scss compile-changed'])) {
    fwrite(STDERR, "FAIL: changed-only WP-CLI command was not registered.\n");
    exit(1);
}

echo "ok: changed-only WP-CLI command is registered\n";
