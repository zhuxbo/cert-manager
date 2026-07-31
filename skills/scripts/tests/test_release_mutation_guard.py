from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[3]
RELEASE_SCRIPT = ROOT / "build" / "release.sh"


class ReleaseMutationGuardTest(unittest.TestCase):
    def test_main_release_verifies_full_mutation_before_tagging(self) -> None:
        script = RELEASE_SCRIPT.read_text(encoding="utf-8")

        guard_start = script.index("verify_main_mutation_evidence()")
        guard_end = script.index(
            "# ========================================",
            guard_start + 1,
        )
        guard = script[guard_start:guard_end]

        self.assertIn(
            '.superpowers/finish-check-runs/main-release-$version',
            guard,
        )
        self.assertIn("finish-check-exec.py verify", guard)
        self.assertIn("--require mutation", guard)
        self.assertNotIn("MUTATE_TARGET_CLASSES", guard)

        call = script.index('verify_main_mutation_evidence "$version"')
        version_tag = script.index('ensure_tag "v$version"')
        latest_tag = script.index('ensure_tag "latest"')
        self.assertLess(call, version_tag)
        self.assertLess(call, latest_tag)


if __name__ == "__main__":
    unittest.main()
