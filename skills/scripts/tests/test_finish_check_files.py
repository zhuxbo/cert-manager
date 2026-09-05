from __future__ import annotations

import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock


SCRIPT = Path(__file__).resolve().parents[1] / "finish-check-files.py"
ORPHANS = SCRIPT.with_name("check-orphan-fixtures.sh")


def load_module():
    spec = importlib.util.spec_from_file_location("finish_check_files_under_test", SCRIPT)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class FinishCheckFilesTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.repo = Path(self.temporary.name)
        self.module = load_module()
        self.git("init", "-q")
        self.git("config", "user.email", "test@example.com")
        self.git("config", "user.name", "Test")
        (self.repo / "README.md").write_text("base\n")
        (self.repo / "removed.sh").write_text("exit 0\n")
        (self.repo / ".gitignore").write_text("ignored/\n")
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")

    def tearDown(self):
        self.temporary.cleanup()

    def git(self, *args):
        return subprocess.run(["git", *args], cwd=self.repo, check=True, capture_output=True)

    def command(self, *args):
        return subprocess.run([sys.executable, str(SCRIPT), *args], cwd=self.repo, capture_output=True)

    def test_union_includes_staged_worktree_untracked_and_deleted(self):
        (self.repo / "README.md").write_text("staged\n")
        self.git("add", "README.md")
        (self.repo / "README.md").write_text("worktree\n")
        (self.repo / "removed.sh").unlink()
        (self.repo / "new 中文 name.py").write_text("pass\n")
        (self.repo / "ignored").mkdir()
        (self.repo / "ignored/no.py").write_text("pass\n")
        result = self.command("list", "--null")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            ["README.md", "new 中文 name.py", "removed.sh"],
            result.stdout.decode().rstrip("\0").split("\0"),
        )

    def test_docs_only_cannot_hide_untracked_code_or_staged_code(self):
        self.assertEqual(1, self.command("docs-only").returncode)
        (self.repo / "README.md").write_text("changed\n")
        self.assertEqual(0, self.command("docs-only").returncode)
        (self.repo / "new.php").write_text("<?php\n")
        self.assertEqual(1, self.command("docs-only").returncode)
        self.git("add", "new.php")
        self.assertEqual(1, self.command("docs-only").returncode)

    def test_checks_use_argument_arrays_and_ignore_deleted_paths(self):
        (self.repo / "removed.sh").unlink()
        names = ["--new file.md", "new file.sh", "removed.sh"]
        for name in names[:2]:
            (self.repo / name).write_text("content\n")
        prettier = self.repo / "frontend/admin/node_modules/.bin/prettier"
        prettier.parent.mkdir(parents=True)
        prettier.touch()
        with mock.patch.object(self.module.subprocess, "run") as run, mock.patch.object(
            self.module.shutil, "which", return_value="/usr/bin/shfmt"
        ):
            self.module.check_files(self.repo, names, "all")
        calls = [call.args[0] for call in run.call_args_list]
        self.assertIn([str(prettier), "--check", "--", "--new file.md"], calls)
        self.assertIn(["bash", "-n", "--", "new file.sh"], calls)
        self.assertIn(["/usr/bin/shfmt", "-d", "-i", "4", "-ci", "--", "new file.sh"], calls)
        self.assertFalse(any("removed.sh" in command for command in calls))

    def test_explicit_base_excludes_untracked_files(self):
        (self.repo / "README.md").write_text("changed\n")
        (self.repo / "new.php").write_text("<?php\n")
        result = self.command("list", "--base", "HEAD", "--null")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(b"README.md\0", result.stdout)

    def test_missing_formatters_and_failed_checks_are_not_passes(self):
        (self.repo / "new.md").write_text("text\n")
        (self.repo / "new.sh").write_text("exit 0\n")
        with self.assertRaisesRegex(RuntimeError, "Prettier"):
            self.module.check_files(self.repo, ["new.md"], "all")
        with mock.patch.object(self.module.shutil, "which", return_value=None):
            with self.assertRaisesRegex(RuntimeError, "shfmt"):
                self.module.check_files(self.repo, ["new.sh"], "all")
        with mock.patch.object(
            self.module.subprocess, "run", side_effect=subprocess.CalledProcessError(1, ["git"])
        ):
            with self.assertRaises(subprocess.CalledProcessError):
                self.module.check_files(self.repo, [], "all")

    def test_new_python_and_json_are_validated_without_source_artifacts(self):
        (self.repo / "new.py").write_text("value = 1\n")
        (self.repo / "new.json").write_text('{"valid": true}\n')
        self.module.check_files(self.repo, ["new.py", "new.json"], "all")
        self.assertFalse((self.repo / "__pycache__").exists())
        (self.repo / "new.json").write_text("broken")
        with self.assertRaises(ValueError):
            self.module.check_files(self.repo, ["new.json"], "all")
        (self.repo / "new.py").write_text("def broken(:")
        with self.assertRaises(self.module.py_compile.PyCompileError):
            self.module.check_files(self.repo, ["new.py"], "all")

    def test_orphan_check_fails_when_neither_docker_nor_php_is_available(self):
        directory = self.repo / "skills/scripts"
        directory.mkdir(parents=True)
        script = directory / ORPHANS.name
        script.write_text(ORPHANS.read_text())
        binaries = self.repo / "bin"
        binaries.mkdir()
        for name in ("dirname", "grep"):
            (binaries / name).symlink_to(f"/usr/bin/{name}")
        docker = binaries / "docker"
        docker.write_text("#!/bin/sh\nexit 1\n")
        docker.chmod(0o755)
        environment = {**os.environ, "PATH": str(binaries)}
        environment.pop("ORPHAN_FIXTURE_PHP", None)
        result = subprocess.run(["/bin/bash", str(script)], env=environment, capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("孤儿夹具检查未执行", result.stderr)


if __name__ == "__main__":
    unittest.main()
