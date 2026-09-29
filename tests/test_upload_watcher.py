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
                    self.assertEqual(compile_mock.call_count, 3)
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


if __name__ == '__main__':
    unittest.main()
