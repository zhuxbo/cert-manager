#!/usr/bin/env python3
"""收集 finish-check 的统一变更清单，并只读检查实际改动文件。"""

from __future__ import annotations

import argparse
import json
import os
import py_compile
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


def changed_files(repo: Path, base: str | None = None) -> list[str]:
    commands = [["git", "diff", "--name-only", "-z", base or "HEAD", "--"]]
    if base is None:
        commands += [
            ["git", "diff", "--cached", "--name-only", "-z", "--"],
            ["git", "ls-files", "--others", "--exclude-standard", "-z"],
        ]
    files: set[str] = set()
    for command in commands:
        result = subprocess.run(command, cwd=repo, check=True, stdout=subprocess.PIPE)
        files.update(os.fsdecode(item) for item in result.stdout.split(b"\0") if item)
    return sorted(files)


def check_files(repo: Path, files: list[str], kind: str, base: str | None = None) -> None:
    subprocess.run(["git", "diff", "--check", base or "HEAD", "--"], cwd=repo, check=True)
    if base is None:
        subprocess.run(["git", "diff", "--cached", "--check", "--"], cwd=repo, check=True)
    groups = {
        suffix: [name for name in files if name.endswith(suffix) and (repo / name).is_file()]
        for suffix in (".md", ".sh", ".py", ".json")
    }
    if kind in ("all", "markdown") and groups[".md"]:
        prettier = repo / "frontend/admin/node_modules/.bin/prettier"
        if not prettier.is_file():
            raise RuntimeError("缺少项目 Prettier，请先安装前端依赖")
        subprocess.run([str(prettier), "--check", "--", *groups[".md"]], cwd=repo, check=True)
    if kind in ("all", "shell") and groups[".sh"]:
        shfmt = shutil.which("shfmt")
        if shfmt is None:
            raise RuntimeError("缺少 shfmt，请安装后重跑；本检查不会自动安装")
        for name in groups[".sh"]:
            subprocess.run(["bash", "-n", "--", name], cwd=repo, check=True)
        subprocess.run([shfmt, "-d", "-i", "4", "-ci", "--", *groups[".sh"]], cwd=repo, check=True)
    if kind == "all":
        with tempfile.TemporaryDirectory(prefix="finish-check-pycompile-") as temporary:
            for index, name in enumerate(groups[".py"]):
                py_compile.compile(str(repo / name), cfile=f"{temporary}/{index}.pyc", doraise=True)
        for name in groups[".json"]:
            with (repo / name).open(encoding="utf-8") as handle:
                json.load(handle)
    print(f"FILE_CHECKS_PASS kind={kind}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["list", "docs-only", "check"])
    parser.add_argument("--base")
    parser.add_argument("--suffix", action="append", default=[])
    parser.add_argument("--null", action="store_true")
    parser.add_argument("--kind", choices=["all", "markdown", "shell"], default="all")
    args = parser.parse_args()
    repo = Path(subprocess.check_output(["git", "rev-parse", "--show-toplevel"], text=True).strip())
    files = changed_files(repo, args.base)
    if args.command == "docs-only":
        return 0 if files and all(name.endswith(".md") for name in files) else 1
    if args.command == "check":
        check_files(repo, files, args.kind, args.base)
    else:
        separator = "\0" if args.null else "\n"
        selected = [name for name in files if not args.suffix or any(name.endswith(suffix) for suffix in args.suffix)]
        if selected:
            sys.stdout.write(separator.join(selected) + separator)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (subprocess.CalledProcessError, RuntimeError, OSError, ValueError, py_compile.PyCompileError) as error:
        print(f"FILE_CHECKS_FAIL: {error}", file=sys.stderr)
        raise SystemExit(1)
