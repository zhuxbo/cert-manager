from __future__ import annotations

import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "backend" / "scripts" / "test-mutate.sh"


class MutateTargetsTest(unittest.TestCase):
    def run_dry(
        self,
        target_classes: str | None = None,
        *,
        target_paths: str | None = None,
        check: bool = True,
    ) -> subprocess.CompletedProcess[str]:
        env = os.environ.copy()
        env["MUTATE_DRY_RUN"] = "1"
        if target_classes is not None:
            env["MUTATE_TARGET_CLASSES"] = target_classes
        if target_paths is not None:
            env["MUTATE_TARGET_PATHS"] = target_paths

        return subprocess.run(
            ["bash", str(SCRIPT)],
            cwd=ROOT,
            env=env,
            check=check,
            capture_output=True,
            text=True,
        )

    def mutation_classes(self, result: subprocess.CompletedProcess[str]) -> list[str]:
        filter_line = next(
            line
            for line in result.stdout.splitlines()
            if line.startswith("Mutation class filter: ")
        )
        return filter_line.removeprefix("Mutation class filter: ").split(",")

    def mutation_paths(self, result: subprocess.CompletedProcess[str]) -> list[str]:
        filter_line = next(
            line
            for line in result.stdout.splitlines()
            if line.startswith("Mutation path filter: ")
        )
        return filter_line.removeprefix("Mutation path filter: ").split(",")

    def test_default_run_targets_all_six_core_classes(self) -> None:
        result = self.run_dry()
        self.assertEqual(
            self.mutation_classes(result),
            [
                "App\\Models\\Fund",
                "App\\Models\\Transaction",
                "App\\Services\\Acme\\Action",
                "App\\Services\\Order\\Action",
                "App\\Services\\Order\\AutoRenewService",
                "App\\Services\\FundAudit\\FundInvariants",
            ],
        )
        self.assertEqual(
            self.mutation_paths(result),
            [
                "app/Models/Fund.php",
                "app/Models/Transaction.php",
                "app/Services/Acme/Action.php",
                "app/Services/Order/Action.php",
                "app/Services/Order/AutoRenewService.php",
                "app/Services/FundAudit/FundInvariants.php",
            ],
        )

    def test_explicit_targets_replace_full_default_set(self) -> None:
        result = self.run_dry(
            " App\\Services\\Order\\Traits\\ActionFileTrait, "
            "App\\Services\\Plugin\\PluginManager ",
            target_paths=(
                " app/Services/Order/Traits/ActionFileTrait.php,"
                "app/Services/Plugin/PluginManager.php "
            ),
        )

        self.assertEqual(
            self.mutation_classes(result),
            [
                "App\\Services\\Order\\Traits\\ActionFileTrait",
                "App\\Services\\Plugin\\PluginManager",
            ],
        )
        self.assertEqual(
            self.mutation_paths(result),
            [
                "app/Services/Order/Traits/ActionFileTrait.php",
                "app/Services/Plugin/PluginManager.php",
            ],
        )

        script = SCRIPT.read_text()
        self.assertEqual(script.count('--class="$MUTATE_CLASS_FILTER"'), 1)
        self.assertEqual(script.count('--path="$MUTATE_PATH_FILTER"'), 1)
        self.assertNotIn("MUTATE_CLASS_ARGS", script)
        self.assertIn('PHP_INI_SCAN_DIR="$MUTATE_SCAN_DIR"', script)
        self.assertNotIn(
            'PCOV_INI="${PHP_INI_DIR:-/usr/local/etc/php}/conf.d/',
            script,
        )

    def test_pcov_probe_does_not_use_quiet_grep_with_pipefail(self) -> None:
        script = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("set -euo pipefail", script)
        self.assertIn("php -m | grep -iE '^pcov$' >/dev/null", script)
        self.assertNotRegex(script, r"php -m\s*\|\s*grep\s+[^\n]*-q")

    def test_invalid_explicit_target_is_rejected(self) -> None:
        result = self.run_dry("not-a-fqcn", check=False)

        self.assertEqual(2, result.returncode)
        self.assertIn("无效 mutation class", result.stderr)

    def test_class_and_path_counts_must_match(self) -> None:
        result = self.run_dry(
            "App\\Models\\Fund,App\\Models\\Transaction",
            target_paths="app/Models/Fund.php",
            check=False,
        )

        self.assertEqual(2, result.returncode)
        self.assertIn("mutation class 与 path 数量不一致", result.stderr)

    def test_isolated_wrapper_does_not_forward_threshold_or_dry_run_ambient(self) -> None:
        wrapper = (
            ROOT / "skills" / "scripts" / "run-isolated-mutation.sh"
        ).read_text(encoding="utf-8")
        self.assertIn("-e MUTATE_MIN_MSI=0", wrapper)
        self.assertNotIn(
            "MUTATE_MIN_MSI=${MUTATE_MIN_MSI",
            wrapper,
        )
        self.assertNotIn('MUTATE_DRY_RUN=${MUTATE_DRY_RUN', wrapper)
        self.assertIn(
            '-e "MUTATE_TARGET_CLASSES=${MUTATE_TARGET_CLASSES:-}"',
            wrapper,
        )
        self.assertIn(
            '-e "MUTATE_TARGET_PATHS=${MUTATE_TARGET_PATHS:-}"',
            wrapper,
        )
        self.assertIn('-e "MUTATE_DRY_RUN=$DRY_RUN"', wrapper)
        self.assertIn(
            '-v "$PEST_MUTATIONS:/var/www/vendor/pestphp/'
            'pest-plugin-mutate/.temp/mutations"',
            wrapper,
        )
        self.assertIn(
            '-v "$PEST_MUTATE_CACHE:/var/www/vendor/pestphp/'
            'pest-plugin-mutate/.temp/pest-mutate-cache"',
            wrapper,
        )
        self.assertIn(
            '-v "$PEST_TEMP:/var/www/vendor/pestphp/pest/.temp"',
            wrapper,
        )
        self.assertIn('-v "$PLUGINS_SNAPSHOT:/var/plugins"', wrapper)
        self.assertNotIn('-v "$PROJECT_ROOT/plugins:/var/plugins:ro"', wrapper)
        self.assertIn('-e "DB_HOST=$DB_CONTAINER_NAME"', wrapper)
        self.assertIn('-e DB_DATABASE=ssl_manager_test', wrapper)
        self.assertIn(
            "php artisan migrate --force --no-interaction",
            wrapper,
        )
        self.assertIn("MutationTimeoutBudgetTest.php", wrapper)
        self.assertIn("sleep(90)", wrapper)
        self.assertIn(
            "--testsuite=MutationScope --profile",
            wrapper,
        )
        self.assertIn("-e COLUMNS=200", wrapper)

    def test_mutation_mysql_is_profiled_tmpfs_and_keeps_database_semantics(self) -> None:
        compose = (ROOT / "compose.yaml").read_text(encoding="utf-8")
        service = compose.split("  mutation-mysql:\n", 1)[1].split(
            "\n  redis:\n",
            1,
        )[0]

        self.assertIn('profiles: ["tools"]', service)
        self.assertIn("/var/lib/mysql:size=3g,mode=1777", service)
        self.assertIn("--skip-log-bin", service)
        self.assertIn("--innodb-flush-log-at-trx-commit=2", service)
        self.assertIn("--innodb-doublewrite=OFF", service)
        self.assertNotIn("--skip-innodb", service)
        self.assertNotIn("--foreign-key-checks=0", service)

    def test_isolated_wrapper_starts_waits_for_and_cleans_mutation_mysql(self) -> None:
        wrapper = (
            ROOT / "skills" / "scripts" / "run-isolated-mutation.sh"
        ).read_text(encoding="utf-8")

        start_position = wrapper.index('mutation-mysql >/dev/null')
        ready_position = wrapper.index('mysqladmin ping', start_position)
        app_position = wrapper.index('docker_args=(', ready_position)
        cleanup_position = wrapper.index(
            'docker rm -f "$DB_CONTAINER_NAME"',
        )

        self.assertLess(start_position, ready_position)
        self.assertLess(ready_position, app_position)
        self.assertLess(cleanup_position, start_position)

    def test_isolated_workspace_preserves_tracked_storage_gitignores(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            fake_docker = Path(temp_dir) / "docker"
            fake_docker.write_text(
                "#!/bin/sh\n"
                'if [ "$1" = "inspect" ]; then\n'
                "    exit 1\n"
                "fi\n"
                "exit 0\n",
                encoding="utf-8",
            )
            fake_docker.chmod(0o755)
            env = os.environ.copy()
            env["PATH"] = f"{temp_dir}:{env['PATH']}"
            env["MUTATION_KEEP_WORKSPACE"] = "1"

            result = subprocess.run(
                [
                    "bash",
                    str(ROOT / "skills/scripts/run-isolated-mutation.sh"),
                    "--dry-run",
                ],
                cwd=ROOT,
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )

            match = re.search(
                r"Mutation workspace retained: (.+)",
                result.stderr,
            )
            self.assertIsNotNone(match, result.stderr)
            workspace = Path(match.group(1))
            self.addCleanup(shutil.rmtree, workspace, True)

            self.assertEqual(
                (
                    workspace
                    / "backend/storage/framework/views/.gitignore"
                ).read_text(encoding="utf-8"),
                "*\n!.gitignore\n",
            )
            self.assertTrue(
                (workspace / "pest-mutate-temp/mutations").is_dir()
            )

    def test_isolated_wrapper_mounts_mutation_plugin_temp_writable(self) -> None:
        cache_root = ROOT / ".superpowers" / "mutation-pest-cache"
        cache_root.mkdir(parents=True, exist_ok=True)
        with (
            tempfile.TemporaryDirectory() as temp_dir,
            tempfile.TemporaryDirectory(dir=cache_root) as cache_temp_dir,
        ):
            fake_docker = Path(temp_dir) / "docker"
            docker_args_log = Path(temp_dir) / "docker-args.log"
            fake_docker.write_text(
                "#!/bin/sh\n"
                'printf "%s\\n" "$@" >> "$DOCKER_ARGS_LOG"\n'
                'if [ "$1" = "inspect" ]; then\n'
                "    exit 1\n"
                "fi\n"
                "exit 0\n",
                encoding="utf-8",
            )
            fake_docker.chmod(0o755)
            env = os.environ.copy()
            env["PATH"] = f"{temp_dir}:{env['PATH']}"
            env["DOCKER_ARGS_LOG"] = str(docker_args_log)
            persistent_cache = Path(cache_temp_dir) / "pest-mutate-cache"
            env["MUTATION_PEST_CACHE_DIR"] = str(persistent_cache)

            subprocess.run(
                [
                    "bash",
                    str(ROOT / "skills/scripts/run-isolated-mutation.sh"),
                    "--dry-run",
                ],
                cwd=ROOT,
                env=env,
                check=True,
                capture_output=True,
                text=True,
            )

            docker_args = docker_args_log.read_text(encoding="utf-8")
            self.assertRegex(
                docker_args,
                re.compile(
                    r"/pest-mutate-temp/mutations:"
                    r"/var/www/vendor/pestphp/"
                    r"pest-plugin-mutate/\.temp/mutations"
                ),
            )
            self.assertRegex(
                docker_args,
                re.compile(
                    re.escape(str(persistent_cache))
                    + r":/var/www/vendor/pestphp/"
                    r"pest-plugin-mutate/\.temp/pest-mutate-cache"
                ),
            )
            self.assertRegex(
                docker_args,
                re.compile(
                    r"/pest-temp:/var/www/vendor/pestphp/pest/\.temp"
                ),
            )
            self.assertEqual(
                0o700,
                persistent_cache.stat().st_mode & 0o777,
            )

    def test_isolated_wrapper_rejects_persistent_cache_outside_project(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            env = os.environ.copy()
            env["MUTATION_PEST_CACHE_DIR"] = str(Path(temp_dir) / "cache")

            result = subprocess.run(
                [
                    "bash",
                    str(ROOT / "skills/scripts/run-isolated-mutation.sh"),
                    "--dry-run",
                ],
                cwd=ROOT,
                env=env,
                check=False,
                capture_output=True,
                text=True,
            )

        self.assertEqual(2, result.returncode)
        self.assertIn("必须位于项目", result.stderr)

    def test_isolated_wrapper_waits_for_docker_client_before_removing_container(
        self,
    ) -> None:
        wrapper = (
            ROOT / "skills" / "scripts" / "run-isolated-mutation.sh"
        ).read_text(encoding="utf-8")
        kill_position = wrapper.index('kill "$DOCKER_CLIENT_PID"')
        wait_position = wrapper.index('wait "$DOCKER_CLIENT_PID"', kill_position)
        remove_position = wrapper.index(
            'docker rm -f "$CONTAINER_NAME"',
            wait_position,
        )

        self.assertLess(kill_position, wait_position)
        self.assertLess(wait_position, remove_position)


if __name__ == "__main__":
    unittest.main()
