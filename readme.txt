=== Ship SCSS Compiler ===
Contributors: ship-git-admin
Requires at least: 5.8
Requires PHP: 7.2
Version: 1.2.0

Compiles the active theme's top-level SCSS entrypoints with the bundled scssphp library.

The existing directory convention is preserved:

* wp-content/themes/shipinc/scss/*.scss
* wp-content/themes/shipinc/css/*.css

Files beginning with an underscore are treated as imported partials. Output names use the
same basename as the SCSS entrypoint. Compilation runs automatically only when an SCSS
file or imported partial is newer than an output CSS file.

Each CSS file is generated in a same-directory temporary file and atomically replaced only
after non-empty output validation. Compilation errors, empty output, memory errors, and
write failures leave the previous CSS file untouched and are appended to scss/error_log.log.

== CSS debugging ==

Administrators can enable CSS debugging from Settings > Ship SCSS Compiler. The next
request generates expanded CSS and an external source map (.css.map), allowing browser
developer tools to trace CSS back to the original SCSS file and line. Normal operation
remains compressed CSS without source maps, and disabling debugging does not delete
existing source map files.

== GitHub updates ==

This plugin is maintained in the public repository below:

https://github.com/ship-git-admin/ship-scss-compiler

WordPress update checks use the bundled Plugin Update Checker and inspect the `main`
branch's latest GitHub Release. A release is offered only when the following asset is
attached:

ship-scss-compiler-x.y.z.zip

The release asset is built by GitHub Actions. The plugin does not require a GitHub token.
