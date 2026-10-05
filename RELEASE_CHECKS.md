# Release verification

## 1.3.7 — 2026-10-05 (deployed to demo only; no public release)

- Reproduced a missed compilation on the deployed 1.3.6 trigger: hold its hash-scan lock during a real SFTP overwrite, then release the lock. The watcher acknowledged/skipped the event with status 0; source contained the new color while CSS retained the old color for the rest of the session.
- Candidate adds two-second reconciliation independent of inotify delivery and temporary lock skips. Unchanged inventories still do not boot WordPress.
- Remote isolated WordPress/WP-CLI integration, real SFTP inputs: skipped event under a lock recovered in 4.057 s using default delays; intentionally ignored notifications recovered in 2.324 s. These are samples, not latency guarantees or proof of the user's exact upload conditions.
- Real SFTP temp-file/rename correction after a syntax error compiled in 0.802 s; prior CSS survived the error and the unchanged failure did not repeatedly launch WordPress during the observation period.
- Candidate PHP command returned exit code 75 on an isolated real compiler lock. Python does not acknowledge that input or apply the syntax-error cooldown to a temporary lock.
- Identical failed content has a 60-second trigger cooldown; new content retries immediately and a successful run clears the error cooldown.
- Remote PHP core/command tests and 16 Python tests passed. Current site's SCSS hash scan measured 6.413 ms average over 30 scans, for 659,934 bytes of SCSS. Not a load test.
- Test sources, CSS and compiler state were isolated outside the public theme, with filters preventing writes to production compiler options. No new backups were created; unrelated theme files and sync-conflict files were untouched.
- Targeted deployment completed after the scoped GO: four runtime files plus README/readme were replaced atomically, using local commit `24bb0b2`. Plugin remains active. No GitHub release, tag, push, or new backup was created.

### Post-deployment checks

- Actual WordPress boot: `SHIP_SCSS_COMPILER_VERSION=1.3.7`, plugin active, global instance `Ship_SCSS_Compiler`; PHP 8.3.33 via `/usr/bin/php8.3 /usr/bin/wp`.
- Runtime and README/readme hashes below match the local candidate; Linux Cron checksum and compiler settings checksum match their pre-deployment values.
- Compiler settings SHA-256: `501d9126ba0a81c7a1539113e9c83bea946dd0391d789344fac68b8753085f0d`.
- Linux crontab SHA-256: `1e5e27492cd6e9b65b321d9768a8c52860c0df706bb5fe0179ad2df68d58e4e0`.
- External-trigger-only remains enabled; front-end requests still do not compile.
- Installed-code integration tests booted this target site's normal WordPress/plugin stack. SCSS/CSS/state remained isolated via the fixture, rather than changing the public theme or production settings.
- Real SFTP lock-skip recovery: 4.073 s. Notifications deliberately ignored: 2.426 s. Syntax failure retained old CSS without repeated CLI starts; corrected temp-file/rename upload compiled in 0.868 s. Successful output contained the final uploaded color; CLI exited 0.
- Actual installed PHP compiler-lock check exited 75. No test context appeared in the production compiler-state option.
- Remote PHP regression/CLI-registration tests and all 16 Python tests passed again using byte-identical deployed runtime files.
- Deployment epoch: `1791189861`. Later Cron watcher PID `1369774` was observed at epoch `1791190007` with age 12 s, so it started after replacement and loaded the updated watcher. No Cron changes or forced process restart were required.
- Server staging/test files and this probe's private locks/state were removed after verification. Their reusable test sources remain in the local repository. Public theme, Snippets, other plugins and settings were not changed by this deployment.

| Deployed file | SHA-256 |
| --- | --- |
| `ship-scss-compiler.php` | `0ca0cef971de08310aba62aece816c45517f2d1cc487bddfae6bf96c33493ea5` |
| `includes/class-ship-scss-compiler.php` | `20bcbd2310e172f3a9ce08a5db0245f165b70285ab3e609209734ba0bb0682bb` |
| `bin/watch_and_compile.py` | `1ca8c4d4d5f4a8c2d3f5e933e904089f2d6c641508c9ab22b857575ffd7dc12b` |
| `bin/compile_if_changed.py` | `fef480d8349b539b25d606b84cb51568f7092f1ead46e3e8122293f50ef048c4` |
| `README.md` | `357cb76d9e093801349fbefef2e3c059cb814d61b88275fa25ff5095a8bf5774` |
| `readme.txt` | `40da171502a5127bf64cc117505318a0de3fc51e3da0dd86ed797984d1e77c2a` |

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
