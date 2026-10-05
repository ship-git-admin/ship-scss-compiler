#!/usr/bin/env python3
"""Watch completed SCSS uploads in bounded, overlapping Cron sessions."""

import argparse
import ctypes
import errno
import fcntl
import hashlib
import os
import select
import stat
import struct
import sys
import time

from compile_if_changed import _inside, _private_state_dir, compile_if_changed


IN_CLOSE_WRITE = 0x00000008
IN_MOVED_FROM = 0x00000040
IN_MOVED_TO = 0x00000080
IN_CREATE = 0x00000100
IN_DELETE = 0x00000200
IN_DELETE_SELF = 0x00000400
IN_MOVE_SELF = 0x00000800
IN_Q_OVERFLOW = 0x00004000
IN_ISDIR = 0x40000000
WATCH_MASK = (IN_CLOSE_WRITE | IN_MOVED_FROM | IN_MOVED_TO | IN_CREATE |
              IN_DELETE | IN_DELETE_SELF | IN_MOVE_SELF)
EVENT_HEADER = struct.Struct('iIII')


def _open_watches(scss_root):
    libc = ctypes.CDLL(None, use_errno=True)
    libc.inotify_init1.argtypes = [ctypes.c_int]
    libc.inotify_init1.restype = ctypes.c_int
    libc.inotify_add_watch.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_uint32]
    libc.inotify_add_watch.restype = ctypes.c_int
    fd = libc.inotify_init1(os.O_NONBLOCK | os.O_CLOEXEC)
    if fd < 0:
        code = ctypes.get_errno()
        raise OSError(code, os.strerror(code))

    try:
        count = 0
        for current, directories, _files in os.walk(scss_root, followlinks=False):
            directories[:] = [name for name in directories
                              if not os.path.islink(os.path.join(current, name))]
            if not _inside(os.path.realpath(current), scss_root):
                continue
            watch = libc.inotify_add_watch(fd, os.fsencode(current), WATCH_MASK)
            if watch < 0:
                code = ctypes.get_errno()
                raise OSError(code, os.strerror(code), current)
            count += 1
        if not count:
            raise ValueError('SCSS directory is no longer available')
        return fd
    except Exception:
        os.close(fd)
        raise


def _event_flags(data):
    """Return whether SCSS may have changed and whether watches need renewal."""
    changed = rebuild = False
    offset = 0
    while offset + EVENT_HEADER.size <= len(data):
        _watch, mask, _cookie, length = EVENT_HEADER.unpack_from(data, offset)
        offset += EVENT_HEADER.size
        if offset + length > len(data):
            raise ValueError('Incomplete inotify event')
        name = data[offset:offset + length].split(b'\0', 1)[0].lower()
        offset += length
        if mask & (IN_Q_OVERFLOW | IN_DELETE_SELF | IN_MOVE_SELF):
            changed = rebuild = True
        elif mask & IN_ISDIR:
            if mask & (IN_CREATE | IN_DELETE | IN_MOVED_FROM | IN_MOVED_TO):
                changed = rebuild = True
        elif name.endswith(b'.scss') and mask & (IN_CLOSE_WRITE | IN_MOVED_TO |
                                                  IN_MOVED_FROM | IN_DELETE):
            changed = True
    return changed, rebuild


def _session_lock(state_dir, identity):
    """At most two bounded watchers may overlap; compiler locking is separate."""
    for slot in range(2):
        path = os.path.join(state_dir, 'ship-scss-' + identity + '.watch-' + str(slot) + '.lock')
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        handle = os.fdopen(fd, 'w')
        try:
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise ValueError('Watcher lock is not a regular file')
            os.fchmod(fd, 0o600)
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return handle
        except BlockingIOError:
            handle.close()
        except Exception:
            handle.close()
            raise
    return None


def watch_and_compile(wp_root, scss_root, php_binary, wp_cli, watch_seconds=70,
                      stable_seconds=1.5, event_stable_seconds=0.25,
                      reconcile_seconds=2):
    wp_root = os.path.realpath(wp_root)
    scss_root = os.path.realpath(scss_root)
    if not os.path.isdir(scss_root) or not _inside(scss_root, wp_root):
        raise ValueError('SCSS directory must be inside the WordPress root')
    state_dir = _private_state_dir()
    identity = hashlib.sha256((wp_root + '\0' + scss_root).encode('utf-8')).hexdigest()[:32]
    lock_file = _session_lock(state_dir, identity)
    if lock_file is None:
        return 0

    with lock_file:
        deadline = time.monotonic() + watch_seconds

        def compile_now(delay):
            try:
                return compile_if_changed(wp_root, scss_root, php_binary, wp_cli,
                                          state_dir=state_dir, stable_seconds=delay)
            except (OSError, ValueError) as error:
                # A failed upload or compile must not stop notifications for
                # its subsequent correction. Never retry in a tight loop.
                print('Ship SCSS scan: ' + str(error), file=sys.stderr)
                return 1

        try:
            watch_fd = _open_watches(scss_root)
        except (AttributeError, OSError, ValueError) as error:
            # Content scanning still works when Linux notifications do not.
            print('Ship SCSS watcher unavailable: ' + str(error), file=sys.stderr)
            watch_fd = None

        try:
            # Install notifications BEFORE scanning so uploads during startup
            # remain queued. Cron sessions overlap the minute boundary.
            result = compile_now(stable_seconds)
            next_scan = time.monotonic() + reconcile_seconds
            while time.monotonic() < deadline:
                remaining = deadline - time.monotonic()
                until_scan = max(0, next_scan - time.monotonic())
                readable, _writable, _errors = select.select(
                    [watch_fd] if watch_fd is not None else [], [], [],
                    max(0, min(remaining, until_scan)))
                if not readable:
                    if remaining <= until_scan:
                        break
                    # Notification loss, a busy lock, deferred upload, or a
                    # missing inotify facility must not leave updates stranded.
                    result = compile_now(stable_seconds)
                    next_scan = time.monotonic() + reconcile_seconds
                    continue
                try:
                    data = os.read(watch_fd, 65536)
                except OSError as error:
                    if error.errno == errno.EAGAIN:
                        continue
                    raise
                if not data:
                    raise OSError('inotify watch closed unexpectedly')
                changed, rebuild = _event_flags(data)
                if rebuild:
                    os.close(watch_fd)
                    watch_fd = None
                    try:
                        watch_fd = _open_watches(scss_root)
                    except (AttributeError, OSError, ValueError) as error:
                        print('Ship SCSS watcher unavailable: ' + str(error), file=sys.stderr)
                if changed:
                    result = compile_now(event_stable_seconds)
                    next_scan = time.monotonic() + reconcile_seconds
                elif time.monotonic() >= next_scan:
                    # Unrelated notifications must not starve reconciliation.
                    result = compile_now(stable_seconds)
                    next_scan = time.monotonic() + reconcile_seconds
            return result
        finally:
            if watch_fd is not None:
                os.close(watch_fd)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True)
    parser.add_argument('--scss-dir', required=True)
    parser.add_argument('--php', default='/usr/bin/php8.3')
    parser.add_argument('--wp-cli', default='/usr/bin/wp')
    parser.add_argument('--watch-seconds', type=float, default=70)
    parser.add_argument('--stable-seconds', type=float, default=1.5)
    parser.add_argument('--event-stable-seconds', type=float, default=0.25)
    parser.add_argument('--reconcile-seconds', type=float, default=2)
    args = parser.parse_args(argv)
    if not 0 < args.watch_seconds <= 110:
        parser.error('--watch-seconds must be between 0 and 110')
    if not 0 <= args.stable_seconds <= 10:
        parser.error('--stable-seconds must be between 0 and 10')
    if not 0 <= args.event_stable_seconds <= 10:
        parser.error('--event-stable-seconds must be between 0 and 10')
    if not 1 <= args.reconcile_seconds <= 60:
        parser.error('--reconcile-seconds must be between 1 and 60')
    try:
        return watch_and_compile(args.wp_path, args.scss_dir, args.php, args.wp_cli,
                                 args.watch_seconds, args.stable_seconds,
                                 args.event_stable_seconds, args.reconcile_seconds)
    except (OSError, ValueError) as error:
        print('Ship SCSS watcher: ' + str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
