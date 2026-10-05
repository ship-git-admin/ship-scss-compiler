"""Upload completion notifications must trigger only safe SCSS scans."""

import os
import struct
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'bin'))
import watch_and_compile


def event(mask, name=b''):
    return struct.pack('iIII', 1, mask, 0, len(name) + 1) + name + b'\0'


class UploadWatcherTests(unittest.TestCase):
    def test_waits_for_completed_upload(self):
        pending = event(watch_and_compile.IN_CREATE, b'page.scss')
        complete = event(watch_and_compile.IN_CLOSE_WRITE, b'page.scss')
        self.assertEqual(watch_and_compile._event_flags(pending), (False, False))
        self.assertEqual(watch_and_compile._event_flags(complete), (True, False))
        self.assertEqual(watch_and_compile._event_flags(
            event(watch_and_compile.IN_MOVED_TO, b'page.scss')), (True, False))
        self.assertEqual(watch_and_compile._event_flags(
            event(watch_and_compile.IN_CLOSE_WRITE, b'page.css')), (False, False))

    def test_rename_directory_and_overflow_require_rescan(self):
        renamed = event(watch_and_compile.IN_MOVED_TO | watch_and_compile.IN_ISDIR,
                        b'pages')
        self.assertEqual(watch_and_compile._event_flags(renamed), (True, True))
        self.assertEqual(watch_and_compile._event_flags(
            event(watch_and_compile.IN_Q_OVERFLOW)), (True, True))

    def test_notification_runs_an_extra_change_scan(self):
        with tempfile.TemporaryDirectory() as root:
            wp_root = os.path.join(root, 'wordpress')
            scss_root = os.path.join(wp_root, 'scss')
            state_dir = os.path.join(root, 'state')
            os.makedirs(scss_root)
            os.makedirs(state_dir)
            read_fd, write_fd = os.pipe()
            try:
                os.write(write_fd, event(watch_and_compile.IN_CLOSE_WRITE, b'page.scss'))
                with mock.patch.object(watch_and_compile, '_private_state_dir',
                                       return_value=state_dir), mock.patch.object(
                                           watch_and_compile, '_open_watches',
                                           return_value=read_fd), mock.patch.object(
                                               watch_and_compile, 'compile_if_changed',
                                               return_value=0) as compile_mock:
                    self.assertEqual(watch_and_compile.watch_and_compile(
                        wp_root, scss_root, '/usr/bin/php', '/usr/bin/wp',
                        watch_seconds=0.01, stable_seconds=0), 0)
                    self.assertEqual(compile_mock.call_count, 2)
                    self.assertEqual(compile_mock.call_args_list[0][1]['stable_seconds'], 0)
                    self.assertEqual(compile_mock.call_args_list[1][1]['stable_seconds'], 0.25)
            finally:
                os.close(write_fd)

    def test_unavailable_notifications_keep_initial_scan(self):
        with tempfile.TemporaryDirectory() as root:
            wp_root = os.path.join(root, 'wordpress')
            scss_root = os.path.join(wp_root, 'scss')
            state_dir = os.path.join(root, 'state')
            os.makedirs(scss_root)
            os.makedirs(state_dir)
            with mock.patch.object(watch_and_compile, '_private_state_dir',
                                   return_value=state_dir), mock.patch.object(
                                       watch_and_compile, '_open_watches',
                                       side_effect=OSError('unavailable')), mock.patch.object(
                                           watch_and_compile, 'compile_if_changed',
                                           return_value=0) as compile_mock:
                self.assertEqual(watch_and_compile.watch_and_compile(
                    wp_root, scss_root, '/usr/bin/php', '/usr/bin/wp',
                    watch_seconds=0.01, stable_seconds=0), 0)
                self.assertEqual(compile_mock.call_count, 1)

    def test_failure_does_not_stop_correction_notifications(self):
        with tempfile.TemporaryDirectory() as root:
            scss_root = os.path.join(root, 'scss')
            state_dir = os.path.join(root, 'state')
            os.makedirs(scss_root)
            os.makedirs(state_dir)
            read_fd, write_fd = os.pipe()
            try:
                completed = event(watch_and_compile.IN_CLOSE_WRITE, b'page.scss')
                with mock.patch.object(watch_and_compile, '_private_state_dir', return_value=state_dir), \
                        mock.patch.object(watch_and_compile, '_open_watches', return_value=read_fd), \
                        mock.patch.object(watch_and_compile.select, 'select',
                                          side_effect=[([read_fd], [], []), ([read_fd], [], []), ([], [], [])]), \
                        mock.patch.object(watch_and_compile.os, 'read', side_effect=[completed, completed]), \
                        mock.patch.object(watch_and_compile, 'compile_if_changed',
                                          side_effect=[1, 1, 0]) as compile_mock:
                    self.assertEqual(watch_and_compile.watch_and_compile(
                        root, scss_root, '/usr/bin/php', '/usr/bin/wp', watch_seconds=1), 0)
                    self.assertEqual(compile_mock.call_count, 3)
            finally:
                os.close(write_fd)

    def test_two_sessions_overlap_but_third_is_rejected(self):
        with tempfile.TemporaryDirectory() as state_dir:
            first = watch_and_compile._session_lock(state_dir, 'test')
            second = watch_and_compile._session_lock(state_dir, 'test')
            try:
                self.assertIsNotNone(first)
                self.assertIsNotNone(second)
                self.assertIsNone(watch_and_compile._session_lock(state_dir, 'test'))
                first.close()
                replacement = watch_and_compile._session_lock(state_dir, 'test')
                self.assertIsNotNone(replacement)
                replacement.close()
            finally:
                first.close()
                second.close()

    def test_silent_notifications_and_failed_scan_are_reconciled(self):
        with tempfile.TemporaryDirectory() as root:
            scss_root = os.path.join(root, 'scss')
            state_dir = os.path.join(root, 'state')
            os.makedirs(scss_root)
            os.makedirs(state_dir)
            read_fd, write_fd = os.pipe()
            try:
                with mock.patch.object(watch_and_compile, '_private_state_dir', return_value=state_dir), \
                        mock.patch.object(watch_and_compile, '_open_watches', return_value=read_fd), \
                        mock.patch.object(watch_and_compile, 'compile_if_changed',
                                          side_effect=[0, 1, 0, 0]) as scan:
                    self.assertEqual(watch_and_compile.watch_and_compile(
                        root, scss_root, '/usr/bin/php', '/usr/bin/wp',
                        watch_seconds=0.075, reconcile_seconds=0.02, stable_seconds=0), 0)
                    self.assertGreaterEqual(scan.call_count, 3)
            finally:
                os.close(write_fd)

    def test_unavailable_notifications_continue_periodic_scans(self):
        with tempfile.TemporaryDirectory() as root:
            scss_root = os.path.join(root, 'scss')
            state_dir = os.path.join(root, 'state')
            os.makedirs(scss_root)
            os.makedirs(state_dir)
            with mock.patch.object(watch_and_compile, '_private_state_dir', return_value=state_dir), \
                    mock.patch.object(watch_and_compile, '_open_watches', side_effect=OSError('unavailable')), \
                    mock.patch.object(watch_and_compile, 'compile_if_changed', return_value=0) as scan:
                watch_and_compile.watch_and_compile(
                    root, scss_root, '/usr/bin/php', '/usr/bin/wp',
                    watch_seconds=0.065, reconcile_seconds=0.02, stable_seconds=0)
                self.assertGreaterEqual(scan.call_count, 3)

    def test_notifications_are_installed_before_initial_scan(self):
        with tempfile.TemporaryDirectory() as root:
            scss_root = os.path.join(root, 'scss')
            state_dir = os.path.join(root, 'state')
            os.makedirs(scss_root)
            os.makedirs(state_dir)
            read_fd, write_fd = os.pipe()
            calls = []
            try:
                def open_watches(_root):
                    calls.append('watch')
                    return read_fd
                def scan(*args, **kwargs):
                    calls.append('scan')
                    return 0
                with mock.patch.object(watch_and_compile, '_private_state_dir', return_value=state_dir), \
                        mock.patch.object(watch_and_compile, '_open_watches', side_effect=open_watches), \
                        mock.patch.object(watch_and_compile, 'compile_if_changed', side_effect=scan):
                    watch_and_compile.watch_and_compile(root, scss_root, '/usr/bin/php',
                                                       '/usr/bin/wp', watch_seconds=0.01)
                self.assertEqual(calls, ['watch', 'scan'])
            finally:
                os.close(write_fd)


if __name__ == '__main__':
    unittest.main()
