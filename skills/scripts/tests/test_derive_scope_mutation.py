from __future__ import annotations

import subprocess
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "derive-scope.sh"
CORE_TARGETS = {
    "backend/app/Models/Fund.php": r"App\Models\Fund",
    "backend/app/Models/Transaction.php": r"App\Models\Transaction",
    "backend/app/Services/Acme/Action.php": r"App\Services\Acme\Action",
    "backend/app/Services/Order/Action.php": r"App\Services\Order\Action",
    "backend/app/Services/Order/AutoRenewService.php": (
        r"App\Services\Order\AutoRenewService"
    ),
    "backend/app/Services/FundAudit/FundInvariants.php": (
        r"App\Services\FundAudit\FundInvariants"
    ),
}


class DeriveScopeMutationTest(unittest.TestCase):
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
        (self.repo / "README.md").write_text("fixture\n", encoding="utf-8")
        for relative in CORE_TARGETS:
            path = self.repo / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("<?php\n// base\n", encoding="utf-8")
        subprocess.run(["git", "add", "."], cwd=self.repo, check=True)
        subprocess.run(["git", "commit", "-qm", "init"], cwd=self.repo, check=True)

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def run_scope(self, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["bash", str(SCRIPT), *args],
            cwd=self.repo,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )

    def test_unrelated_change_does_not_require_mutation(self) -> None:
        (self.repo / "README.md").write_text("changed\n", encoding="utf-8")

        result = self.run_scope()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("MUTATION_REQUIRED=no", result.stdout)
        self.assertIn("MUTATION_RUN=（不运行）", result.stdout)

    def test_changed_core_classes_generate_targeted_gate_commands(self) -> None:
        for relative in CORE_TARGETS:
            (self.repo / relative).write_text(
                "<?php\n// changed\n",
                encoding="utf-8",
            )

        result = self.run_scope()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("MUTATION_REQUIRED=yes", result.stdout)
        targets = ",".join(CORE_TARGETS.values())
        self.assertIn(f"MUTATION_AUTO_TARGETS={targets}", result.stdout)
        self.assertIn(
            f"MUTATE_TARGET_CLASSES={targets}",
            result.stdout,
        )
        self.assertIn(
            f"mutation:MUTATE_TARGET_CLASSES={targets}",
            result.stdout,
        )

    def test_plan_targets_are_deduplicated_and_trigger_mutation(self) -> None:
        target = r"App\Services\Order\Traits\ActionFileTrait"

        result = self.run_scope(
            "--mutation-target-class",
            target,
            "--mutation-target-class",
            target,
        )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("MUTATION_REQUIRED=yes", result.stdout)
        self.assertIn(f"MUTATION_PLAN_TARGETS={target},{target}", result.stdout)
        self.assertIn(f"MUTATION_EFFECTIVE_TARGETS={target}", result.stdout)

    def test_invalid_plan_target_is_rejected(self) -> None:
        result = self.run_scope("--mutation-target-class", "not-a-fqcn")

        self.assertNotEqual(0, result.returncode)
        self.assertIn("无效 mutation FQCN", result.stderr)


if __name__ == "__main__":
    unittest.main()
