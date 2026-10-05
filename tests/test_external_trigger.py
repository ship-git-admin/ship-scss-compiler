#!/usr/bin/env python3
"""Regression tests for the lightweight external SCSS change trigger."""

import os
import stat
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'bin'))
import compile_if_changed


class ExternalTriggerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = self.temporary.name
        self.wp_root = os.path.join(self.root, 'wordpress')
        self.scss_root = os.path.join(self.wp_root, 'wp-content', 'themes', 'shipinc', 'scss')
        self.state_dir = os.path.join(self.root, 'state')
        self.php_binary = os.path.join(self.root, 'php')
        self.wp_cli = os.path.join(self.root, 'wp')
        os.makedirs(self.scss_root)
        os.makedirs(self.state_dir)
        with open(os.path.join(self.wp_root, 'wp-config.php'), 'w', encoding='utf-8') as output:
            output.write('<?php')
        for executable in (self.php_binary, self.wp_cli):
            with open(executable, 'w', encoding='utf-8') as output:
                output.write('#!/bin/sh\n')
            os.chmod(executable, 0o700)
        self.entrypoint = os.path.join(self.scss_root, 'main.scss')
        self._write(self.entrypoint, '.main { color: red; }')
        self.calls = []

    def tearDown(self):
        self.temporary.cleanup()

    def _write(self, path, contents):
        with open(path, 'w', encoding='utf-8') as output:
            output.write(contents)

    def _run(self, returncode=0, wait=lambda _seconds: None):
        def fake_run(command, cwd, check):
            self.calls.append((command, cwd, check))
            return subprocess.CompletedProcess(command, returncode)

        return compile_if_changed.compile_if_changed(
            self.wp_root, self.scss_root, self.php_binary, self.wp_cli,
            state_dir=self.state_dir, stable_seconds=0, run=fake_run, wait=wait,
            retry_seconds=0,
        )

    def test_first_scan_runs_once_then_skips_unchanged_inventory(self):
        self.assertEqual(self._run(), 0)
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(self._run(), 0)
        self.assertEqual(len(self.calls), 1)

    def test_default_state_directory_is_private_to_its_owner(self):
        state_dir = compile_if_changed._private_state_dir(self.state_dir)
        self.assertEqual(os.stat(state_dir).st_uid, os.getuid())
        self.assertEqual(stat.S_IMODE(os.stat(state_dir).st_mode), 0o700)

    def test_scss_change_runs_cli_but_non_scss_change_does_not(self):
        self._run()
        self._write(os.path.join(self.scss_root, 'note.txt'), 'ignore')
        self._run()
        self.assertEqual(len(self.calls), 1)
        self._write(self.entrypoint, '.main { color: blue; }')
        self._run()
        self.assertEqual(len(self.calls), 2)

    def test_failed_cli_does_not_advance_fingerprint(self):
        self.assertEqual(self._run(returncode=1), 1)
        self.assertEqual(self._run(), 0)
        self.assertEqual(len(self.calls), 2)

    def test_same_failure_is_throttled_but_new_upload_retries_immediately(self):
        now = [100]
        def fake_run(command, cwd, check):
            self.calls.append(command)
            return subprocess.CompletedProcess(command, 1)
        def scan():
            return compile_if_changed.compile_if_changed(
                self.wp_root, self.scss_root, self.php_binary, self.wp_cli,
                state_dir=self.state_dir, stable_seconds=0, run=fake_run,
                clock=lambda: now[0])
        self.assertEqual(scan(), 1)
        self.assertEqual(scan(), 0)
        self.assertEqual(len(self.calls), 1)
        self._write(self.entrypoint, '.main { color: blue; }')
        self.assertEqual(scan(), 1)
        self.assertEqual(len(self.calls), 2)
        now[0] += 61
        self.assertEqual(scan(), 1)
        self.assertEqual(len(self.calls), 3)

    def test_compiler_lock_conflict_retries_without_error_cooldown(self):
        def fake_run(command, cwd, check):
            self.calls.append(command)
            return subprocess.CompletedProcess(command, 75)
        for _ in range(2):
            self.assertEqual(compile_if_changed.compile_if_changed(
                self.wp_root, self.scss_root, self.php_binary, self.wp_cli,
                state_dir=self.state_dir, stable_seconds=0, run=fake_run), 75)
        self.assertEqual(len(self.calls), 2)

    def test_unstable_upload_is_deferred_without_running_cli(self):
        def finish_upload(_seconds):
            self._write(self.entrypoint, '.main { color: green; }')

        self.assertEqual(
            compile_if_changed.compile_if_changed(
                self.wp_root, self.scss_root, self.php_binary, self.wp_cli,
                state_dir=self.state_dir, stable_seconds=1,
                run=lambda *args, **kwargs: self.fail('CLI should not run while files are changing'),
                wait=finish_upload,
            ),
            0,
        )


if __name__ == '__main__':
    unittest.main()
