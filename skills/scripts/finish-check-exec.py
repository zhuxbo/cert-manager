#!/usr/bin/env python3
"""finish-check 并行执行器：源码冻结、资源锁、证据账本与失效校验。"""

from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import fcntl
import hashlib
import json
import os
import re
import signal
import stat
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import Iterator

STALE_EXIT = 86
STATE_FILE = "state.json"
LEDGER_FILE = "ledger.jsonl"
DEFAULT_MANIFEST = "skills/finish-check-gates.json"
SENSITIVE_NAME = re.compile(
    r"(?:token|password|passwd|secret|credential|private[-_]?key|api[-_]?key|"
    r"authorization|bearer|cookie|session)",
    re.IGNORECASE,
)
SENSITIVE_OPTION_NAME = re.compile(
    r"^--?(?:token|password|passwd|secret|credential|private[-_]?key|"
    r"api[-_]?key|authorization|bearer|cookie|session)$",
    re.IGNORECASE,
)
CONTROLLED_ENV_PREFIXES = ("MUTATE_", "MUTATION_")
SENSITIVE_VALUE_OPTIONS = {
    "-H",
    "--header",
    "--proxy-header",
    "-b",
    "--cookie",
    "-u",
    "--user",
    "--oauth2-bearer",
}


def git_output(repo: Path, *args: str) -> bytes:
    result = subprocess.run(
        ["git", *args],
        cwd=repo,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return result.stdout


def repo_root() -> Path:
    result = subprocess.run(
        ["git", "rev-parse", "--show-toplevel"],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    return Path(result.stdout.strip()).resolve()


def update_digest(digest: hashlib._Hash, label: bytes, payload: bytes) -> None:
    digest.update(label)
    digest.update(b"\0")
    digest.update(str(len(payload)).encode())
    digest.update(b"\0")
    digest.update(payload)
    digest.update(b"\0")


def source_fingerprint(repo: Path) -> str:
    """覆盖 HEAD、暂存区、工作树、文件模式及未跟踪非忽略文件。"""
    digest = hashlib.sha256()
    update_digest(digest, b"head", git_output(repo, "rev-parse", "HEAD").strip())
    update_digest(
        digest,
        b"index",
        git_output(
            repo,
            "diff",
            "--binary",
            "--full-index",
            "--no-ext-diff",
            "--no-textconv",
            "--cached",
            "HEAD",
        ),
    )
    update_digest(
        digest,
        b"worktree",
        git_output(
            repo,
            "diff",
            "--binary",
            "--full-index",
            "--no-ext-diff",
            "--no-textconv",
            "HEAD",
        ),
    )

    untracked = git_output(
        repo, "ls-files", "--others", "--exclude-standard", "-z"
    ).split(b"\0")
    for raw_path in sorted(path for path in untracked if path):
        rel = raw_path.decode("utf-8", "surrogateescape")
        path = repo / rel
        try:
            info = path.lstat()
        except FileNotFoundError:
            update_digest(digest, b"untracked-missing", raw_path)
            continue

        update_digest(digest, b"untracked-path", raw_path)
        update_digest(digest, b"untracked-mode", oct(stat.S_IMODE(info.st_mode)).encode())
        if path.is_symlink():
            update_digest(
                digest,
                b"untracked-symlink",
                os.readlink(path).encode("utf-8", "surrogateescape"),
            )
        elif path.is_file():
            digest.update(b"untracked-content\0")
            with path.open("rb") as handle:
                while chunk := handle.read(1024 * 1024):
                    digest.update(chunk)
            digest.update(b"\0")
        else:
            update_digest(digest, b"untracked-special", str(info.st_mode).encode())

    return digest.hexdigest()


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat()


def ensure_private_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)
    path.chmod(0o700)


def atomic_json_write(path: Path, payload: dict[str, object]) -> None:
    ensure_private_dir(path.parent)
    fd, tmp_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write("\n")
        os.replace(tmp_name, path)
        path.chmod(0o600)
    finally:
        if os.path.exists(tmp_name):
            os.unlink(tmp_name)


def load_state(run_dir: Path) -> dict[str, object]:
    path = run_dir / STATE_FILE
    if not path.is_file():
        raise SystemExit(f"缺少冻结状态：{path}；请先执行 freeze")
    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def resolve_manifest(repo: Path, value: str) -> Path:
    path = Path(value)
    if not path.is_absolute():
        path = repo / path
    return path.resolve()


def load_manifest(path: Path) -> tuple[dict[str, dict[str, object]], str]:
    if not path.is_file():
        raise SystemExit(f"缺少 gate manifest：{path}")
    raw = path.read_bytes()
    try:
        payload = json.loads(raw)
        gates = payload["gates"]
    except (json.JSONDecodeError, KeyError, TypeError) as exc:
        raise SystemExit(f"无效 gate manifest：{path}") from exc
    if not isinstance(gates, dict):
        raise SystemExit(f"gate manifest 的 gates 必须是对象：{path}")
    return gates, hashlib.sha256(raw).hexdigest()


def command_hash(command: list[str]) -> str:
    digest = hashlib.sha256()
    for item in command:
        update_digest(
            digest,
            b"argv",
            item.encode("utf-8", "surrogateescape"),
        )
    return digest.hexdigest()


def environment_hash(environment: dict[str, str]) -> str:
    digest = hashlib.sha256()
    for name in sorted(environment):
        update_digest(digest, b"env-name", name.encode())
        update_digest(
            digest,
            b"env-value",
            environment[name].encode("utf-8", "surrogateescape"),
        )
    return digest.hexdigest()


def redact_command(command: list[str]) -> list[str]:
    redacted: list[str] = []
    redact_next = False
    shell_payload_next = False
    shell_command = bool(command) and Path(command[0]).name in {
        "bash",
        "dash",
        "ksh",
        "sh",
        "zsh",
    }
    for index, item in enumerate(command):
        if shell_payload_next:
            redacted.append("[REDACTED SHELL PAYLOAD]")
            shell_payload_next = False
            continue
        if redact_next:
            redacted.append("[REDACTED]")
            redact_next = False
            continue
        if (
            shell_command
            and index > 0
            and item.startswith("-")
            and "c" in item[1:]
        ):
            redacted.append(item)
            shell_payload_next = True
            continue
        if item in SENSITIVE_VALUE_OPTIONS:
            redacted.append(item)
            redact_next = True
            continue
        attached_option = next(
            (
                option
                for option in ("-H", "-b", "-u")
                if item.startswith(option) and item != option
            ),
            None,
        )
        if attached_option is not None:
            redacted.append(f"{attached_option}[REDACTED]")
            continue
        attached_long_option = next(
            (
                option
                for option in SENSITIVE_VALUE_OPTIONS
                if option.startswith("--") and item.startswith(f"{option}=")
            ),
            None,
        )
        if attached_long_option is not None:
            redacted.append(f"{attached_long_option}=[REDACTED]")
            continue
        if item.startswith("-") and "=" in item:
            name, _value = item.split("=", 1)
            if SENSITIVE_NAME.search(name):
                redacted.append(f"{name}=[REDACTED]")
                continue
        if SENSITIVE_OPTION_NAME.fullmatch(item):
            redacted.append(item)
            redact_next = True
            continue
        if item.startswith("-") and SENSITIVE_NAME.search(item):
            redacted.append("[REDACTED SENSITIVE OPTION]")
            redact_next = True
            continue
        if "=" in item:
            name, _value = item.split("=", 1)
            if SENSITIVE_NAME.search(name):
                redacted.append(f"{name}=[REDACTED]")
                continue
        if SENSITIVE_NAME.search(item):
            redacted.append("[REDACTED SENSITIVE ARG]")
            continue
        redacted.append(item)
    return redacted


def controlled_environment_names(
    gates: dict[str, dict[str, object]],
) -> set[str]:
    names: set[str] = set()
    for gate in gates.values():
        if isinstance(gate, dict):
            names.update(str(item) for item in gate.get("allowed_env", []))
    return names


def registered_process_environment(
    gates: dict[str, dict[str, object]],
    explicit: dict[str, str],
) -> dict[str, str]:
    controlled = controlled_environment_names(gates)
    inherited = {
        name: value
        for name, value in os.environ.items()
        if name not in controlled
        and not any(name.startswith(prefix) for prefix in CONTROLLED_ENV_PREFIXES)
    }
    inherited.update(explicit)
    return inherited


def parse_environment(values: list[str], allowed: set[str] | None) -> dict[str, str]:
    result: dict[str, str] = {}
    for value in values:
        if "=" not in value:
            raise SystemExit(f"无效环境变量格式 {value!r}，应为 NAME=value")
        name, content = value.split("=", 1)
        if not re.fullmatch(r"[A-Z_][A-Z0-9_]*", name):
            raise SystemExit(f"无效环境变量名：{name}")
        if allowed is not None and name not in allowed:
            raise SystemExit(f"gate manifest 未允许环境变量：{name}")
        result[name] = content
    return result


def parse_expected_environment(
    values: list[str],
    gates: dict[str, dict[str, object]],
    required: set[str],
) -> dict[str, dict[str, str]]:
    expected: dict[str, dict[str, str]] = {name: {} for name in required}
    for value in values:
        if ":" not in value:
            raise SystemExit(
                f"无效预期环境变量格式 {value!r}，应为 GATE:NAME=value"
            )
        gate_name, assignment = value.split(":", 1)
        if gate_name not in required:
            raise SystemExit(f"--expect-env 的 gate 未列入 --require：{gate_name}")
        gate = gates.get(gate_name)
        if not isinstance(gate, dict):
            raise SystemExit(f"gate manifest 未注册：{gate_name}")
        allowed = {str(item) for item in gate.get("allowed_env", [])}
        parsed = parse_environment([assignment], allowed)
        expected[gate_name].update(parsed)
    return expected


def lock_root(repo: Path) -> Path:
    repo_id = hashlib.sha256(str(repo).encode()).hexdigest()[:16]
    root = Path(tempfile.gettempdir()) / "ssl-manager-finish-check-locks" / repo_id
    root.mkdir(parents=True, exist_ok=True)
    return root


def parse_locks(values: list[str]) -> dict[str, str]:
    locks: dict[str, str] = {"source": "shared"}
    for value in values:
        try:
            name, mode = value.split(":", 1)
        except ValueError as exc:
            raise SystemExit(f"无效锁格式 {value!r}，应为 name:shared|exclusive") from exc
        if not re.fullmatch(r"[a-z0-9][a-z0-9-]*", name):
            raise SystemExit(f"无效锁名：{name}")
        if mode not in {"shared", "exclusive"}:
            raise SystemExit(f"无效锁模式：{mode}")
        if locks.get(name) == "exclusive" or mode == "exclusive":
            locks[name] = "exclusive"
        else:
            locks[name] = "shared"
    return locks


@contextlib.contextmanager
def acquire_locks(repo: Path, requested: dict[str, str]) -> Iterator[None]:
    handles: list[object] = []
    try:
        for name in sorted(requested):
            path = lock_root(repo) / f"{name}.lock"
            handle = path.open("a+")
            operation = (
                fcntl.LOCK_EX if requested[name] == "exclusive" else fcntl.LOCK_SH
            )
            fcntl.flock(handle.fileno(), operation)
            handles.append(handle)
        yield
    finally:
        for handle in reversed(handles):
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
            handle.close()


def append_ledger(repo: Path, run_dir: Path, entry: dict[str, object]) -> None:
    ensure_private_dir(run_dir)
    ledger = run_dir / LEDGER_FILE
    lock_path = lock_root(repo) / "ledger.lock"
    with lock_path.open("a+") as lock_handle:
        fcntl.flock(lock_handle.fileno(), fcntl.LOCK_EX)
        fd = os.open(ledger, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
        with os.fdopen(fd, "a", encoding="utf-8") as handle:
            handle.write(json.dumps(entry, ensure_ascii=False, sort_keys=True))
            handle.write("\n")
        ledger.chmod(0o600)


def safe_name(value: str) -> str:
    result = re.sub(r"[^a-zA-Z0-9._-]+", "-", value).strip("-")
    return result or "gate"


def command_freeze(args: argparse.Namespace) -> int:
    repo = repo_root()
    run_dir = Path(args.run_dir).resolve()
    manifest_path = resolve_manifest(repo, args.manifest)
    _gates, manifest_hash = load_manifest(manifest_path)
    with acquire_locks(repo, {"source": "exclusive"}):
        fingerprint = source_fingerprint(repo)
        old_state = {}
        state_path = run_dir / STATE_FILE
        if state_path.is_file():
            with state_path.open(encoding="utf-8") as handle:
                old_state = json.load(handle)
        generation = int(old_state.get("generation", 0)) + 1
        state = {
            "repo": str(repo),
            "generation": generation,
            "fingerprint": fingerprint,
            "frozen_at": utc_now(),
            "manifest": str(manifest_path),
            "manifest_hash": manifest_hash,
        }
        atomic_json_write(state_path, state)
    print(f"FROZEN generation={generation} fingerprint={fingerprint}")
    return 0


def terminate_process(process: subprocess.Popen[str]) -> None:
    if process.poll() is not None:
        return
    try:
        os.killpg(process.pid, signal.SIGTERM)
        process.wait(timeout=5)
    except ProcessLookupError:
        process.wait()
    except subprocess.TimeoutExpired:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait(timeout=5)
    finally:
        if process.stdout is not None:
            process.stdout.close()


@contextlib.contextmanager
def guard_subprocess_spawn_signals() -> Iterator[set[signal.Signals]]:
    guarded = {signal.SIGINT, signal.SIGTERM, signal.SIGHUP}
    previous_mask = signal.pthread_sigmask(signal.SIG_BLOCK, guarded)
    previous: dict[signal.Signals, object] = {}

    def interrupt(_signum: int, _frame: object) -> None:
        raise KeyboardInterrupt

    for signum in (signal.SIGTERM, signal.SIGHUP):
        previous[signum] = signal.getsignal(signum)
        signal.signal(signum, interrupt)
    try:
        yield previous_mask
    finally:
        signal.pthread_sigmask(signal.SIG_SETMASK, previous_mask)
        for signum, handler in previous.items():
            signal.signal(signum, handler)


def command_run(args: argparse.Namespace) -> int:
    repo = repo_root()
    run_dir = Path(args.run_dir).resolve()
    state = load_state(run_dir)
    manifest_path = resolve_manifest(repo, args.manifest)
    gates, manifest_hash = load_manifest(manifest_path)
    if manifest_hash != state.get("manifest_hash"):
        raise SystemExit("gate manifest 与 freeze 时不一致；请重新 freeze")

    registered = args.gate is not None
    if registered:
        if args.command:
            raise SystemExit("--gate 使用 manifest 中的规范命令，不能再传 -- command")
        if args.gate not in gates:
            raise SystemExit(f"gate manifest 未注册：{args.gate}")
        gate = gates[args.gate]
        if not isinstance(gate, dict):
            raise SystemExit(f"gate manifest 条目无效：{args.gate}")
        name = args.gate
        command = [str(item) for item in gate.get("command", [])]
        manifest_locks = [str(item) for item in gate.get("locks", [])]
        allowed_env = {str(item) for item in gate.get("allowed_env", [])}
    else:
        if args.name in gates:
            raise SystemExit(f"{args.name} 是受保护 gate，必须使用 --gate {args.name}")
        if not args.command:
            raise SystemExit("自定义 run 需要在 -- 后提供命令")
        name = args.name
        command = list(args.command)
        manifest_locks = []
        allowed_env = None

    if command and command[0] == "--":
        command = command[1:]
    if not command:
        raise SystemExit("run 子命令的命令为空")

    requested = parse_locks([*manifest_locks, *args.lock])
    environment = parse_environment(args.env, allowed_env)
    process_environment = (
        registered_process_environment(gates, environment)
        if registered
        else {**os.environ, **environment}
    )
    started_at = utc_now()
    started_clock = time.monotonic()
    log_dir = run_dir / "logs"
    ensure_private_dir(log_dir)
    log_path = log_dir / f"{safe_name(name)}-{time.time_ns()}-{os.getpid()}.log"

    lock_started_clock = time.monotonic()
    with acquire_locks(repo, requested):
        lock_acquired_clock = time.monotonic()
        before = source_fingerprint(repo)
        expected = str(state["fingerprint"])
        base_entry: dict[str, object] = {
            "name": name,
            "generation": state["generation"],
            "expected_fingerprint": expected,
            "before_fingerprint": before,
            "registered_gate": registered,
            "manifest_hash": manifest_hash,
            "command_hash": command_hash(command),
            "command_display": redact_command(command),
            "environment_keys": sorted(environment),
            "environment_hash": environment_hash(environment),
            "locks": requested,
            "cache_state": args.cache_state,
            "started_at": started_at,
            "log": str(log_path),
            "lock_wait_seconds": round(
                lock_acquired_clock - lock_started_clock,
                3,
            ),
        }
        if before != expected:
            entry = {
                **base_entry,
                "status": "rejected-stale-before-start",
                "exit_code": STALE_EXIT,
                "ended_at": utc_now(),
                "duration_seconds": round(time.monotonic() - started_clock, 3),
                "execution_seconds": 0.0,
            }
            append_ledger(repo, run_dir, entry)
            print(
                f"STALE: 当前 fingerprint={before}，冻结值={expected}；"
                "请重新 freeze 并重跑受影响门禁",
                file=sys.stderr,
            )
            return STALE_EXIT

        exit_code = 1
        execution_started_clock = time.monotonic()
        fd = os.open(log_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as log_handle:
            with guard_subprocess_spawn_signals() as previous_signal_mask:
                def restore_child_signal_mask() -> None:
                    signal.pthread_sigmask(
                        signal.SIG_SETMASK,
                        previous_signal_mask,
                    )

                process = subprocess.Popen(
                    command,
                    cwd=repo,
                    env=process_environment,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.STDOUT,
                    text=True,
                    encoding="utf-8",
                    errors="replace",
                    bufsize=1,
                    start_new_session=True,
                    preexec_fn=restore_child_signal_mask,
                )
                try:
                    # spawn 期间到达的信号一直挂起；process 已赋值后才恢复，
                    # 挂起信号会在本 try 内交付，从而能可靠终止子进程组。
                    signal.pthread_sigmask(
                        signal.SIG_SETMASK,
                        previous_signal_mask,
                    )
                    assert process.stdout is not None
                    for line in process.stdout:
                        sys.stdout.write(line)
                        sys.stdout.flush()
                        log_handle.write(line)
                        log_handle.flush()
                    exit_code = process.wait()
                except KeyboardInterrupt:
                    terminate_process(process)
                    exit_code = 130
                finally:
                    if process.stdout is not None:
                        process.stdout.close()

        after = source_fingerprint(repo)
        stable = before == after == expected
        if exit_code == 0 and stable:
            status = "passed"
            final_exit = 0
        elif exit_code == 0:
            status = "stale-source-changed"
            final_exit = STALE_EXIT
        else:
            status = "failed" if stable else "failed-and-stale"
            final_exit = exit_code

        entry = {
            **base_entry,
            "after_fingerprint": after,
            "status": status,
            "exit_code": exit_code,
            "effective_exit_code": final_exit,
            "ended_at": utc_now(),
            "duration_seconds": round(time.monotonic() - started_clock, 3),
            "execution_seconds": round(
                time.monotonic() - execution_started_clock,
                3,
            ),
        }
        append_ledger(repo, run_dir, entry)
        if not stable:
            print(
                f"STALE: {name} 执行期间源码 fingerprint 发生变化，"
                "本次结果不得作为 finish-check 证据",
                file=sys.stderr,
            )
        return final_exit


def command_verify(args: argparse.Namespace) -> int:
    repo = repo_root()
    run_dir = Path(args.run_dir).resolve()
    state = load_state(run_dir)
    manifest_path = resolve_manifest(repo, args.manifest)
    gates, manifest_hash = load_manifest(manifest_path)
    if manifest_hash != state.get("manifest_hash"):
        print(
            "VERIFY_FAIL: gate manifest 与 freeze 时不一致",
            file=sys.stderr,
        )
        return STALE_EXIT
    with acquire_locks(repo, {"source": "shared"}):
        current = source_fingerprint(repo)
    expected = str(state["fingerprint"])
    if current != expected:
        print(
            f"VERIFY_FAIL: 当前 fingerprint={current}，冻结值={expected}",
            file=sys.stderr,
        )
        return STALE_EXIT

    latest: dict[str, dict[str, object]] = {}
    ledger = run_dir / LEDGER_FILE
    if ledger.is_file():
        with ledger.open(encoding="utf-8") as handle:
            for line in handle:
                if not line.strip():
                    continue
                entry = json.loads(line)
                if (
                    entry.get("generation") == state["generation"]
                    and entry.get("expected_fingerprint") == expected
                ):
                    latest[str(entry["name"])] = entry

    expected_environments = parse_expected_environment(
        args.expect_env,
        gates,
        set(args.require),
    )
    missing: list[str] = []
    for name in args.require:
        gate = gates.get(name)
        entry = latest.get(name)
        if not isinstance(gate, dict) or entry is None:
            missing.append(name)
            continue
        expected_command = [str(item) for item in gate.get("command", [])]
        required_locks = parse_locks([str(item) for item in gate.get("locks", [])])
        actual_locks = entry.get("locks")
        locks_match = isinstance(actual_locks, dict) and all(
            actual_locks.get(lock_name) == lock_mode
            for lock_name, lock_mode in required_locks.items()
        )
        if (
            entry.get("status") != "passed"
            or entry.get("registered_gate") is not True
            or entry.get("manifest_hash") != manifest_hash
            or entry.get("command_hash") != command_hash(expected_command)
            or entry.get("environment_hash")
            != environment_hash(expected_environments[name])
            or not locks_match
        ):
            missing.append(name)
    if missing:
        print(
            "VERIFY_FAIL: 当前 generation 缺少有效门禁：" + ", ".join(missing),
            file=sys.stderr,
        )
        return 1
    print(
        f"VERIFY_OK generation={state['generation']} fingerprint={expected} "
        f"gates={','.join(args.require)}"
    )
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="subcommand", required=True)

    freeze_parser = subparsers.add_parser("freeze", help="冻结当前源码 fingerprint")
    freeze_parser.add_argument("--run-dir", required=True)
    freeze_parser.add_argument("--manifest", default=DEFAULT_MANIFEST)
    freeze_parser.set_defaults(func=command_freeze)

    run_parser = subparsers.add_parser("run", help="在锁和 fingerprint 守卫下执行门禁")
    run_parser.add_argument("--run-dir", required=True)
    identity = run_parser.add_mutually_exclusive_group(required=True)
    identity.add_argument("--gate", help="运行 manifest 中注册的规范 gate")
    identity.add_argument("--name", help="仅记账的自定义命令，不能满足 verify")
    run_parser.add_argument("--manifest", default=DEFAULT_MANIFEST)
    run_parser.add_argument(
        "--lock",
        action="append",
        default=[],
        help="资源锁，格式 name:shared|exclusive，可重复",
    )
    run_parser.add_argument(
        "--env",
        action="append",
        default=[],
        help="manifest 允许的环境变量，格式 NAME=value，可重复",
    )
    run_parser.add_argument(
        "--cache-state",
        choices=["unknown", "hit", "miss", "not-applicable"],
        default="unknown",
    )
    run_parser.add_argument("command", nargs=argparse.REMAINDER)
    run_parser.set_defaults(func=command_run)

    verify_parser = subparsers.add_parser(
        "verify", help="验证当前 generation 已有指定有效门禁"
    )
    verify_parser.add_argument("--run-dir", required=True)
    verify_parser.add_argument("--manifest", default=DEFAULT_MANIFEST)
    verify_parser.add_argument("--require", action="append", default=[], required=True)
    verify_parser.add_argument(
        "--expect-env",
        action="append",
        default=[],
        help="绑定 gate 的显式环境变量值，格式 GATE:NAME=value，可重复",
    )
    verify_parser.set_defaults(func=command_verify)
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        return int(args.func(args))
    except subprocess.CalledProcessError as exc:
        stderr = exc.stderr.decode("utf-8", "replace") if exc.stderr else ""
        print(f"命令失败：{' '.join(exc.cmd)}\n{stderr}", file=sys.stderr)
        return exc.returncode or 1


if __name__ == "__main__":
    raise SystemExit(main())
