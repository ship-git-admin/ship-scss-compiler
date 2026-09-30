# Release verification

## 1.3.6 — 2026-09-30

- Release: `v1.3.6`, commit `9c5884e49bd4dc1d4799c357543148735a4d4cdd`.
- GitHub release-asset workflow: successful; ZIP downloaded and its SHA-256 matched before deployment.
- Asset: `ship-scss-compiler-1.3.6.zip`.
- SHA-256: `0a80d83e0924c8b90db77e0d4c0b45da506970a1d57b5b245b3f29069045e6fb`.
- Remote Linux/PHP 8.3: core regression suite, WP-CLI command registration, 12 Python tests and PHP syntax checks passed.
- Actual WordPress/WP-CLI: global instance, canonical source spelling, two targeted compiles in one request, and input-root boundary rejection passed.
- Active plugin confirmed as 1.3.6 on the demo site; external-trigger-only setting retained.
- SFTP completion to CSS generation: `line.scss` 0.880 s (previous release: 2.149 s); `main.scss` 1.173 s. These are individual samples, not latency guarantees.
- Existing custom-page save callback: 0.029 s for the callback itself, excluding request/bootstrap time.
- Authenticated browser: new CSS version URLs returned HTTP 200 and the uploaded test content.
- After verification: original SCSS/CSS content and corresponding cache versions restored and hash-checked; snippets, post data, settings and Cron unchanged. Private server test artifacts removed; backups retained only locally.
- Idle watcher observed around 15 MB RSS per process; overlap is capped at two watchers. This is not a load-test result or a guarantee of zero shared-server impact.
