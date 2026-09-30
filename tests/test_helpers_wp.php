<?php
/** Run on a test WordPress host: wp eval-file tests/test_helpers_wp.php */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Requires WordPress through WP-CLI.'); }
if (!isset($GLOBALS['ship_scss_compiler']) || !($GLOBALS['ship_scss_compiler'] instanceof Ship_SCSS_Compiler)) {
    throw new RuntimeException('Compiler helpers cannot see the instance loaded by WP-CLI.');
}
$paths = ship_scss_compiler_paths();
$entries = ship_scss_compiler_entrypoints($paths['scss']);
$source = isset($entries['line.scss']) ? $entries['line.scss'] : reset($entries);
if (!$source) { throw new RuntimeException('Requires an existing nonempty entrypoint.'); }
$dot_source = dirname($source) . '/./' . basename($source);
$output = ship_scss_compiler_output_path($source, $paths['css']);
if (!ship_scss_compiler_compile_one($dot_source, $output, dirname($dot_source))) {
    throw new RuntimeException('Canonical path spelling was not accepted.');
}
if (!ship_scss_compiler_compile_one($source, $output, dirname($source))) {
    throw new RuntimeException('Second explicit save was skipped.');
}
if (ship_scss_compiler_compile_one(ABSPATH . 'wp-config.php', $output, dirname($source))) {
    throw new RuntimeException('Source outside the input root was accepted.');
}
echo "WP-CLI instance, canonical paths, repeated saves and root boundaries passed.\n";
