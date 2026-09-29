#!/usr/bin/env python3
"""Run the Ship SCSS WP-CLI compiler only when SCSS content has changed."""

import argparse
import errno
import fcntl
import hashlib
import os
import stat
import subprocess
import sys
import tempfile
import time


def _inside(path, root):
    try:
        return os.path.commonpath([path, root]) == root
    except ValueError:
        return False


def scss_fingerprint(scss_root):
    digest = hashlib.sha256()
    for current_root, directories, filenames in os.walk(scss_root, followlinks=False):
        directories[:] = sorted(
            name for name in directories
            if not os.path.islink(os.path.join(current_root, name))
        )
        for filename in sorted(filenames):
            if not filename.lower().endswith('.scss'):
                continue
            path = os.path.join(current_root, filename)
            if os.path.islink(path):
                continue
            real_path = os.path.realpath(path)
            if not _inside(real_path, scss_root) or not os.path.isfile(real_path):
                continue
            relative_path = os.path.relpath(real_path, scss_root).replace(os.sep, '/')
            digest.update(relative_path.encode('utf-8'))
            digest.update(b'\0')
            with open(real_path, 'rb') as source:
                for chunk in iter(lambda: source.read(65536), b''):
                    digest.update(chunk)
            digest.update(b'\n')
    return digest.hexdigest()


def _atomic_write(path, value):
    directory = os.path.dirname(path)
    descriptor, temporary_path = tempfile.mkstemp(prefix='.ship-scss-', dir=directory)
    try:
        os.fchmod(descriptor, 0o600)
        with os.fdopen(descriptor, 'w', encoding='ascii') as output:
            output.write(value + '\n')
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary_path, path)
    finally:
        if os.path.exists(temporary_path):
            os.unlink(temporary_path)


def _private_state_dir(base_dir=None):
    base_dir = base_dir or tempfile.gettempdir()
    owner_id = os.getuid()
    state_dir = os.path.join(base_dir, 'ship-scss-trigger-' + str(owner_id))
    try:
        os.mkdir(state_dir, 0o700)
    except OSError as error:
        if error.errno != errno.EEXIST:
            raise

    state = os.lstat(state_dir)
    if not stat.S_ISDIR(state.st_mode) or state.st_uid != owner_id:
        raise ValueError('private trigger state directory is not owned by this user')
    os.chmod(state_dir, 0o700)
    return state_dir


def compile_if_changed(wp_root, scss_root, php_binary, wp_cli, state_dir=None,
                       stable_seconds=1.5, run=subprocess.run, wait=time.sleep):
    wp_root = os.path.realpath(wp_root)
    scss_root = os.path.realpath(scss_root)
    php_binary = os.path.realpath(php_binary)
    wp_cli = os.path.realpath(wp_cli)

    if not os.path.isfile(os.path.join(wp_root, 'wp-config.php')):
        raise ValueError('WordPress root does not contain wp-config.php')
    if not os.path.isdir(scss_root) or not _inside(scss_root, wp_root):
        raise ValueError('SCSS directory must be inside the WordPress root')
    if not os.path.isfile(php_binary) or not os.access(php_binary, os.X_OK):
        raise ValueError('PHP executable was not found or is not executable')
    if not os.path.isfile(wp_cli) or not os.access(wp_cli, os.X_OK):
        raise ValueError('WP-CLI executable was not found or is not executable')

    state_dir = state_dir or _private_state_dir()
    identity = hashlib.sha256((wp_root + '\0' + scss_root).encode('utf-8')).hexdigest()[:32]
    state_path = os.path.join(state_dir, 'ship-scss-' + identity + '.state')
    lock_path = state_path + '.lock'

    with open(lock_path, 'a') as lock_file:
        os.chmod(lock_path, 0o600)
        try:
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0

        current = scss_fingerprint(scss_root)
        try:
            with open(state_path, 'r', encoding='ascii') as state_file:
                previous = state_file.read().strip()
        except FileNotFoundError:
            previous = ''

        if current == previous:
            return 0

        if stable_seconds > 0:
            wait(stable_seconds)
            if scss_fingerprint(scss_root) != current:
                return 0

        result = run(
            [php_binary, wp_cli, '--path=' + wp_root, 'ship-scss', 'compile-changed'],
            cwd=wp_root,
            check=False,
        )
        if result.returncode != 0:
            return result.returncode

        _atomic_write(state_path, current)
        return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True, help='Absolute WordPress root directory')
    parser.add_argument('--scss-dir', required=True, help='Absolute theme SCSS input directory')
    parser.add_argument('--php', default='/usr/bin/php8.3', help='PHP executable used for WP-CLI')
    parser.add_argument('--wp-cli', default='/usr/bin/wp', help='WP-CLI executable')
    parser.add_argument('--stable-seconds', type=float, default=1.5,
                        help='Wait before rechecking a changed inventory (default: 1.5)')
    args = parser.parse_args(argv)

    if args.stable_seconds < 0 or args.stable_seconds > 10:
        parser.error('--stable-seconds must be between 0 and 10')
    try:
        return compile_if_changed(
            args.wp_path, args.scss_dir, args.php, args.wp_cli,
            stable_seconds=args.stable_seconds,
        )
    except (OSError, ValueError) as error:
        print('Ship SCSS trigger: ' + str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
