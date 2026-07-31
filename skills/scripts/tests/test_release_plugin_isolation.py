from __future__ import annotations

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path


REPO = Path(__file__).resolve().parents[3]
SCRIPT = REPO / "plugins" / "release-plugin.sh"


class ReleasePluginIsolationTest(unittest.TestCase):
    def test_frontend_build_uses_explicit_isolated_allowlist(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            plugin = root / "fixture-plugin"
            frontend = plugin / "frontend" / "admin"
            shared = plugin / "frontend" / "shared"
            fake_bin = root / "bin"
            frontend.mkdir(parents=True)
            shared.mkdir(parents=True)
            fake_bin.mkdir()
            (shared / "Shared.vue").write_text("<template />\n", encoding="utf-8")

            (plugin / "plugin.json").write_text(
                json.dumps({"name": "fixture-plugin", "requires": "1.0.0"}),
                encoding="utf-8",
            )
            (plugin / "build.json").write_text(
                json.dumps(
                    {
                        "include": ["plugin.json"],
                        "exclude": [],
                    },
                    indent=2,
                ),
                encoding="utf-8",
            )
            (frontend / "package.json").write_text(
                json.dumps({"name": "fixture-admin", "scripts": {"build": "fake"}}),
                encoding="utf-8",
            )
            (frontend / "pnpm-lock.yaml").write_text(
                "lockfileVersion: '9.0'\n",
                encoding="utf-8",
            )

            log = root / "pnpm.log"
            fake_pnpm = fake_bin / "pnpm"
            fake_pnpm.write_text(
                """#!/usr/bin/env bash
set -euo pipefail
test -f pnpm-workspace.yaml
grep -qx "  '@parcel/watcher': true" pnpm-workspace.yaml
grep -qx '  esbuild: true' pnpm-workspace.yaml
grep -qx '  vue-demi: true' pnpm-workspace.yaml
if grep -q 'dangerouslyAllowAllBuilds' pnpm-workspace.yaml; then exit 91; fi
test -f ../shared/Shared.vue
printf '%s|%s\\n' "$PWD" "$*" >>"$FAKE_PNPM_LOG"
if [[ "$1" == "install" ]]; then
    mkdir -p node_modules
fi
if [[ "$1" == "build" ]]; then
    test -L ../node_modules
    mkdir -p dist
    printf 'fixture' >dist/app.js
fi
""",
                encoding="utf-8",
            )
            fake_pnpm.chmod(0o755)

            environment = {
                **os.environ,
                "PATH": f"{fake_bin}:{os.environ['PATH']}",
                "FAKE_PNPM_LOG": str(log),
                "PNPM_ALLOW_NETWORK_FALLBACK": "0",
            }
            result = subprocess.run(
                [
                    "bash",
                    str(SCRIPT),
                    str(plugin),
                    "--version",
                    "0.0.0-test",
                    "--build-only",
                ],
                cwd=REPO,
                env=environment,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
            )
            self.assertEqual(0, result.returncode, result.stdout)
            self.assertFalse((frontend / "pnpm-workspace.yaml").exists())
            self.assertFalse((frontend / "node_modules").exists())
            self.assertEqual("fixture", (frontend / "dist" / "app.js").read_text())
            for line in log.read_text(encoding="utf-8").splitlines():
                working_dir, _args = line.split("|", 1)
                self.assertNotEqual(frontend, Path(working_dir))

            output = (
                REPO
                / "plugins"
                / "temp"
                / "fixture-plugin-plugin-0.0.0-test.zip"
            )
            output.unlink(missing_ok=True)

        self.assertNotIn(
            "dangerouslyAllowAllBuilds",
            SCRIPT.read_text(encoding="utf-8"),
        )


if __name__ == "__main__":
    unittest.main()
