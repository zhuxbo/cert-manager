#!/usr/bin/env python3
"""按精确文件运行 mutation 分片，缓存完整分片证据并按 mutant 数加权汇总。"""

from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import decimal
import fcntl
import fnmatch
import hashlib
import json
import math
import os
import re
import stat
import subprocess
import sys
import tempfile
import time
from collections import Counter
from pathlib import Path
from typing import Dict, Iterable, Iterator, List, Mapping, Optional, TextIO, Tuple


ROOT = Path(__file__).resolve().parents[2]
REGISTRY_PATH = ROOT / "skills" / "mutation-shards.json"
BASELINE_PATH = ROOT / "backend" / "tests" / ".mutation-baseline.json"
DEFAULT_CACHE_DIR = ROOT / ".superpowers" / "mutation-shard-cache" / "v1"
DEFAULT_PEST_CACHE_DIR = ROOT / ".superpowers" / "mutation-pest-cache" / "v1"
WRAPPER = ROOT / "skills" / "scripts" / "run-isolated-mutation.sh"
ANSI_RE = re.compile(r"\x1b\[[0-?]*[ -/]*[@-~]")
FQCN_RE = re.compile(r"^App(?:\\[A-Z][A-Za-z0-9_]*)+$")
COUNT_LABELS = {
    "untested": "untested",
    "uncovered": "uncovered",
    "pending": "pending",
    "timeout": "timeout",
    "tested": "tested",
}
SURVIVOR_RE = re.compile(
    r"(?m)^\s*(UNTESTED|UNCOVERED)\s+(\S+)\s+>\s+Line\s+(\d+):\s+"
    r"([A-Za-z0-9_\\]+)\s+-\s+ID:\s+([a-f0-9]+)\s*$",
    re.IGNORECASE,
)
PROFILE_RE = re.compile(
    r"(?m)^\s*(\S+)\s+>\s+Line\s+(\d+):\s+"
    r"([A-Za-z0-9_\\]+)\s+-\s+ID:\s+([a-f0-9]+)\s+"
    r"([0-9][0-9,]*(?:\.[0-9]+)?)s\s*$",
    re.IGNORECASE,
)
SKIP_DIR_NAMES = {
    ".git",
    "node_modules",
    "vendor",
    "storage",
    "dist",
    "temp",
    "__pycache__",
}
COMMON_TEST_PATTERNS = (
    "backend/tests/Pest.php",
    "backend/tests/TestCase.php",
    "backend/tests/Support/**/*.php",
    "backend/tests/Traits/**/*.php",
)


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat()


def ensure_private_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)
    path.chmod(0o700)


def atomic_json_write(path: Path, payload: Mapping[str, object]) -> None:
    ensure_private_dir(path.parent)
    fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write("\n")
        os.replace(temp_name, path)
        path.chmod(0o600)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


@contextlib.contextmanager
def private_lock(path: Path) -> Iterator[TextIO]:
    ensure_private_dir(path.parent)
    with path.open("a+", encoding="utf-8") as lock:
        path.chmod(0o600)
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        yield lock


def strip_ansi(value: str) -> str:
    return ANSI_RE.sub("", value).replace("\r", "")


def parse_mutation_output(output: str) -> Dict[str, object]:
    plain = strip_ansi(output)
    generated_matches = list(re.finditer(
        r"(?m)^\s*(\d+)\s+Mutations for\s+(\d+)\s+Files created\s*$",
        plain,
    ))
    attempt = plain[generated_matches[-1].start():] if generated_matches else plain
    summary_matches = re.findall(r"(?m)^\s*Mutations:\s*(.+?)\s*$", attempt)
    score_matches = re.findall(
        r"(?m)^\s*Score:\s*([0-9]+(?:\.[0-9]+)?)%",
        attempt,
    )
    duration_matches = re.findall(
        r"(?m)^\s*Duration:\s*([0-9][0-9,]*(?:\.[0-9]+)?)s\s*$",
        attempt,
    )
    if (
        not generated_matches
        or not summary_matches
        or not score_matches
        or not duration_matches
    ):
        raise ValueError("mutation 输出缺少完整汇总")

    counts: Dict[str, int] = {key: 0 for key in COUNT_LABELS}
    for key, label in COUNT_LABELS.items():
        match = re.search(rf"(\d+)\s+{label}\b", summary_matches[-1])
        if match is not None:
            counts[key] = int(match.group(1))

    generated, files = (int(value) for value in generated_matches[-1].groups())
    if generated <= 0:
        raise ValueError("mutation 分片没有生成 mutant")
    if counts["pending"] != 0:
        raise ValueError("mutation 分片仍有 pending，不能缓存")
    if sum(counts.values()) != generated:
        raise ValueError("mutation 汇总数量与生成数量不一致")
    score = float(score_matches[-1])
    expected_score = (counts["tested"] + counts["timeout"]) / generated * 100
    if abs(score - expected_score) > 0.011:
        raise ValueError("mutation Score 与计数不一致")

    survivors = [
        {
            "outcome": match.group(1).lower(),
            "path": match.group(2),
            "line": int(match.group(3)),
            "mutator": match.group(4),
            "id": match.group(5),
        }
        for match in SURVIVOR_RE.finditer(attempt)
    ]
    expected_survivors = counts["untested"] + counts["uncovered"]
    if len(survivors) != expected_survivors:
        raise ValueError(
            "mutation survivor 明细数量与 untested + uncovered 不一致"
        )

    slow_mutations = [
        {
            "path": match.group(1),
            "line": int(match.group(2)),
            "mutator": match.group(3),
            "id": match.group(4),
            "seconds": float(match.group(5).replace(",", "")),
        }
        for match in PROFILE_RE.finditer(attempt)
    ]

    return {
        "generated": generated,
        "files": files,
        **counts,
        "score": score,
        "duration_seconds": float(duration_matches[-1].replace(",", "")),
        "survivors": survivors,
        "slow_mutations": slow_mutations,
    }


def aggregate_results(
    results: Iterable[Mapping[str, object]],
) -> Dict[str, object]:
    totals = {
        "generated": 0,
        "untested": 0,
        "uncovered": 0,
        "pending": 0,
        "timeout": 0,
        "tested": 0,
    }
    for result in results:
        for key in totals:
            totals[key] += int(result.get(key, 0))
    killed = totals["tested"]
    score = (
        round(killed / totals["generated"] * 100, 2)
        if totals["generated"]
        else 0.0
    )
    return {**totals, "killed": killed, "score": score}


def aggregate_meets_threshold(
    *,
    killed: int,
    generated: int,
    min_msi: float,
) -> bool:
    if generated <= 0:
        return False
    threshold = decimal.Decimal(str(min_msi))
    return (
        decimal.Decimal(killed) * decimal.Decimal(100)
        >= threshold * decimal.Decimal(generated)
    )


def required_additional_kills(
    *,
    killed: int,
    generated: int,
    min_msi: float,
) -> int:
    if generated <= 0:
        return 0
    required = (
        decimal.Decimal(str(min_msi))
        * decimal.Decimal(generated)
        / decimal.Decimal(100)
    ).to_integral_value(rounding=decimal.ROUND_CEILING)
    return max(0, int(required) - killed)


def ranked_counts(values: Iterable[str]) -> List[Dict[str, object]]:
    counts = Counter(values)
    return [
        {"key": key, "count": count}
        for key, count in sorted(
            counts.items(),
            key=lambda item: (-item[1], item[0]),
        )
    ]


def method_for_survivor(path: str, line: int) -> str:
    source = ROOT / "backend" / path
    try:
        lines = source.read_text(encoding="utf-8").splitlines()
    except OSError:
        return "<unknown>"
    method = "<class>"
    for source_line in lines[:line]:
        match = re.search(
            r"\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(",
            source_line,
        )
        if match is not None:
            method = match.group(1)
    return method


def build_inspection(
    aggregate: Mapping[str, object],
    survivor_details: Iterable[Mapping[str, object]],
    *,
    min_msi: float,
    slow_mutations: Iterable[Mapping[str, object]] = (),
) -> Dict[str, object]:
    generated = int(aggregate.get("generated", 0))
    killed = int(
        aggregate.get(
            "killed",
            int(aggregate.get("tested", 0)) + int(aggregate.get("timeout", 0)),
        )
    )
    survivors = int(aggregate.get("untested", 0)) + int(
        aggregate.get("uncovered", 0)
    )
    deduplicated: Dict[str, Dict[str, object]] = {}
    for raw in survivor_details:
        mutation_id = str(raw.get("id", ""))
        if mutation_id:
            deduplicated[mutation_id] = dict(raw)
    details = list(deduplicated.values())
    if len(details) > survivors:
        raise ValueError("结构化 survivor 明细多于 mutation 汇总")

    methods = []
    for item in details:
        path = str(item["path"])
        method = method_for_survivor(path, int(item["line"]))
        methods.append(f"{path}::{method}")

    return {
        "generated": generated,
        "killed": killed,
        "survivors": survivors,
        "score": float(aggregate.get("score", 0.0)),
        "min_msi": min_msi,
        "required_additional_kills": required_additional_kills(
            killed=killed,
            generated=generated,
            min_msi=min_msi,
        ),
        "structured_survivors": len(details),
        "missing_survivor_details": survivors - len(details),
        "survivor_details": details,
        "hotspots": {
            "paths": ranked_counts(str(item["path"]) for item in details),
            "methods": ranked_counts(methods),
            "mutators": ranked_counts(str(item["mutator"]) for item in details),
        },
        "slow_mutations": list(slow_mutations),
    }


def inspect_run_log(output: str) -> Dict[str, object]:
    plain = strip_ansi(output)
    aggregate_matches = re.findall(
        r"(?m)^MUTATION_AGGREGATE_JSON=(\{.*\})\s*$",
        plain,
    )
    if not aggregate_matches:
        raise ValueError("日志缺少 MUTATION_AGGREGATE_JSON")
    try:
        evidence = json.loads(aggregate_matches[-1])
        aggregate = evidence["result"]
        min_msi = float(evidence["min_msi"])
    except (json.JSONDecodeError, KeyError, TypeError, ValueError) as exc:
        raise ValueError("日志中的 mutation 聚合证据无效") from exc
    if not isinstance(aggregate, dict):
        raise ValueError("日志中的 mutation 聚合结果无效")

    survivors = [
        {
            "outcome": match.group(1).lower(),
            "path": match.group(2),
            "line": int(match.group(3)),
            "mutator": match.group(4),
            "id": match.group(5),
        }
        for match in SURVIVOR_RE.finditer(plain)
    ]
    slow_mutations = [
        {
            "path": match.group(1),
            "line": int(match.group(2)),
            "mutator": match.group(3),
            "id": match.group(4),
            "seconds": float(match.group(5).replace(",", "")),
        }
        for match in PROFILE_RE.finditer(plain)
    ]
    return build_inspection(
        aggregate,
        survivors,
        min_msi=min_msi,
        slow_mutations=slow_mutations,
    )


def result_is_complete(result: object) -> bool:
    if not isinstance(result, dict):
        return False
    try:
        files = int(result["files"])
        generated = int(result["generated"])
        counts = [
            int(result[key])
            for key in ("untested", "uncovered", "pending", "timeout", "tested")
        ]
        score = float(result["score"])
        duration = float(result["duration_seconds"])
        survivors = result["survivors"]
        slow_mutations = result["slow_mutations"]
    except (KeyError, TypeError, ValueError):
        return False
    if (
        generated <= 0
        or not isinstance(survivors, list)
        or not isinstance(slow_mutations, list)
    ):
        return False
    if len(survivors) != counts[0] + counts[1]:
        return False
    survivor_outcomes = Counter()
    survivor_ids = set()
    for survivor in survivors:
        if not isinstance(survivor, dict):
            return False
        try:
            outcome = str(survivor["outcome"])
            path = str(survivor["path"])
            line = int(survivor["line"])
            mutator = str(survivor["mutator"])
            mutation_id = str(survivor["id"])
        except (KeyError, TypeError, ValueError):
            return False
        if (
            outcome not in ("untested", "uncovered")
            or not path.startswith("app/")
            or line <= 0
            or not mutator
            or re.fullmatch(r"[a-f0-9]{16}", mutation_id) is None
            or mutation_id in survivor_ids
        ):
            return False
        survivor_outcomes[outcome] += 1
        survivor_ids.add(mutation_id)
    if (
        survivor_outcomes["untested"] != counts[0]
        or survivor_outcomes["uncovered"] != counts[1]
    ):
        return False
    for slow in slow_mutations:
        if not isinstance(slow, dict):
            return False
        try:
            slow_path = str(slow["path"])
            slow_line = int(slow["line"])
            slow_mutator = str(slow["mutator"])
            slow_id = str(slow["id"])
            seconds = float(slow["seconds"])
        except (KeyError, TypeError, ValueError):
            return False
        if (
            not slow_path.startswith("app/")
            or slow_line <= 0
            or not slow_mutator
            or re.fullmatch(r"[a-f0-9]{16}", slow_id) is None
            or not math.isfinite(seconds)
            or seconds < 0
        ):
            return False
    expected_score = counts[4] / generated * 100
    return (
        files == 1
        and generated > 0
        and all(count >= 0 for count in counts)
        and counts[2] == 0
        and counts[3] == 0
        and sum(counts) == generated
        and math.isfinite(score)
        and 0 <= score <= 100
        and abs(score - expected_score) <= 0.011
        and math.isfinite(duration)
        and duration >= 0
    )


def cache_reuse_decision(
    cached: object,
    current_inputs: Mapping[str, object],
) -> Tuple[bool, str]:
    if not isinstance(cached, dict) or cached.get("schema_version") != 1:
        return False, "缓存格式无效"
    if not result_is_complete(cached.get("result")):
        return False, "缓存证据不完整"
    cached_inputs = cached.get("inputs")
    if not isinstance(cached_inputs, dict):
        return False, "缓存输入无效"
    if cached_inputs.get("common") != current_inputs.get("common"):
        return False, "共享输入变化"
    if cached_inputs.get("scope") != current_inputs.get("scope"):
        return False, "分片定义变化"
    if cached_inputs.get("dependencies") != current_inputs.get("dependencies"):
        return False, "分片依赖变化"

    cached_tests = cached_inputs.get("tests")
    current_tests = current_inputs.get("tests")
    if not isinstance(cached_tests, dict) or not isinstance(current_tests, dict):
        return False, "测试指纹无效"
    for path, digest in cached_tests.items():
        if current_tests.get(path) != digest:
            return False, "既有测试变化或删除"
    new_tests = set(current_tests) - set(cached_tests)
    if new_tests:
        result = cached["result"]
        survivors = int(result.get("untested", 0)) + int(
            result.get("uncovered", 0)
        )
        if survivors:
            return False, "新增测试可能杀死 survivor"
        return True, "仅新增测试且缓存分片无 survivor"
    return True, "命中"


def select_relevant_test_manifest(
    all_tests: Mapping[str, str],
    patterns: Iterable[str],
) -> Dict[str, str]:
    normalized_patterns = tuple(patterns)
    return {
        path: digest
        for path, digest in all_tests.items()
        if any(glob_matches(path, pattern) for pattern in normalized_patterns)
    }


def glob_matches(path: str, pattern: str) -> bool:
    candidates = {pattern}
    pending = [pattern]
    while pending:
        candidate = pending.pop()
        start = candidate.find("**/")
        if start < 0:
            continue
        without_recursive_directory = (
            candidate[:start] + candidate[start + 3:]
        )
        if without_recursive_directory not in candidates:
            candidates.add(without_recursive_directory)
            pending.append(without_recursive_directory)
    return any(fnmatch.fnmatchcase(path, candidate) for candidate in candidates)


def file_digest(path: Path) -> str:
    digest = hashlib.sha256()
    info = path.lstat()
    digest.update(oct(stat.S_IMODE(info.st_mode)).encode())
    digest.update(b"\0")
    if path.is_symlink():
        digest.update(os.readlink(path).encode("utf-8", "surrogateescape"))
    else:
        with path.open("rb") as handle:
            while True:
                chunk = handle.read(1024 * 1024)
                if not chunk:
                    break
                digest.update(chunk)
    return digest.hexdigest()


def manifest_digest(items: Mapping[str, str]) -> str:
    digest = hashlib.sha256()
    for path in sorted(items):
        digest.update(path.encode("utf-8", "surrogateescape"))
        digest.update(b"\0")
        digest.update(items[path].encode())
        digest.update(b"\0")
    return digest.hexdigest()


def collect_files(
    roots: Iterable[Path],
    *,
    excluded: Iterable[Path] = (),
) -> Dict[str, str]:
    excluded_resolved = {path.resolve() for path in excluded}
    result: Dict[str, str] = {}
    for root in roots:
        if not root.exists():
            continue
        if root.is_file() or root.is_symlink():
            candidates = [root]
        else:
            candidates = []
            for directory, child_dirs, child_files in os.walk(root):
                directory_path = Path(directory)
                child_dirs[:] = [
                    name
                    for name in child_dirs
                    if name not in SKIP_DIR_NAMES
                    and (directory_path / name).resolve()
                    not in excluded_resolved
                    and not (
                        (directory_path / name)
                        .relative_to(ROOT)
                        .as_posix()
                        .startswith("backend/bootstrap/cache")
                    )
                ]
                candidates.extend(directory_path / name for name in child_files)
        for path in candidates:
            try:
                resolved = path.resolve()
                relative_parts = path.relative_to(ROOT).parts
            except (OSError, ValueError):
                continue
            if resolved in excluded_resolved or not (path.is_file() or path.is_symlink()):
                continue
            if any(part in SKIP_DIR_NAMES for part in relative_parts):
                continue
            if relative_parts[:3] == ("backend", "bootstrap", "cache"):
                continue
            relative = path.relative_to(ROOT).as_posix()
            result[relative] = file_digest(path)
    return result


def load_registry() -> List[Dict[str, object]]:
    try:
        payload = json.loads(REGISTRY_PATH.read_text(encoding="utf-8"))
        shards = payload["shards"]
    except (OSError, KeyError, json.JSONDecodeError, TypeError) as exc:
        raise SystemExit(f"无效 mutation 分片注册表：{REGISTRY_PATH}") from exc
    if payload.get("schema_version") != 1 or not isinstance(shards, list):
        raise SystemExit("mutation 分片注册表 schema_version 必须为 1")

    ids = set()
    classes = set()
    paths = set()
    normalized: List[Dict[str, object]] = []
    for raw in shards:
        if not isinstance(raw, dict):
            raise SystemExit("mutation 分片必须是对象")
        shard_id = str(raw.get("id", ""))
        class_name = str(raw.get("class", ""))
        relative_path = str(raw.get("path", ""))
        dependencies = raw.get("dependencies", [])
        test_patterns = raw.get("test_patterns", [])
        if (
            not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", shard_id)
            or FQCN_RE.fullmatch(class_name) is None
            or not relative_path.startswith("backend/app/")
            or not relative_path.endswith(".php")
            or not (ROOT / relative_path).is_file()
            or not isinstance(dependencies, list)
            or not isinstance(test_patterns, list)
            or not test_patterns
            or not all(
                isinstance(pattern, str)
                and pattern.startswith("backend/tests/")
                and pattern.endswith(".php")
                and ".." not in Path(pattern).parts
                for pattern in test_patterns
            )
        ):
            raise SystemExit(f"无效 mutation 分片：{raw!r}")
        if shard_id in ids or class_name in classes or relative_path in paths:
            raise SystemExit("mutation 分片 id、class、path 必须唯一")
        ids.add(shard_id)
        classes.add(class_name)
        paths.add(relative_path)
        normalized.append(
            {
                "id": shard_id,
                "class": class_name,
                "path": relative_path,
                "dependencies": [str(item) for item in dependencies],
                "test_patterns": [str(item) for item in test_patterns],
            }
        )
    for shard in normalized:
        unknown = set(shard["dependencies"]) - ids
        if unknown:
            raise SystemExit(
                f"mutation 分片 {shard['id']} 引用了未知依赖：{sorted(unknown)}"
            )
    return normalized


def extra_shard(class_name: str) -> Dict[str, object]:
    if FQCN_RE.fullmatch(class_name) is None:
        raise SystemExit(f"无效 mutation class：{class_name}")
    relative_class = class_name.removeprefix("App\\").replace("\\", "/")
    relative_path = f"backend/app/{relative_class}.php"
    if not (ROOT / relative_path).is_file():
        raise SystemExit(f"mutation class 找不到精确文件：{class_name}")
    suffix = hashlib.sha256(class_name.encode()).hexdigest()[:12]
    return {
        "id": f"extra-{suffix}",
        "class": class_name,
        "path": relative_path,
        "dependencies": [],
        "test_patterns": ["backend/tests/**/*.php"],
    }


def select_shards(
    registry: List[Dict[str, object]],
    target_classes: Optional[str],
) -> List[Dict[str, object]]:
    if target_classes is None or not target_classes.strip():
        return registry
    by_class = {str(item["class"]): item for item in registry}
    selected = []
    seen = set()
    for raw_class in target_classes.split(","):
        class_name = raw_class.strip()
        if class_name in seen:
            continue
        seen.add(class_name)
        selected.append(by_class.get(class_name) or extra_shard(class_name))
    if not selected:
        raise SystemExit("mutation 目标不能为空")
    return selected


def select_shard_by_id(
    registry: List[Dict[str, object]],
    shard_id: str,
) -> Dict[str, object]:
    for shard in registry:
        if shard["id"] == shard_id:
            return shard
    raise SystemExit(f"未知 mutation 分片：{shard_id}")


def build_inputs(
    registry: List[Dict[str, object]],
    shard: Mapping[str, object],
) -> Dict[str, object]:
    by_id = {str(item["id"]): item for item in registry}
    target_paths = {ROOT / str(item["path"]) for item in registry}
    target_paths.add(ROOT / str(shard["path"]))
    all_tests = {
        path: digest
        for path, digest in collect_files([ROOT / "backend" / "tests"]).items()
        if path.endswith(".php")
    }
    test_patterns = [
        *COMMON_TEST_PATTERNS,
        *(str(pattern) for pattern in shard.get("test_patterns", [])),
    ]
    tests = select_relevant_test_manifest(all_tests, test_patterns)
    dependency_paths = [ROOT / str(shard["path"])]
    for dependency_id in shard.get("dependencies", []):
        dependency_paths.append(ROOT / str(by_id[str(dependency_id)]["path"]))
    dependencies = collect_files(dependency_paths)

    common_roots = [
        ROOT / "backend",
        ROOT / "plugins",
        ROOT / "docker",
        ROOT / "skills" / "scripts" / "mutation-shards.py",
        ROOT / "skills" / "scripts" / "run-isolated-mutation.sh",
        ROOT / "docker-compose.yml",
        ROOT / "docker-compose.yaml",
        ROOT / "compose.yml",
        ROOT / "compose.yaml",
    ]
    common_excluded = set(target_paths)
    common_excluded.add(ROOT / "backend" / "tests")
    common = collect_files(common_roots, excluded=common_excluded)
    # collect_files 的 excluded 对目录只排除目录节点；显式剔除测试树。
    common = {
        path: digest
        for path, digest in common.items()
        if not path.startswith("backend/tests/")
    }
    return {
        "common": manifest_digest(common),
        "scope": hashlib.sha256(
            json.dumps(shard, ensure_ascii=False, sort_keys=True).encode("utf-8")
        ).hexdigest(),
        "dependencies": manifest_digest(dependencies),
        "tests": tests,
    }


def load_cache(path: Path) -> object:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None


def run_shard(
    shard: Mapping[str, object],
    *,
    extra_args: Tuple[str, ...] = (),
    pest_cache_dir: Optional[Path] = None,
    formal_scope: bool = False,
) -> Tuple[int, str, float]:
    relative_path = str(shard["path"]).removeprefix("backend/")
    environment = os.environ.copy()
    environment["MUTATE_TARGET_CLASSES"] = str(shard["class"])
    environment["MUTATE_TARGET_PATHS"] = relative_path
    environment["MUTATION_FORMAL_SCOPE"] = "1" if formal_scope else "0"
    if pest_cache_dir is not None:
        ensure_private_dir(pest_cache_dir)
        environment["MUTATION_PEST_CACHE_DIR"] = str(pest_cache_dir)
    started = time.monotonic()
    process = subprocess.Popen(
        ["bash", str(WRAPPER), *extra_args],
        cwd=ROOT,
        env=environment,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
    )
    output_parts: List[str] = []
    assert process.stdout is not None
    try:
        for line in process.stdout:
            output_parts.append(line)
            print(line, end="", flush=True)
        return_code = process.wait()
    except KeyboardInterrupt:
        process.terminate()
        process.wait()
        raise
    return return_code, "".join(output_parts), time.monotonic() - started


def load_min_msi() -> float:
    try:
        value = json.loads(BASELINE_PATH.read_text(encoding="utf-8"))["min_msi"]
        return float(value)
    except (OSError, KeyError, TypeError, ValueError, json.JSONDecodeError) as exc:
        raise SystemExit(f"无效 mutation baseline：{BASELINE_PATH}") from exc


def formal_test_args(inputs: Mapping[str, object]) -> Tuple[str, ...]:
    tests = inputs.get("tests")
    if not isinstance(tests, dict):
        raise ValueError("正式 mutation 测试指纹无效")
    runnable = [
        path.removeprefix("backend/")
        for path in tests
        if isinstance(path, str)
        and path.endswith("Test.php")
        and path.startswith(("backend/tests/Feature/", "backend/tests/Unit/"))
    ]
    # 直接单元测试通常能在异常 mutant 进入控制器/并发路径前快速失败。
    # 全部相关测试仍会执行；这里只固定 Unit 在 Feature 之前，避免可杀死
    # mutant 被较慢的间接用例拖成 timeout。
    runnable.sort(key=lambda path: (not path.startswith("tests/Unit/"), path))
    if not runnable:
        raise ValueError("正式 mutation 分片没有可执行的相关测试")
    return tuple(runnable)


def command_run(args: argparse.Namespace) -> int:
    registry = load_registry()
    selected = select_shards(
        registry,
        os.environ.get("MUTATE_TARGET_CLASSES"),
    )
    cache_dir = Path(args.cache_dir).resolve()
    ensure_private_dir(cache_dir)
    lock_path = cache_dir / ".lock"
    with private_lock(lock_path):
        results = []
        rows = []
        for index, shard in enumerate(selected, start=1):
            shard_id = str(shard["id"])
            cache_path = cache_dir / f"{shard_id}.json"
            print(
                f"\n═══ Mutation shard {index}/{len(selected)}: "
                f"{shard['class']} ({shard['path']}) ═══",
                flush=True,
            )
            inputs = build_inputs(registry, shard)
            cached = load_cache(cache_path)
            reusable, reason = cache_reuse_decision(cached, inputs)
            if reusable:
                result = dict(cached["result"])
                source = "cache"
                print(f"CACHE_HIT {shard_id}: {reason}", flush=True)
                if reason == "仅新增测试且缓存分片无 survivor":
                    promoted = dict(cached)
                    promoted_inputs = dict(cached["inputs"])
                    promoted_inputs["tests"] = inputs["tests"]
                    promoted["inputs"] = promoted_inputs
                    promoted["tests_promoted_at"] = utc_now()
                    atomic_json_write(cache_path, promoted)
            else:
                print(f"CACHE_MISS {shard_id}: {reason}", flush=True)
                try:
                    test_args = formal_test_args(inputs)
                except ValueError as exc:
                    print(
                        f"mutation 分片 {shard_id} 测试范围无效：{exc}",
                        file=sys.stderr,
                    )
                    return 1
                return_code, output, wall_seconds = run_shard(
                    shard,
                    extra_args=test_args,
                    formal_scope=True,
                )
                if return_code != 0:
                    print(
                        f"mutation 分片 {shard_id} 执行失败，exit={return_code}",
                        file=sys.stderr,
                    )
                    return return_code or 1
                try:
                    result = parse_mutation_output(output)
                except ValueError as exc:
                    print(
                        f"mutation 分片 {shard_id} 证据无效：{exc}",
                        file=sys.stderr,
                    )
                    return 1
                if int(result["files"]) != 1:
                    print(
                        f"mutation 分片 {shard_id} 扩展到 "
                        f"{result['files']} 个文件，拒绝缓存",
                        file=sys.stderr,
                    )
                    return 1
                if not result_is_complete(result):
                    print(
                        f"mutation 分片 {shard_id} 证据不完整或含 timeout，"
                        "拒绝缓存与汇总",
                        file=sys.stderr,
                    )
                    return 1
                final_inputs = build_inputs(registry, shard)
                if final_inputs != inputs:
                    print(
                        f"mutation 分片 {shard_id} 执行期间输入发生变化，"
                        "拒绝缓存",
                        file=sys.stderr,
                    )
                    return 1
                result["wall_seconds"] = round(wall_seconds, 3)
                atomic_json_write(
                    cache_path,
                    {
                        "schema_version": 1,
                        "created_at": utc_now(),
                        "shard": {
                            "id": shard_id,
                            "class": shard["class"],
                            "path": shard["path"],
                        },
                        "inputs": inputs,
                        "result": result,
                    },
                )
                source = "run"
            results.append(result)
            rows.append(
                {
                    "id": shard_id,
                    "source": source,
                    "generated": int(result["generated"]),
                    "killed": int(result["tested"]),
                    "score": float(result["score"]),
                }
            )

        aggregate = aggregate_results(results)
        min_msi = load_min_msi()
        print("\n═══ Mutation shard summary ═══")
        for row in rows:
            print(
                f"{row['id']}: {row['source']} "
                f"{row['killed']}/{row['generated']} "
                f"({row['score']:.2f}%)"
            )
        print(
            "aggregate: "
            f"{aggregate['killed']}/{aggregate['generated']} "
            f"({aggregate['score']:.2f}%), min={min_msi:.2f}%"
        )
        evidence = {
            "schema_version": 1,
            "selected_shards": [str(item["id"]) for item in selected],
            "min_msi": min_msi,
            "result": aggregate,
            "shards": rows,
        }
        print(
            "MUTATION_AGGREGATE_JSON="
            + json.dumps(evidence, ensure_ascii=False, sort_keys=True)
        )
        if not aggregate_meets_threshold(
            killed=int(aggregate["killed"]),
            generated=int(aggregate["generated"]),
            min_msi=min_msi,
        ):
            print("mutation 加权汇总 MSI 未达到门槛", file=sys.stderr)
            return 1
        return 0


def command_inspect(args: argparse.Namespace) -> int:
    log_path = Path(args.log).resolve()
    try:
        inspection = inspect_run_log(log_path.read_text(encoding="utf-8"))
    except OSError as exc:
        print(f"无法读取 mutation 日志：{log_path}: {exc}", file=sys.stderr)
        return 1
    except ValueError as exc:
        print(f"mutation 日志证据无效：{exc}", file=sys.stderr)
        return 1

    print("═══ Mutation inspection ═══")
    print(f"generated: {inspection['generated']}")
    print(f"killed: {inspection['killed']}")
    print(f"survivors: {inspection['survivors']}")
    print(
        "structured survivor details: "
        f"{inspection['structured_survivors']}/{inspection['survivors']}"
    )
    print(
        "score: "
        f"{inspection['score']:.2f}% (min={inspection['min_msi']:.2f}%)"
    )
    print(
        "required additional kills: "
        f"{inspection['required_additional_kills']}"
    )
    for label, key in (
        ("top paths", "paths"),
        ("top methods", "methods"),
        ("top mutators", "mutators"),
    ):
        print(f"{label}:")
        for item in inspection["hotspots"][key][:15]:
            print(f"  {item['count']:>4}  {item['key']}")
    if inspection["missing_survivor_details"]:
        print(
            "WARNING: 此日志含缓存分片或旧格式证据，缺少 "
            f"{inspection['missing_survivor_details']} 条 survivor 明细"
        )
    print(
        "MUTATION_INSPECT_JSON="
        + json.dumps(inspection, ensure_ascii=False, sort_keys=True)
    )
    return 0


def diagnostic_cache_fingerprint(
    registry: List[Dict[str, object]],
    shard: Mapping[str, object],
    test_paths: Tuple[str, ...],
) -> str:
    payload = {
        "inputs": build_inputs(registry, shard),
        "test_paths": list(test_paths),
    }
    encoded = json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode()
    return hashlib.sha256(encoded).hexdigest()


def diagnostic_cache_path(
    args: argparse.Namespace,
    shard_id: str,
    fingerprint: str,
) -> Path:
    cache_root = Path(args.pest_cache_dir).resolve()
    path = cache_root / shard_id / fingerprint / "pest-mutate-cache"
    ensure_private_dir(path)
    return path


def diagnostic_test_paths(raw_paths: Iterable[str]) -> Tuple[str, ...]:
    backend_root = (ROOT / "backend").resolve()
    tests_root = (backend_root / "tests").resolve()
    resolved = []
    for raw_path in raw_paths:
        candidate = (ROOT / raw_path).resolve()
        try:
            candidate.relative_to(tests_root)
        except ValueError as exc:
            raise SystemExit(
                f"probe --test-path 必须位于 backend/tests: {raw_path}"
            ) from exc
        if candidate.suffix != ".php" or not candidate.is_file():
            raise SystemExit(f"probe 测试文件不存在或不是 PHP 文件: {raw_path}")
        resolved.append(str(candidate.relative_to(backend_root)))
    return tuple(dict.fromkeys(resolved))


def command_probe(args: argparse.Namespace) -> int:
    registry = load_registry()
    shard = select_shard_by_id(registry, args.shard)
    test_paths = diagnostic_test_paths(getattr(args, "test_paths", ()))
    fingerprint = diagnostic_cache_fingerprint(registry, shard, test_paths)
    pest_cache_dir = diagnostic_cache_path(
        args,
        str(shard["id"]),
        fingerprint,
    )
    if test_paths:
        print(
            "PROBE_ONLY_PARTIAL: 仅运行指定测试文件及其覆盖行，"
            "分数和变异数量不代表完整分片，不能写入正式 mutation 证据",
            flush=True,
        )
    else:
        print(
            "PROBE_ONLY: 此结果仅用于开发诊断，不能写入正式 mutation 证据；"
            "冷启动仍会先串行执行全量覆盖基线",
            flush=True,
        )
    return_code, output, wall_seconds = run_shard(
        shard,
        # --retry 已让 mutate 在首个 untested 停止；补 uncovered 的专用停止项。
        # 不使用 --bail，因为它还会改变前置 PHPUnit 基线的失败行为。
        extra_args=(
            *test_paths,
            "--retry",
            "--stop-on-uncovered",
            "--profile",
        ),
        pest_cache_dir=pest_cache_dir,
    )
    if test_paths and return_code == 0:
        survivors = list(SURVIVOR_RE.finditer(strip_ansi(output)))
        if survivors:
            first = survivors[0]
            print(
                "PROBE_ONLY_SURVIVOR: "
                f"{first.group(2)}:{first.group(3)} "
                f"{first.group(4)} id={first.group(5)}；"
                "其余 pending 是 --retry 早停的预期结果",
                file=sys.stderr,
            )
            return_code = 1
        else:
            try:
                result = parse_mutation_output(output)
            except ValueError as exc:
                print(
                    f"PROBE_ONLY_INVALID: 无法验证局部结果：{exc}",
                    file=sys.stderr,
                )
                return_code = 1
            else:
                timeout_count = int(result["timeout"])
                if timeout_count:
                    print(
                        "PROBE_ONLY_INVALID: 局部基线使 mutant 超时预算过短，"
                        f"{timeout_count}/{result['generated']} 个 timeout；"
                        "timeout 分数不可用于判断测试强度，请缩小测试文件或改跑完整 probe",
                        file=sys.stderr,
                    )
                    return_code = 1
    print(
        f"PROBE_ONLY_RESULT shard={shard['id']} "
        f"exit={return_code} wall={wall_seconds:.3f}s",
        flush=True,
    )
    return return_code


def command_run_id(args: argparse.Namespace) -> int:
    if re.fullmatch(r"[a-f0-9]{16}", args.mutation_id) is None:
        raise SystemExit("无效 mutation ID：必须是 16 位小写十六进制")
    registry = load_registry()
    shard = select_shard_by_id(registry, args.shard)
    fingerprint = diagnostic_cache_fingerprint(registry, shard, ())
    pest_cache_dir = diagnostic_cache_path(
        args,
        str(shard["id"]),
        fingerprint,
    )
    print(
        "PROBE_ONLY: 此结果仅用于开发诊断，不能写入正式 mutation 证据",
        flush=True,
    )
    return_code, _output, wall_seconds = run_shard(
        shard,
        extra_args=(f"--id={args.mutation_id}",),
        pest_cache_dir=pest_cache_dir,
    )
    print(
        f"PROBE_ONLY_RESULT shard={shard['id']} id={args.mutation_id} "
        f"exit={return_code} wall={wall_seconds:.3f}s",
        flush=True,
    )
    return return_code


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    run_parser = commands.add_parser(
        "run",
        help="运行选定分片、复用安全缓存并汇总",
    )
    run_parser.add_argument(
        "--cache-dir",
        default=str(DEFAULT_CACHE_DIR),
        help=argparse.SUPPRESS,
    )
    inspect_parser = commands.add_parser(
        "inspect",
        help="只读分析完整 mutation 运行日志",
    )
    inspect_parser.add_argument(
        "--log",
        required=True,
        help="包含 MUTATION_AGGREGATE_JSON 的正式运行日志",
    )
    probe_parser = commands.add_parser(
        "probe",
        help="非正式早停诊断：优先执行上轮 survivor",
    )
    probe_parser.add_argument("--shard", required=True, help="分片 id")
    probe_parser.add_argument(
        "--test-path",
        action="append",
        default=[],
        dest="test_paths",
        help=(
            "可重复；仅用指定 backend/tests/*.php 做局部诊断，"
            "显著缩短基线但结果不代表完整分片"
        ),
    )
    probe_parser.add_argument(
        "--pest-cache-dir",
        default=str(DEFAULT_PEST_CACHE_DIR),
        help=argparse.SUPPRESS,
    )
    run_id_parser = commands.add_parser(
        "run-id",
        help="非正式诊断：只执行一个 mutation id",
    )
    run_id_parser.add_argument("--shard", required=True, help="分片 id")
    run_id_parser.add_argument(
        "--id",
        required=True,
        dest="mutation_id",
        help="16 位 mutation id",
    )
    run_id_parser.add_argument(
        "--pest-cache-dir",
        default=str(DEFAULT_PEST_CACHE_DIR),
        help=argparse.SUPPRESS,
    )
    return parser


def main() -> int:
    args = build_parser().parse_args()
    if args.command == "run":
        return command_run(args)
    if args.command == "inspect":
        return command_inspect(args)
    if args.command == "probe":
        return command_probe(args)
    if args.command == "run-id":
        return command_run_id(args)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
