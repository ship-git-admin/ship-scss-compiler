# Release verification

## 1.3.8 — 2026-10-19

- User authorized plugin-only implementation and GitHub release, and explicitly accepted monitoring only while an admin page is open. No server Cron, theme or Snippets changes are part of this release; the target site's installed plugin is not replaced by these tests.
- Adds admin-only JavaScript, nonce/capability-gated lease issuance, and a lightweight POST probe. Leases are bound to the issuing login cookie, expire in three minutes, renew while the page is open, and are stored in owner-private temporary storage outside the web root. No paths or commands come from HTTP input.
- Idle probes do not load WordPress or connect to its database. Changed/stable input uses the existing compiler lock and changed-only run; session/capability/active-plugin/context are revalidated after boot. Compiler failure preserves old CSS and identical failures are throttled. Multiple admin sessions share two-second scanning/acknowledgment.
- Includes the previously local `last_scan` repair (`55e5e0c`): successful unchanged scans advance the timestamp; cached, failed and lock-skipped runs do not. Empty successful inventories are cached; failed scans preserve state and release locks.
- Remote Heteml PHP 8.3.35 / Python 3.7: core suite (including 26 deterministic scan tests), WP-CLI registration, admin-watch tests and existing 16 Python tests passed. Local Node syntax/polling tests passed. Remote loopback HTTP tests rejected invalid sessions/methods/body sizes/cross-site requests and confirmed idle no-bootstrap and active-plugin/context revalidation.
- Real SFTP uploads to isolated SCSS/CSS/state used this site's normal WordPress/WP-CLI stack and the candidate compiler, without changing the public theme or production compiler options. Initial compile: 3 s; partial color overwrite: 4 s; invalid partial: CLI exit 1 with previous blue CSS preserved; corrected temp-file/rename upload: 3 s and red CSS. Four compiler starts total (initial, blue, invalid, corrected); unchanged probes did not start the compiler. These are individual samples, not latency guarantees.
- Thirty content-fingerprint scans of the actual site's SCSS averaged 4.236 ms. This is a read-only scan benchmark, not an end-to-end latency or concurrent load test.
- Browser scheduling, background-tab throttling and sleeping devices can delay monitoring. Closing admin pages stops this mode; server Cron remains necessary for unattended watching. Unsupported host/security restrictions show a visible error. Actual logged-in browser behavior after installing the new release remains to be verified on the target site.
- Published stable public release `v1.3.8` from `a26e173a0c17b8122df4c2288fe701b03491f8fb`. Build workflow `39212711143` succeeded. Asset: `ship-scss-compiler-1.3.8.zip`, 602,660 bytes; downloaded SHA-256 `fde516471bbe49e7166d369d4d3dc0a4774f4606bc5d9d25c846a2220210c568` matches GitHub's asset digest.
- ZIP integrity passed; extracted contents match the tagged Git archive and contain no sync-conflict files. Unauthenticated latest-release API returns stable `v1.3.8`.
- Final candidate: 26 admin-watch unit checks, core suite, command registration, 16 Python tests and changed-PHP lint passed remotely; four Node polling checks passed locally. Nine private HTTP checks passed. Temporary loopback server was stopped, and this run's private remote fixtures/leases/compiler lock were removed.
- Target site remains on 1.3.7 with its previous class/settings hashes and no test context in production compiler state. Target `_colors.scss`, header/footer CSS hashes remained the same; `main.css` changed during verification and the cause was not determined, so no full-theme byte-for-byte unchanged claim is made. Explicit fixture writes and uploads targeted only private test files, not public theme files.

## 1.3.7 — 2026-10-05 (public release and demo deployment verified)

- Reproduced a missed compilation on the deployed 1.3.6 trigger: hold its hash-scan lock during a real SFTP overwrite, then release the lock. The watcher acknowledged/skipped the event with status 0; source contained the new color while CSS retained the old color for the rest of the session.
- Candidate adds two-second reconciliation independent of inotify delivery and temporary lock skips. Unchanged inventories still do not boot WordPress.
- Remote isolated WordPress/WP-CLI integration, real SFTP inputs: skipped event under a lock recovered in 4.057 s using default delays; intentionally ignored notifications recovered in 2.324 s. These are samples, not latency guarantees or proof of the user's exact upload conditions.
- Real SFTP temp-file/rename correction after a syntax error compiled in 0.802 s; prior CSS survived the error and the unchanged failure did not repeatedly launch WordPress during the observation period.
- Candidate PHP command returned exit code 75 on an isolated real compiler lock. Python does not acknowledge that input or apply the syntax-error cooldown to a temporary lock.
- Identical failed content has a 60-second trigger cooldown; new content retries immediately and a successful run clears the error cooldown.
- Remote PHP core/command tests and 16 Python tests passed. Current site's SCSS hash scan measured 6.413 ms average over 30 scans, for 659,934 bytes of SCSS. Not a load test.
- Test sources, CSS and compiler state were isolated outside the public theme, with filters preventing writes to production compiler options. No new backups were created; unrelated theme files and sync-conflict files were untouched.
- Targeted deployment completed after the scoped GO: four runtime files plus README/readme were replaced atomically, using local commit `24bb0b2`. Plugin remains active. At that deployment stage, no GitHub release, tag, push, or new backup was created.
- After deployment, the user reported smoother near-real-time SCSS compilation and explicitly requested the public 1.3.7 release. This release does not change theme files, Snippets, Cron or production settings.

### Post-deployment checks

- Actual WordPress boot: `SHIP_SCSS_COMPILER_VERSION=1.3.7`, plugin active, global instance `Ship_SCSS_Compiler`; PHP 8.3.33 via `/usr/bin/php8.3 /usr/bin/wp`.
- Runtime and README/readme hashes below match the local candidate; Linux Cron checksum and compiler settings checksum match their pre-deployment values.
- Compiler settings SHA-256: `501d9126ba0a81c7a1539113e9c83bea946dd0391d789344fac68b8753085f0d`.
- Linux crontab SHA-256: `1e5e27492cd6e9b65b321d9768a8c52860c0df706bb5fe0179ad2df68d58e4e0`.
- External-trigger-only remains enabled; front-end requests still do not compile.
- Installed-code integration tests booted this target site's normal WordPress/plugin stack. SCSS/CSS/state remained isolated via the fixture, rather than changing the public theme or production settings.
- Real SFTP lock-skip recovery: 4.073 s. Notifications deliberately ignored: 2.426 s. Syntax failure retained old CSS without repeated CLI starts; corrected temp-file/rename upload compiled in 0.868 s. Successful output contained the final uploaded color; CLI exited 0.
- Actual installed PHP compiler-lock check exited 75. No test context appeared in the production compiler-state option.
- The native installed `wp ship-scss compile-changed` command was also exercised, with `after_wp_load` fixture filters isolating its input/output/state. A real SFTP overwrite while the scan lock was held recovered in 4.094 s, produced the uploaded color and exited 0; the watcher exited 0.
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

### Public release checks

- User explicitly requested the release after reporting smoother near-real-time compilation on the demo.
- Published `v1.3.7` from commit `2421ac5848db2922474e9c093112d5a1a3c7a3eb` using the company account. Release is public, non-draft and non-prerelease; unauthenticated GitHub latest-release API returns `v1.3.7`.
- Build workflow run `37300581694` completed successfully. Asset: `ship-scss-compiler-1.3.7.zip`, 582,619 bytes.
- Downloaded ZIP SHA-256: `4aae4e71895419c9bd4a573550b739b6c68cbc69d4e02a645faf11e3970533b1`; matches GitHub's asset digest. ZIP integrity passed; unpacked files exactly match the tagged Git archive, with no sync-conflict files.
- PHP core and CLI-registration tests, 16 Python tests and runtime PHP syntax checks passed again on the remote Linux/PHP 8.3 verification host before publication.
- Demo plugin remains active at 1.3.7; the four deployed runtime hashes match the release. Settings and Linux crontab checksums remain unchanged. No theme, Snippets or other plugin changes were made for this release.
- Private remote release-test files were removed. Release verification records were committed separately after publication; the released tag/asset were not changed.

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
