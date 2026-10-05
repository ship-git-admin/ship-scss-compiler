"""Remote-only integration runner using real SFTP inputs and real WP-CLI compilation."""
import argparse
import fcntl
import hashlib
import os
import subprocess
import sys

parser = argparse.ArgumentParser()
parser.add_argument('--plugin-dir', required=True)
parser.add_argument('--probe-root', required=True)
parser.add_argument('--wp-path', required=True)
parser.add_argument('--mode', choices=['normal', 'lock', 'silent'], default='normal')
parser.add_argument('--seconds', type=float, default=10)
args = parser.parse_args()
sys.path.insert(0, os.path.join(args.plugin_dir, 'bin'))
import watch_and_compile as watcher
import compile_if_changed as trigger

root = os.path.realpath(args.probe_root)
scss = os.path.join(root, 'scss')
identity = hashlib.sha256((root + '\0' + scss).encode('utf-8')).hexdigest()[:32]
state_dir = trigger._private_state_dir()
lock_path = os.path.join(state_dir, 'ship-scss-' + identity + '.state.lock')
held = None
calls = 0

def run_cli(command, cwd, check):
    env = dict(os.environ, SHIP_SCSS_PROBE_ROOT=root,
               SHIP_SCSS_CANDIDATE_DIR=args.plugin_dir)
    result = subprocess.run(['/usr/bin/php8.3', '-d', 'error_reporting=0', '/usr/bin/wp',
                             '--path=' + args.wp_path, '--skip-plugins=ship-scss-compiler', 'eval-file',
                             os.path.join(os.path.dirname(__file__), 'compile_probe.php')],
                            env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    print('WP_CLI_EXIT=' + str(result.returncode), flush=True)
    css = os.path.join(root, 'css', 'probe.css')
    if result.returncode == 0 and os.path.isfile(css):
        latency = os.stat(css).st_mtime - os.stat(os.path.join(scss, 'probe.scss')).st_mtime
        print('SOURCE_TO_CSS_SECONDS=' + str(round(latency, 3)), flush=True)
    if result.returncode:
        print(ascii(result.stdout.decode('utf-8', errors='replace')[-300:]), flush=True)
        print(ascii(result.stderr.decode('utf-8', errors='replace')[-300:]), flush=True)
    return result

def scan(*positional, **keywords):
    global held, calls
    calls += 1
    result = trigger.compile_if_changed(*positional, run=run_cli, **keywords)
    if args.mode == 'lock' and calls == 1:
        held = open(lock_path, 'a')
        fcntl.flock(held.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        print('READY_LOCK_HELD', flush=True)
    elif held is not None:
        held.close()
        held = None
        print('EVENT_SKIPPED_LOCK_RELEASED', flush=True)
    else:
        print('SCAN=' + str(calls), flush=True)
    return result

watcher.compile_if_changed = scan
if args.mode == 'silent':
    watcher._event_flags = lambda data: (False, False)
try:
    result = watcher.watch_and_compile(root, scss, '/usr/bin/php8.3', '/usr/bin/wp',
                                       watch_seconds=args.seconds, stable_seconds=1.5)
finally:
    if held is not None:
        held.close()
print('WATCH_EXIT=' + str(result), flush=True)
