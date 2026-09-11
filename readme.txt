=== Ship SCSS Compiler ===
Contributors: ship-git-admin
Requires at least: 5.8
Requires PHP: 7.2
Version: 1.3.1

Safely compiles SCSS entrypoints in the active theme with the bundled scssphp library.

== Defaults ==

Without changing settings, the plugin preserves the existing workflow:

* active theme scss/ to css/
* direct .scss entrypoints only
* underscore-prefixed files are partials
* empty entrypoints leave existing CSS untouched
* compressed CSS without source maps
* automatic CSS cache-busting is disabled

Settings > Ship SCSS Compiler supports theme-relative input/output directories,
automatic or explicit entrypoints, recursive discovery, debug maps, sourcesContent,
owned-map cleanup, cache-busting, and retry suppression. Paths must be relative to the
active stylesheet theme. Absolute paths, URLs, .. segments, and unsafe symlinks are rejected.

== Difference compilation and state ==

The plugin stores per-context, non-autoloaded state. A context includes the active theme,
input/output configuration, entrypoint configuration, and output mode. Each successful
entrypoint records its content hash, output hash, mode, dependency list from
CompilationResult::getIncludedFiles(), and output metadata. Unrelated entrypoints are not
recompiled for ordinary partial changes. The state view shows input/output paths, status,
last success, duration, CSS version, mode, dependency count, recent error, and retry time.

The default retry suppression interval is 300 seconds. Input/dependency/config changes,
elapsed interval, and manual compilation allow a retry. A compile failure never replaces a
previous public CSS file; an initial failure is shown as having no publishable CSS.

== Manual recompilation ==

Administrators with manage_options can use “Recompile all” or select verified entrypoints
from the status table. Both actions use POST, nonce verification, server-side path
validation, a shared lock, and a redirect after processing. A lock conflict is not a success.

== CSS cache-busting ==

When enabled, style_loader_src receives the stored SHA-256 content hash as the ver value for
CSS successfully published and owned by this plugin. External/CDN/unmanaged URLs are left
unchanged and other query parameters and fragments are preserved. The plugin does not add
stylesheets or delete page/CDN caches. Theme code may request a version explicitly:

    $ver = ship_scss_compiler_css_version('css/home.css');

The helper returns an empty string for an unmanaged or not-yet-published CSS file.

== Source maps and logs ==

Normal mode generates no new source maps and no sourceMappingURL. Debug mode generates an
external map and expanded CSS. sourcesContent is independent and disabled by default. An
external map may make source structure or content available by URL, so normal mode is
recommended for production; this does not hide already-served data or direct SCSS access.

When debug is disabled, only maps recorded as owned by this plugin are removed when the
cleanup setting is enabled. Unknown maps are warned about, not bulk-deleted. New detailed
errors are stored in a non-autoloaded WordPress option, limited to the latest 100 entries,
30 days, and 4096 characters per entry. Administrators can clear them with a nonce. A legacy
scss/error_log.log is warned about and is never automatically removed.

== Hooks ==

* ship_scss_compiler_debug
* ship_scss_compiler_retry_interval
* ship_scss_compiler_scan_interval

== GitHub updates ==

The existing release-only update checker remains in place. It accepts only the asset:

    ship-scss-compiler-x.y.z.zip

== Changelog ==

= 1.3.1 =
* Updated the bundled Plugin Update Checker library to official v5.7.

= 1.3.0 =
* Added configurable input/output paths and explicit or recursive entrypoints.
* Added dependency-aware content-hash incremental compilation and retry suppression.
* Added state, manual recompilation, cache-busting, owned map cleanup, and DB logs.

= 1.2.0 =
* Added settings-based expanded CSS and external source maps.
