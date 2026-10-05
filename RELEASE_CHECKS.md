# Release verification

## 1.3.7 candidate — 2026-10-05 (not released or deployed)

- Reproduced a missed compilation on the deployed 1.3.6 trigger: hold its hash-scan lock during a real SFTP overwrite, then release the lock. The watcher acknowledged/skipped the event with status 0; source contained the new color while CSS retained the old color for the rest of the session.
- Candidate adds two-second reconciliation independent of inotify delivery and temporary lock skips. Unchanged inventories still do not boot WordPress.
- Remote isolated WordPress/WP-CLI integration, real SFTP inputs: skipped event under a lock recovered in 4.057 s using default delays; intentionally ignored notifications recovered in 2.324 s. These are samples, not latency guarantees or proof of the user's exact upload conditions.
- Real SFTP temp-file/rename correction after a syntax error compiled in 0.802 s; prior CSS survived the error and the unchanged failure did not repeatedly launch WordPress during the observation period.
- Candidate PHP command returned exit code 75 on an isolated real compiler lock. Python does not acknowledge that input or apply the syntax-error cooldown to a temporary lock.
- Identical failed content has a 60-second trigger cooldown; new content retries immediately and a successful run clears the error cooldown.
- Remote PHP core/command tests and 16 Python tests passed. Current site's SCSS hash scan measured 6.413 ms average over 30 scans, for 659,934 bytes of SCSS. Not a load test.
- Test sources, CSS and compiler state were isolated outside the public theme, with filters preventing writes to production compiler options. No new backups were created; unrelated theme files and sync-conflict files were untouched.
- Release and production replacement require explicit GO; current production remains 1.3.6.

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
