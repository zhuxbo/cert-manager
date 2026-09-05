from __future__ import annotations

import json
import importlib.util
import os
import signal
import subprocess
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock


SCRIPT = Path(__file__).resolve().parents[1] / "finish-check-exec.py"


def load_executor_module():
    spec = importlib.util.spec_from_file_location("finish_check_exec_under_test", SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class FinishCheckExecTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.repo = Path(self.temp_dir.name)
        subprocess.run(["git", "init", "-q"], cwd=self.repo, check=True)
        subprocess.run(
            ["git", "config", "user.email", "test@example.com"],
            cwd=self.repo,
            check=True,
        )
        subprocess.run(
            ["git", "config", "user.name", "Test"],
            cwd=self.repo,
            check=True,
        )
        (self.repo / "tracked.txt").write_text("base\n", encoding="utf-8")
        subprocess.run(["git", "add", "tracked.txt"], cwd=self.repo, check=True)
        subprocess.run(["git", "commit", "-qm", "init"], cwd=self.repo, check=True)
        self.run_dir = self.repo / ".superpowers" / "run"
        (self.repo / ".gitignore").write_text(".superpowers/\n", encoding="utf-8")
        subprocess.run(["git", "add", ".gitignore"], cwd=self.repo, check=True)
        subprocess.run(["git", "commit", "-qm", "ignore"], cwd=self.repo, check=True)
        self.manifest = self.repo / "finish-check-gates.json"
        self.manifest.write_text(
            json.dumps(
                {
                    "version": 1,
                    "gates": {
                        "stable": {
                            "command": ["sh", "-c", "test ! -f .superpowers/fail"],
                            "locks": [],
                            "allowed_env": ["SAFE_EXTRA_TARGET"],
                        },
                        "ambient-clean": {
                            "command": [
                                "sh",
                                "-c",
                                "test -z \"${SAFE_EXTRA_TARGET:-}\" && "
                                "test -z \"${MUTATE_DRY_RUN:-}\"",
                            ],
                            "locks": [],
                        },
                        "mutating-gate": {
                            "command": [
                                "sh",
                                "-c",
                                "printf changed >> tracked.txt",
                            ],
                            "locks": [],
                        },
                        "first": {
                            "command": ["sh", "-c", "sleep 0.5"],
                            "locks": ["db:exclusive"],
                        },
                        "second": {
                            "command": ["sh", "-c", "sleep 0.5"],
                            "locks": ["db:exclusive"],
                        },
                        "long-running": {
                            "command": [
                                "sh",
                                "-c",
                                "echo $$ > .superpowers/child.pid; exec sleep 30",
                            ],
                            "locks": [],
                        },
                    },
                }
            ),
            encoding="utf-8",
        )
        subprocess.run(
            ["git", "add", self.manifest.name],
            cwd=self.repo,
            check=True,
        )
        subprocess.run(["git", "commit", "-qm", "manifest"], cwd=self.repo, check=True)

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def command(
        self,
        *args: str,
        check: bool = False,
        environment: dict[str, str] | None = None,
    ) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["python3", str(SCRIPT), *args],
            cwd=self.repo,
            env=environment,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=check,
        )

    def freeze(self) -> dict[str, object]:
        self.command(
            "freeze",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            check=True,
        )
        return json.loads((self.run_dir / "state.json").read_text(encoding="utf-8"))

    def test_summary_reports_timings_without_executing_or_authorizing_gates(self) -> None:
        self.freeze()
        empty = self.command("summary", "--run-dir", str(self.run_dir))
        self.assertEqual(0, empty.returncode, empty.stderr)
        self.run_gate("stable", check=True)
        self.run_gate("stable", check=True)
        ledger = self.run_dir / "ledger.jsonl"
        before = ledger.read_bytes()
        result = self.command("summary", "--run-dir", str(self.run_dir))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("stable | passed", result.stdout)
        self.assertIn("本运行累计次数", result.stdout)
        self.assertIn("等待不可相加", result.stdout)
        self.assertNotIn("VERIFY_OK", result.stdout)
        self.assertNotIn("test ! -f", result.stdout)
        self.assertEqual(before, ledger.read_bytes())

    def run_gate(
        self,
        name: str,
        *,
        check: bool = False,
    ) -> subprocess.CompletedProcess[str]:
        return self.command(
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--gate",
            name,
            check=check,
        )

    def verify(self, name: str) -> subprocess.CompletedProcess[str]:
        return self.command(
            "verify",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--require",
            name,
        )

    def test_fingerprint_detects_tracked_untracked_and_mode_changes(self) -> None:
        first = self.freeze()["fingerprint"]

        (self.repo / "tracked.txt").write_text("changed\n", encoding="utf-8")
        second = self.freeze()["fingerprint"]
        self.assertNotEqual(first, second)

        (self.repo / "new.txt").write_text("untracked\n", encoding="utf-8")
        third = self.freeze()["fingerprint"]
        self.assertNotEqual(second, third)

        os.chmod(self.repo / "tracked.txt", 0o755)
        fourth = self.freeze()["fingerprint"]
        self.assertNotEqual(third, fourth)

    def test_read_only_gate_is_rejected_when_it_changes_source(self) -> None:
        self.freeze()
        result = self.run_gate("mutating-gate")
        self.assertEqual(86, result.returncode)
        ledger = [
            json.loads(line)
            for line in (self.run_dir / "ledger.jsonl").read_text(
                encoding="utf-8"
            ).splitlines()
        ]
        self.assertEqual("stale-source-changed", ledger[-1]["status"])

    def test_new_generation_invalidates_old_passes(self) -> None:
        self.freeze()
        self.run_gate("stable", check=True)
        self.verify("stable").check_returncode()

        (self.repo / "tracked.txt").write_text("next\n", encoding="utf-8")
        self.freeze()
        result = self.verify("stable")
        self.assertEqual(1, result.returncode)
        self.assertIn("缺少有效门禁", result.stderr)

    def test_latest_gate_result_overrides_earlier_pass(self) -> None:
        self.freeze()
        self.run_gate("stable", check=True)
        (self.repo / ".superpowers" / "fail").write_text("", encoding="utf-8")
        failed = self.run_gate("stable")
        self.assertEqual(1, failed.returncode)

        result = self.verify("stable")
        self.assertEqual(1, result.returncode)
        self.assertIn("缺少有效门禁", result.stderr)

    def test_exclusive_resource_lock_serializes_commands(self) -> None:
        self.freeze()
        base = [
            "python3",
            str(SCRIPT),
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
        ]
        started = time.monotonic()
        first = subprocess.Popen(
            [*base, "--gate", "first"],
            cwd=self.repo,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            text=True,
        )
        time.sleep(0.1)
        second = subprocess.Popen(
            [*base, "--gate", "second"],
            cwd=self.repo,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            text=True,
        )
        self.assertEqual(0, first.wait())
        self.assertEqual(0, second.wait())
        self.assertGreaterEqual(time.monotonic() - started, 0.9)

    def test_sigterm_terminates_child_process_group_before_exit(self) -> None:
        self.freeze()
        child_pid_path = self.repo / ".superpowers" / "child.pid"
        process = subprocess.Popen(
            [
                "python3",
                str(SCRIPT),
                "run",
                "--run-dir",
                str(self.run_dir),
                "--manifest",
                str(self.manifest),
                "--gate",
                "long-running",
            ],
            cwd=self.repo,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            text=True,
        )
        deadline = time.monotonic() + 5
        while not child_pid_path.is_file() and time.monotonic() < deadline:
            time.sleep(0.02)
        self.assertTrue(child_pid_path.is_file())
        child_pid = int(child_pid_path.read_text(encoding="utf-8"))

        process.send_signal(signal.SIGTERM)
        self.assertEqual(130, process.wait(timeout=7))
        with self.assertRaises(ProcessLookupError):
            os.kill(child_pid, 0)

    def test_sigterm_arriving_during_popen_is_delivered_after_process_assignment(
        self,
    ) -> None:
        self.freeze()
        executor = load_executor_module()
        race_command = "exec sleep 30"
        args = executor.build_parser().parse_args(
            [
                "run",
                "--run-dir",
                str(self.run_dir),
                "--manifest",
                str(self.manifest),
                "--name",
                "spawn-race",
                "--",
                "sh",
                "-c",
                race_command,
            ]
        )
        real_popen = subprocess.Popen
        injected = False
        injected_child_pid = 0

        def popen_with_pending_sigterm(command, *popen_args, **popen_kwargs):
            nonlocal injected, injected_child_pid
            process = real_popen(command, *popen_args, **popen_kwargs)
            if command == ["sh", "-c", race_command]:
                injected = True
                injected_child_pid = process.pid
                os.kill(os.getpid(), signal.SIGTERM)
            return process

        previous_cwd = Path.cwd()
        try:
            os.chdir(self.repo)
            with mock.patch.object(
                executor.subprocess,
                "Popen",
                side_effect=popen_with_pending_sigterm,
            ):
                self.assertEqual(130, executor.command_run(args))
        finally:
            os.chdir(previous_cwd)

        self.assertTrue(injected)
        self.assertGreater(injected_child_pid, 0)
        with self.assertRaises(ProcessLookupError):
            os.kill(injected_child_pid, 0)

    def test_registered_gate_cannot_be_spoofed_with_custom_command(self) -> None:
        self.freeze()
        result = self.command(
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--name",
            "stable",
            "--",
            "true",
        )
        self.assertNotEqual(0, result.returncode)
        self.assertIn("必须使用 --gate stable", result.stderr)

    def test_registered_gate_rejects_unapproved_environment(self) -> None:
        self.freeze()
        result = self.command(
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--gate",
            "stable",
            "--env",
            "MUTATE_DRY_RUN=1",
        )
        self.assertNotEqual(0, result.returncode)
        self.assertIn("未允许环境变量：MUTATE_DRY_RUN", result.stderr)

    def test_registered_gate_drops_controlled_ambient_environment(self) -> None:
        self.freeze()
        environment = {
            **os.environ,
            "SAFE_EXTRA_TARGET": "ambient-must-not-leak",
            "MUTATE_DRY_RUN": "1",
        }
        result = self.command(
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--gate",
            "ambient-clean",
            check=True,
            environment=environment,
        )
        self.assertEqual(0, result.returncode)

    def test_verify_binds_explicit_environment_values(self) -> None:
        self.freeze()
        result = self.command(
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--gate",
            "stable",
            "--env",
            "SAFE_EXTRA_TARGET=expected-target",
            check=True,
        )
        self.assertEqual(0, result.returncode)
        self.assertEqual(1, self.verify("stable").returncode)

        verified = self.command(
            "verify",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--require",
            "stable",
            "--expect-env",
            "stable:SAFE_EXTRA_TARGET=expected-target",
        )
        self.assertEqual(0, verified.returncode, verified.stderr)

    def test_ledger_redacts_sensitive_argv_shell_payload_and_uses_private_modes(
        self,
    ) -> None:
        self.freeze()
        secret = "do-not-store-this-token"
        result = self.command(
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--name",
            "custom-secret-check",
            "--",
            "bash",
            "-lc",
            f'test -n "{secret}" # Authorization: Bearer {secret}',
            check=True,
        )
        self.assertEqual(0, result.returncode)
        ledger_path = self.run_dir / "ledger.jsonl"
        ledger_text = ledger_path.read_text(encoding="utf-8")
        self.assertNotIn(secret, ledger_text)
        self.assertEqual(0o600, ledger_path.stat().st_mode & 0o777)
        self.assertEqual(0o700, self.run_dir.stat().st_mode & 0o777)
        entry = json.loads(ledger_text.splitlines()[-1])
        self.assertEqual(
            ["bash", "-lc", "[REDACTED SHELL PAYLOAD]"],
            entry["command_display"],
        )
        log_path = Path(entry["log"])
        self.assertEqual(0o600, log_path.stat().st_mode & 0o777)
        self.assertIn("lock_wait_seconds", entry)
        self.assertIn("execution_seconds", entry)

    def test_ledger_redacts_authorization_header_in_plain_argv(self) -> None:
        self.freeze()
        secret = "plain-argv-secret"
        result = self.command(
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--name",
            "custom-header-check",
            "--",
            "python3",
            "-c",
            "import sys",
            "-H",
            f"Authorization: Bearer {secret}",
            check=True,
        )
        self.assertEqual(0, result.returncode)
        ledger_text = (self.run_dir / "ledger.jsonl").read_text(encoding="utf-8")
        self.assertNotIn(secret, ledger_text)
        entry = json.loads(ledger_text.splitlines()[-1])
        self.assertEqual(
            ["python3", "-c", "import sys", "-H", "[REDACTED]"],
            entry["command_display"],
        )

    def test_ledger_redacts_attached_curl_sensitive_options(self) -> None:
        self.freeze()
        secret = "attached-option-secret"
        result = self.command(
            "run",
            "--run-dir",
            str(self.run_dir),
            "--manifest",
            str(self.manifest),
            "--name",
            "custom-attached-header-check",
            "--",
            "python3",
            "-c",
            "import sys",
            f"-HAuthorization: Bearer {secret}",
            f"-bSESSION={secret}",
            f"-uadmin:{secret}",
            "--access-token",
            secret,
            check=True,
        )
        self.assertEqual(0, result.returncode)
        ledger_text = (self.run_dir / "ledger.jsonl").read_text(encoding="utf-8")
        self.assertNotIn(secret, ledger_text)
        entry = json.loads(ledger_text.splitlines()[-1])
        self.assertEqual(
            [
                "python3",
                "-c",
                "import sys",
                "-H[REDACTED]",
                "-b[REDACTED]",
                "-u[REDACTED]",
                "[REDACTED SENSITIVE OPTION]",
                "[REDACTED]",
            ],
            entry["command_display"],
        )


if __name__ == "__main__":
    unittest.main()
