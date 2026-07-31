from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import os
import re
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "skills" / "scripts" / "mutation-shards.py"
REGISTRY = ROOT / "skills" / "mutation-shards.json"


def load_module():
    spec = importlib.util.spec_from_file_location(
        "mutation_shards_under_test",
        SCRIPT,
    )
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class MutationShardsTest(unittest.TestCase):
    def test_registry_contains_six_unique_exact_file_shards(self) -> None:
        payload = json.loads(REGISTRY.read_text(encoding="utf-8"))
        shards = payload["shards"]

        self.assertEqual(1, payload["schema_version"])
        self.assertEqual(6, len(shards))
        self.assertEqual(6, len({item["id"] for item in shards}))
        self.assertEqual(6, len({item["class"] for item in shards}))
        self.assertEqual(6, len({item["path"] for item in shards}))
        for shard in shards:
            self.assertTrue((ROOT / shard["path"]).is_file())

    def test_registry_covers_every_test_that_directly_imports_a_shard_class(
        self,
    ) -> None:
        module = load_module()
        tests = list((ROOT / "backend" / "tests").rglob("*.php"))

        for shard in module.load_registry():
            class_name = re.escape(str(shard["class"]))
            import_pattern = re.compile(
                rf"^\s*use\s+{class_name}(?:\s+as\s+\w+)?\s*;",
                re.MULTILINE,
            )
            patterns = [
                *module.COMMON_TEST_PATTERNS,
                *shard["test_patterns"],
            ]
            missing = [
                path.relative_to(ROOT).as_posix()
                for path in tests
                if import_pattern.search(path.read_text(encoding="utf-8"))
                and not any(
                    module.glob_matches(
                        path.relative_to(ROOT).as_posix(),
                        pattern,
                    )
                    for pattern in patterns
                )
            ]
            self.assertEqual([], missing, shard["id"])

    def test_finish_check_mutation_gate_uses_shard_orchestrator(self) -> None:
        manifest = json.loads(
            (ROOT / "skills/finish-check-gates.json").read_text(encoding="utf-8")
        )
        gate = manifest["gates"]["mutation"]

        self.assertEqual(
            ["python3", "skills/scripts/mutation-shards.py", "run"],
            gate["command"],
        )
        self.assertEqual(
            ["MUTATE_TARGET_CLASSES", "MUTATION_KEEP_WORKSPACE"],
            gate["allowed_env"],
        )

    def test_common_inputs_bind_docker_runtime_definition(self) -> None:
        module = load_module()
        registry = module.load_registry()
        with mock.patch.object(
            module,
            "collect_files",
            side_effect=[{}, {}, {}],
        ) as collect:
            module.build_inputs(registry, registry[0])

        common_roots = collect.call_args_list[2].args[0]
        self.assertIn(ROOT / "docker", common_roots)
        self.assertIn(ROOT / "compose.yaml", common_roots)

    def test_formal_test_args_run_direct_unit_tests_before_feature_tests(self) -> None:
        module = load_module()

        result = module.formal_test_args(
            {
                "tests": {
                    "backend/tests/Feature/Http/ActionTest.php": "feature",
                    "backend/tests/Unit/Services/ActionTest.php": "unit-action",
                    "backend/tests/Unit/Models/ActionTest.php": "unit-model",
                }
            }
        )

        self.assertEqual(
            (
                "tests/Unit/Models/ActionTest.php",
                "tests/Unit/Services/ActionTest.php",
                "tests/Feature/Http/ActionTest.php",
            ),
            result,
        )

    def test_parse_requires_complete_counts_and_strips_ansi(self) -> None:
        module = load_module()
        output = """
        \x1b[90m108 Mutations for 1 Files created\x1b[39m
        UNTESTED app/Models/Fund.php > Line 10: RemoveArrayItem - ID: aaaaaaaaaaaaaaaa
        UNTESTED app/Models/Fund.php > Line 11: IncrementInteger - ID: bbbbbbbbbbbbbbbb
        UNTESTED app/Models/Fund.php > Line 12: DecrementInteger - ID: cccccccccccccccc
        UNTESTED app/Models/Fund.php > Line 13: RemoveMethodCall - ID: dddddddddddddddd
        UNTESTED app/Models/Fund.php > Line 14: IfNegated - ID: eeeeeeeeeeeeeeee
        UNTESTED app/Models/Fund.php > Line 15: ConcatRemoveLeft - ID: ffffffffffffffff
        UNTESTED app/Models/Fund.php > Line 16: ConcatRemoveRight - ID: 1111111111111111
        UNTESTED app/Models/Fund.php > Line 17: ConcatSwitchSides - ID: 2222222222222222
        UNCOVERED app/Models/Fund.php > Line 18: RemoveStringCast - ID: 3333333333333333
        UNCOVERED app/Models/Fund.php > Line 19: RemoveIntegerCast - ID: 4444444444444444
        Mutations: 8 untested, 2 uncovered, 3 timeout, 95 tested
        Score: 90.74%
        Duration: 321.50s
        """

        result = module.parse_mutation_output(output)

        self.assertEqual(
            {
                "generated": 108,
                "files": 1,
                "untested": 8,
                "uncovered": 2,
                "pending": 0,
                "timeout": 3,
                "tested": 95,
                "score": 90.74,
                "duration_seconds": 321.5,
                "survivors": [
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 10,
                        "mutator": "RemoveArrayItem",
                        "id": "aaaaaaaaaaaaaaaa",
                    },
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 11,
                        "mutator": "IncrementInteger",
                        "id": "bbbbbbbbbbbbbbbb",
                    },
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 12,
                        "mutator": "DecrementInteger",
                        "id": "cccccccccccccccc",
                    },
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 13,
                        "mutator": "RemoveMethodCall",
                        "id": "dddddddddddddddd",
                    },
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 14,
                        "mutator": "IfNegated",
                        "id": "eeeeeeeeeeeeeeee",
                    },
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 15,
                        "mutator": "ConcatRemoveLeft",
                        "id": "ffffffffffffffff",
                    },
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 16,
                        "mutator": "ConcatRemoveRight",
                        "id": "1111111111111111",
                    },
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 17,
                        "mutator": "ConcatSwitchSides",
                        "id": "2222222222222222",
                    },
                    {
                        "outcome": "uncovered",
                        "path": "app/Models/Fund.php",
                        "line": 18,
                        "mutator": "RemoveStringCast",
                        "id": "3333333333333333",
                    },
                    {
                        "outcome": "uncovered",
                        "path": "app/Models/Fund.php",
                        "line": 19,
                        "mutator": "RemoveIntegerCast",
                        "id": "4444444444444444",
                    },
                ],
                "slow_mutations": [],
            },
            result,
        )

    def test_parse_rejects_pending_or_count_mismatch(self) -> None:
        module = load_module()
        with self.assertRaisesRegex(ValueError, "pending"):
            module.parse_mutation_output(
                "108 Mutations for 1 Files created\n"
                "Mutations: 1 untested, 87 pending, 20 tested\n"
                "Score: 18.52%\nDuration: 62.98s\n"
            )
        with self.assertRaisesRegex(ValueError, "数量"):
            module.parse_mutation_output(
                "108 Mutations for 1 Files created\n"
                "UNTESTED app/Models/Fund.php > Line 10: RemoveArrayItem "
                "- ID: aaaaaaaaaaaaaaaa\n"
                "Mutations: 7 untested, 100 tested\n"
                "Score: 92.59%\nDuration: 300.00s\n"
            )
        with self.assertRaisesRegex(ValueError, "Score"):
            module.parse_mutation_output(
                "10 Mutations for 1 Files created\n"
                "UNTESTED app/Models/Fund.php > Line 10: RemoveArrayItem "
                "- ID: aaaaaaaaaaaaaaaa\n"
                "Mutations: 1 untested, 9 tested\n"
                "Score: 100.00%\nDuration: 10.00s\n"
            )

    def test_parse_supports_thousands_duration_survivors_and_profile(self) -> None:
        module = load_module()
        output = (
            "507 Mutations for 1 Files created\n"
            "UNTESTED app/Services/Acme/Action.php > "
            "Line 200: RemoveMethodCall - ID: abcdef0123456789\n"
            "UNCOVERED app/Services/Acme/Action.php > "
            "Line 250: IncrementInteger - ID: 0123456789abcdef\n"
            "Top 10 slowest mutation tests:\n"
            "app/Services/Acme/Action.php > Line 200: RemoveMethodCall "
            "- ID: abcdef0123456789  12.34s\n"
            "Mutations: 1 untested, 1 uncovered, 505 tested\n"
            "Score: 99.61%\n"
            "Duration: 2,403.51s\n"
        )

        result = module.parse_mutation_output(output)

        self.assertEqual(2403.51, result["duration_seconds"])
        self.assertEqual(
            [
                {
                    "outcome": "untested",
                    "path": "app/Services/Acme/Action.php",
                    "line": 200,
                    "mutator": "RemoveMethodCall",
                    "id": "abcdef0123456789",
                },
                {
                    "outcome": "uncovered",
                    "path": "app/Services/Acme/Action.php",
                    "line": 250,
                    "mutator": "IncrementInteger",
                    "id": "0123456789abcdef",
                },
            ],
            result["survivors"],
        )
        self.assertEqual(
            [
                {
                    "path": "app/Services/Acme/Action.php",
                    "line": 200,
                    "mutator": "RemoveMethodCall",
                    "id": "abcdef0123456789",
                    "seconds": 12.34,
                }
            ],
            result["slow_mutations"],
        )

    def test_parse_rejects_incomplete_survivor_manifest(self) -> None:
        module = load_module()

        with self.assertRaisesRegex(ValueError, "survivor"):
            module.parse_mutation_output(
                "10 Mutations for 1 Files created\n"
                "UNTESTED app/Models/Fund.php > Line 10: RemoveArrayItem "
                "- ID: aaaaaaaaaaaaaaaa\n"
                "Mutations: 2 untested, 8 tested\n"
                "Score: 80.00%\nDuration: 10.00s\n"
            )

    def test_parse_uses_last_retry_attempt(self) -> None:
        module = load_module()
        output = (
            "108 Mutations for 1 Files created\n"
            "Mutations: 1 untested, 87 pending, 20 tested\n"
            "Score: 18.52%\nDuration: 62.98s\n"
            "bootstrap/cache/packages.php Failed to open stream\n"
            "108 Mutations for 1 Files created\n"
            + "".join(
                "UNTESTED app/Models/Fund.php > "
                f"Line {line}: RemoveArrayItem - ID: {line:016x}\n"
                for line in range(1, 30)
            )
            +
            "Mutations: 29 untested, 79 tested\n"
            "Score: 73.15%\nDuration: 411.96s\n"
        )

        result = module.parse_mutation_output(output)

        self.assertEqual(108, result["generated"])
        self.assertEqual(0, result["pending"])
        self.assertEqual(79, result["tested"])
        self.assertEqual(411.96, result["duration_seconds"])

    def test_required_additional_kills_uses_exact_threshold(self) -> None:
        module = load_module()

        self.assertEqual(
            302,
            module.required_additional_kills(
                killed=1048,
                generated=1534,
                min_msi=88.0,
            ),
        )

    def test_inspect_run_log_reports_aggregate_and_structured_hotspots(self) -> None:
        module = load_module()
        aggregate = {
            "min_msi": 88.0,
            "result": {
                "generated": 1534,
                "killed": 1048,
                "pending": 0,
                "score": 68.32,
                "tested": 1048,
                "timeout": 0,
                "uncovered": 0,
                "untested": 486,
            },
        }
        output = (
            "UNTESTED app/Services/Order/Action.php > "
            "Line 100: RemoveMethodCall - ID: abcdef0123456789\n"
            "UNTESTED app/Services/Order/Action.php > "
            "Line 101: IncrementInteger - ID: 0123456789abcdef\n"
            "UNTESTED app/Services/Order/Action.php > "
            "Line 100: RemoveMethodCall - ID: abcdef0123456789\n"
            "MUTATION_AGGREGATE_JSON="
            + json.dumps(aggregate)
            + "\n"
        )

        inspection = module.inspect_run_log(output)

        self.assertEqual(1534, inspection["generated"])
        self.assertEqual(1048, inspection["killed"])
        self.assertEqual(486, inspection["survivors"])
        self.assertEqual(302, inspection["required_additional_kills"])
        self.assertEqual(2, inspection["structured_survivors"])
        self.assertEqual(484, inspection["missing_survivor_details"])
        self.assertEqual(
            [
                {
                    "key": "app/Services/Order/Action.php",
                    "count": 2,
                }
            ],
            inspection["hotspots"]["paths"],
        )
        self.assertEqual(
            {"id": "abcdef0123456789", "line": 100},
            {
                "id": inspection["survivor_details"][0]["id"],
                "line": inspection["survivor_details"][0]["line"],
            },
        )

    def test_inspect_command_reads_log_without_running_mutation(self) -> None:
        module = load_module()
        aggregate = {
            "min_msi": 88.0,
            "result": {
                "generated": 100,
                "killed": 80,
                "tested": 80,
                "timeout": 0,
                "untested": 20,
                "uncovered": 0,
                "score": 80.0,
            },
        }
        with tempfile.TemporaryDirectory() as temp_dir:
            log_path = Path(temp_dir) / "mutation.log"
            log_path.write_text(
                "MUTATION_AGGREGATE_JSON=" + json.dumps(aggregate) + "\n",
                encoding="utf-8",
            )
            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                result = module.command_inspect(
                    SimpleNamespace(log=str(log_path))
                )

        self.assertEqual(0, result)
        self.assertIn("generated: 100", stdout.getvalue())
        self.assertIn("required additional kills: 8", stdout.getvalue())
        self.assertIn("MUTATION_INSPECT_JSON=", stdout.getvalue())

    def test_parser_exposes_inspect_log_option(self) -> None:
        module = load_module()

        args = module.build_parser().parse_args(
            ["inspect", "--log", "/tmp/mutation.log"]
        )

        self.assertEqual("inspect", args.command)
        self.assertEqual("/tmp/mutation.log", args.log)

    def test_probe_is_non_formal_and_uses_full_baseline_by_default(self) -> None:
        module = load_module()
        with tempfile.TemporaryDirectory() as temp_dir:
            stdout = io.StringIO()
            with (
                mock.patch.object(
                    module,
                    "diagnostic_cache_fingerprint",
                    return_value="input-fingerprint",
                ),
                mock.patch.object(
                    module,
                    "run_shard",
                    return_value=(0, "probe output\n", 2.5),
                ) as run_shard,
                contextlib.redirect_stdout(stdout),
            ):
                result = module.command_probe(
                    SimpleNamespace(
                        shard="order-action",
                        pest_cache_dir=temp_dir,
                        test_paths=[],
                    )
                )

        self.assertEqual(0, result)
        self.assertIn("PROBE_ONLY", stdout.getvalue())
        shard = run_shard.call_args.args[0]
        self.assertEqual("order-action", shard["id"])
        self.assertEqual(
            ("--retry", "--stop-on-uncovered", "--profile"),
            run_shard.call_args.kwargs["extra_args"],
        )
        self.assertEqual(
            Path(temp_dir).resolve()
            / "order-action"
            / "input-fingerprint"
            / "pest-mutate-cache",
            run_shard.call_args.kwargs["pest_cache_dir"],
        )

    def test_diagnostic_cache_fingerprint_binds_inputs_and_test_scope(self) -> None:
        module = load_module()
        shard = module.select_shard_by_id(
            module.load_registry(),
            "acme-action",
        )
        with mock.patch.object(
            module,
            "build_inputs",
            return_value={
                "common": "common-a",
                "dependencies": "dependencies-a",
                "tests": {"backend/tests/A.php": "digest-a"},
            },
        ):
            first = module.diagnostic_cache_fingerprint(
                module.load_registry(),
                shard,
                ("tests/A.php",),
            )
            same = module.diagnostic_cache_fingerprint(
                module.load_registry(),
                shard,
                ("tests/A.php",),
            )
            other_scope = module.diagnostic_cache_fingerprint(
                module.load_registry(),
                shard,
                ("tests/B.php",),
            )

        with mock.patch.object(
            module,
            "build_inputs",
            return_value={
                "common": "common-b",
                "dependencies": "dependencies-a",
                "tests": {"backend/tests/A.php": "digest-a"},
            },
        ):
            other_inputs = module.diagnostic_cache_fingerprint(
                module.load_registry(),
                shard,
                ("tests/A.php",),
            )

        self.assertEqual(first, same)
        self.assertNotEqual(first, other_scope)
        self.assertNotEqual(first, other_inputs)

    def test_probe_can_limit_baseline_to_valid_test_files(self) -> None:
        module = load_module()
        with tempfile.TemporaryDirectory() as temp_dir:
            stdout = io.StringIO()
            with (
                mock.patch.object(
                    module,
                    "run_shard",
                    return_value=(
                        0,
                        "1 Mutations for 1 Files created\n"
                        "Mutations: 1 tested\n"
                        "Score: 100.00%\n"
                        "Duration: 1.00s\n",
                        1.0,
                    ),
                ) as run_shard,
                contextlib.redirect_stdout(stdout),
            ):
                result = module.command_probe(
                    SimpleNamespace(
                        shard="acme-action",
                        pest_cache_dir=temp_dir,
                        test_paths=[
                            "backend/tests/Unit/Services/Acme/ActionTest.php"
                        ],
                    )
                )

        self.assertEqual(0, result)
        self.assertIn("PROBE_ONLY_PARTIAL", stdout.getvalue())
        self.assertEqual(
            (
                "tests/Unit/Services/Acme/ActionTest.php",
                "--retry",
                "--stop-on-uncovered",
                "--profile",
            ),
            run_shard.call_args.kwargs["extra_args"],
        )

    def test_partial_probe_rejects_timeout_as_false_green(self) -> None:
        module = load_module()
        with tempfile.TemporaryDirectory() as temp_dir:
            stderr = io.StringIO()
            with (
                mock.patch.object(
                    module,
                    "run_shard",
                    return_value=(
                        0,
                        "2 Mutations for 1 Files created\n"
                        "Mutations: 2 timeout, 0 tested\n"
                        "Score: 100.00%\n"
                        "Duration: 10.00s\n",
                        10.0,
                    ),
                ),
                contextlib.redirect_stderr(stderr),
            ):
                result = module.command_probe(
                    SimpleNamespace(
                        shard="acme-action",
                        pest_cache_dir=temp_dir,
                        test_paths=[
                            "backend/tests/Unit/Services/Acme/ActionTest.php"
                        ],
                    )
                )

        self.assertEqual(1, result)
        self.assertIn("2/2 个 timeout", stderr.getvalue())

    def test_partial_probe_reports_retry_survivor_before_pending(self) -> None:
        module = load_module()
        with tempfile.TemporaryDirectory() as temp_dir:
            stderr = io.StringIO()
            with (
                mock.patch.object(
                    module,
                    "run_shard",
                    return_value=(
                        0,
                        "3 Mutations for 1 Files created\n"
                        "UNTESTED app/Services/Acme/Action.php > "
                        "Line 10: IncrementInteger - ID: abcdef0123456789\n"
                        "Mutations: 1 untested, 2 pending, 0 tested\n"
                        "Score: 0.00%\n"
                        "Duration: 1.00s\n",
                        1.0,
                    ),
                ),
                contextlib.redirect_stderr(stderr),
            ):
                result = module.command_probe(
                    SimpleNamespace(
                        shard="acme-action",
                        pest_cache_dir=temp_dir,
                        test_paths=[
                            "backend/tests/Unit/Services/Acme/ActionTest.php"
                        ],
                    )
                )

        self.assertEqual(1, result)
        self.assertIn("PROBE_ONLY_SURVIVOR", stderr.getvalue())
        self.assertIn("abcdef0123456789", stderr.getvalue())

    def test_probe_rejects_test_paths_outside_backend_tests(self) -> None:
        module = load_module()

        with self.assertRaisesRegex(SystemExit, "必须位于 backend/tests"):
            module.diagnostic_test_paths(["backend/app/Services/Acme/Action.php"])

    def test_run_id_is_non_formal_and_validates_id(self) -> None:
        module = load_module()
        with tempfile.TemporaryDirectory() as temp_dir:
            stdout = io.StringIO()
            with (
                mock.patch.object(
                    module,
                    "run_shard",
                    return_value=(0, "killed\n", 2.0),
                ) as run_shard,
                contextlib.redirect_stdout(stdout),
            ):
                result = module.command_run_id(
                    SimpleNamespace(
                        shard="acme-action",
                        mutation_id="abcdef0123456789",
                        pest_cache_dir=temp_dir,
                    )
                )

        self.assertEqual(0, result)
        self.assertIn("PROBE_ONLY", stdout.getvalue())
        self.assertEqual(
            ("--id=abcdef0123456789",),
            run_shard.call_args.kwargs["extra_args"],
        )
        with self.assertRaisesRegex(SystemExit, "mutation ID"):
            module.command_run_id(
                SimpleNamespace(
                    shard="acme-action",
                    mutation_id="../bad",
                    pest_cache_dir=temp_dir,
                )
            )

    def test_parser_exposes_probe_and_run_id(self) -> None:
        module = load_module()

        probe = module.build_parser().parse_args(
            [
                "probe",
                "--shard",
                "order-action",
                "--test-path",
                "backend/tests/Unit/Services/Order/ActionTest.php",
            ]
        )
        run_id = module.build_parser().parse_args(
            [
                "run-id",
                "--shard",
                "acme-action",
                "--id",
                "abcdef0123456789",
            ]
        )

        self.assertEqual("probe", probe.command)
        self.assertEqual("order-action", probe.shard)
        self.assertEqual(
            ["backend/tests/Unit/Services/Order/ActionTest.php"],
            probe.test_paths,
        )
        self.assertEqual("run-id", run_id.command)
        self.assertEqual("abcdef0123456789", run_id.mutation_id)
        self.assertEqual(
            0,
            module.required_additional_kills(
                killed=88,
                generated=100,
                min_msi=88.0,
            ),
        )

    def test_aggregate_uses_mutant_weight_not_average_score(self) -> None:
        module = load_module()
        aggregate = module.aggregate_results(
            [
                {
                    "generated": 100,
                    "untested": 0,
                    "uncovered": 0,
                    "pending": 0,
                    "timeout": 0,
                    "tested": 100,
                },
                {
                    "generated": 900,
                    "untested": 180,
                    "uncovered": 0,
                    "pending": 0,
                    "timeout": 0,
                    "tested": 720,
                },
            ]
        )

        self.assertEqual(1000, aggregate["generated"])
        self.assertEqual(820, aggregate["killed"])
        self.assertEqual(82.0, aggregate["score"])

    def test_aggregate_never_counts_timeout_as_killed(self) -> None:
        module = load_module()
        aggregate = module.aggregate_results(
            [
                {
                    "generated": 100,
                    "untested": 12,
                    "uncovered": 0,
                    "pending": 0,
                    "timeout": 88,
                    "tested": 0,
                }
            ]
        )

        self.assertEqual(0, aggregate["killed"])
        self.assertEqual(0.0, aggregate["score"])
        self.assertFalse(
            module.result_is_complete(
                {
                    **aggregate,
                    "files": 1,
                    "duration_seconds": 1.0,
                }
            )
        )

    def test_threshold_uses_unrounded_weighted_score(self) -> None:
        module = load_module()

        self.assertEqual(
            88.0,
            module.aggregate_results(
                [
                    {
                        "generated": 100000,
                        "tested": 87999,
                    }
                ]
            )["score"],
        )
        self.assertFalse(
            module.aggregate_meets_threshold(
                killed=87999,
                generated=100000,
                min_msi=88.0,
            )
        )
        self.assertTrue(
            module.aggregate_meets_threshold(
                killed=88000,
                generated=100000,
                min_msi=88.0,
            )
        )

    def test_cache_reuse_is_fail_closed_and_new_tests_rerun_survivors(self) -> None:
        module = load_module()
        current = {
            "common": "common-a",
            "dependencies": "deps-a",
            "tests": {"tests/A.php": "a"},
        }
        clean_cache = {
            "schema_version": 1,
            "inputs": current,
            "result": {
                "files": 1,
                "generated": 10,
                "untested": 0,
                "uncovered": 0,
                "pending": 0,
                "timeout": 0,
                "tested": 10,
                "score": 100.0,
                "duration_seconds": 1.0,
                "survivors": [],
                "slow_mutations": [],
            },
        }

        self.assertEqual(
            (True, "命中"),
            module.cache_reuse_decision(clean_cache, current),
        )

        with_new_test = {
            **current,
            "tests": {
                "tests/A.php": "a",
                "tests/New.php": "new",
            },
        }
        self.assertEqual(
            (True, "仅新增测试且缓存分片无 survivor"),
            module.cache_reuse_decision(clean_cache, with_new_test),
        )

        survivor_cache = json.loads(json.dumps(clean_cache))
        survivor_cache["result"]["untested"] = 1
        survivor_cache["result"]["tested"] = 9
        survivor_cache["result"]["score"] = 90.0
        survivor_cache["result"]["survivors"] = [
            {
                "outcome": "untested",
                "path": "app/Models/Fund.php",
                "line": 10,
                "mutator": "RemoveArrayItem",
                "id": "aaaaaaaaaaaaaaaa",
            }
        ]
        self.assertEqual(
            (False, "新增测试可能杀死 survivor"),
            module.cache_reuse_decision(survivor_cache, with_new_test),
        )

        changed = {
            **current,
            "common": "common-b",
        }
        self.assertEqual(
            (False, "共享输入变化"),
            module.cache_reuse_decision(clean_cache, changed),
        )

        incomplete_cache = json.loads(json.dumps(clean_cache))
        del incomplete_cache["result"]["files"]
        self.assertEqual(
            (False, "缓存证据不完整"),
            module.cache_reuse_decision(incomplete_cache, current),
        )

        legacy_cache = json.loads(json.dumps(clean_cache))
        del legacy_cache["result"]["survivors"]
        self.assertEqual(
            (False, "缓存证据不完整"),
            module.cache_reuse_decision(legacy_cache, current),
        )

    def test_relevant_test_manifest_excludes_unrelated_test_content(self) -> None:
        module = load_module()
        all_tests = {
            "backend/tests/Unit/Services/Order/ActionTest.php": "order-a",
            "backend/tests/Unit/Services/Acme/ActionTest.php": "acme-a",
            "backend/tests/Pest.php": "shared-a",
            "backend/tests/Support/MutationHelper.php": "helper-a",
        }

        relevant = module.select_relevant_test_manifest(
            all_tests,
            [
                "backend/tests/Pest.php",
                "backend/tests/Support/**/*.php",
                "backend/tests/**/Order/*Test.php",
            ],
        )

        self.assertEqual(
            {
                "backend/tests/Pest.php": "shared-a",
                "backend/tests/Support/MutationHelper.php": "helper-a",
                "backend/tests/Unit/Services/Order/ActionTest.php": "order-a",
            },
            relevant,
        )

    def test_fund_execution_scope_ignores_existing_order_test_change(self) -> None:
        module = load_module()
        registry = module.load_registry()
        fund = next(item for item in registry if item["id"] == "fund")
        before_tests = {
            "backend/tests/Feature/Models/FundTest.php": "fund-a",
            "backend/tests/Unit/Services/Order/ActionTest.php": "order-a",
        }
        after_tests = {
            **before_tests,
            "backend/tests/Unit/Services/Order/ActionTest.php": "order-b",
        }
        with mock.patch.object(
            module,
            "collect_files",
            side_effect=[
                before_tests,
                {"backend/app/Models/Fund.php": "source"},
                {"backend/composer.lock": "common"},
                after_tests,
                {"backend/app/Models/Fund.php": "source"},
                {"backend/composer.lock": "common"},
            ],
        ):
            before = module.build_inputs(registry, fund)
            after = module.build_inputs(registry, fund)

        self.assertEqual(before, after)
        self.assertNotIn(
            "backend/tests/Unit/Services/Order/ActionTest.php",
            before["tests"],
        )

    def test_new_unrelated_test_keeps_survivor_cache_hit(self) -> None:
        module = load_module()
        current = {
            "common": "common-a",
            "dependencies": "deps-a",
            "tests": {"backend/tests/Unit/FundTest.php": "fund-a"},
        }
        cached = {
            "schema_version": 1,
            "inputs": current,
            "result": {
                "files": 1,
                "generated": 10,
                "untested": 1,
                "uncovered": 0,
                "pending": 0,
                "timeout": 0,
                "tested": 9,
                "score": 90.0,
                "duration_seconds": 1.0,
                "survivors": [
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 10,
                        "mutator": "RemoveArrayItem",
                        "id": "aaaaaaaaaaaaaaaa",
                    }
                ],
                "slow_mutations": [],
            },
        }
        with_new_unrelated_test = {
            **current,
            "test_universe": [
                "backend/tests/Unit/FundTest.php",
                "backend/tests/Unit/UnrelatedTest.php",
            ],
        }

        self.assertEqual(
            (True, "命中"),
            module.cache_reuse_decision(cached, with_new_unrelated_test),
        )

    def test_unrelated_existing_test_content_change_keeps_cache_hit(self) -> None:
        module = load_module()
        inputs = {
            "common": "common-a",
            "dependencies": "deps-a",
            "tests": {"backend/tests/Unit/FundTest.php": "fund-a"},
        }
        cached = {
            "schema_version": 1,
            "inputs": inputs,
            "result": {
                "files": 1,
                "generated": 10,
                "untested": 1,
                "uncovered": 0,
                "pending": 0,
                "timeout": 0,
                "tested": 9,
                "score": 90.0,
                "duration_seconds": 1.0,
                "survivors": [
                    {
                        "outcome": "untested",
                        "path": "app/Models/Fund.php",
                        "line": 10,
                        "mutator": "RemoveArrayItem",
                        "id": "aaaaaaaaaaaaaaaa",
                    }
                ],
                "slow_mutations": [],
            },
        }

        self.assertEqual(
            (True, "命中"),
            module.cache_reuse_decision(cached, dict(inputs)),
        )

    def test_atomic_cache_is_private_and_round_trips(self) -> None:
        module = load_module()
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "fund.json"
            payload = {"schema_version": 1, "value": "ok"}

            module.atomic_json_write(path, payload)

            self.assertEqual(payload, json.loads(path.read_text(encoding="utf-8")))
            self.assertEqual(0o600, path.stat().st_mode & 0o777)

    def test_cache_lock_is_private(self) -> None:
        module = load_module()
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / ".lock"

            with module.private_lock(path):
                self.assertTrue(path.is_file())
                self.assertEqual(0o600, path.stat().st_mode & 0o777)

    def test_run_refuses_to_cache_if_inputs_change_during_shard(self) -> None:
        module = load_module()
        before = {
            "common": "a",
            "dependencies": "b",
            "tests": {"backend/tests/Unit/FundTest.php": "c"},
        }
        after = {
            **before,
            "common": "changed",
        }
        output = (
            "10 Mutations for 1 Files created\n"
            "UNTESTED app/Models/Fund.php > Line 10: RemoveArrayItem "
            "- ID: aaaaaaaaaaaaaaaa\n"
            "Mutations: 1 untested, 9 tested\n"
            "Score: 90.00%\n"
            "Duration: 1.00s\n"
        )
        with tempfile.TemporaryDirectory() as temp_dir:
            with (
                mock.patch.dict(
                    os.environ,
                    {"MUTATE_TARGET_CLASSES": "App\\Models\\Fund"},
                    clear=False,
                ),
                mock.patch.object(
                    module,
                    "build_inputs",
                    side_effect=[before, after],
                ),
                mock.patch.object(
                    module,
                    "run_shard",
                    return_value=(0, output, 1.1),
                ) as run_shard,
                mock.patch.object(module, "load_min_msi", return_value=0.0),
            ):
                result = module.command_run(
                    SimpleNamespace(cache_dir=temp_dir)
                )

            self.assertEqual(1, result)
            self.assertFalse((Path(temp_dir) / "fund.json").exists())
            self.assertEqual(
                ("tests/Unit/FundTest.php",),
                run_shard.call_args.kwargs["extra_args"],
            )

    def test_run_refuses_timeout_result_before_writing_cache(self) -> None:
        module = load_module()
        inputs = {
            "common": "a",
            "dependencies": "b",
            "tests": {"backend/tests/Unit/FundTest.php": "c"},
        }
        output = (
            "10 Mutations for 1 Files created\n"
            "Mutations: 1 timeout, 9 tested\n"
            "Score: 100.00%\n"
            "Duration: 1.00s\n"
        )
        with tempfile.TemporaryDirectory() as temp_dir:
            with (
                mock.patch.dict(
                    os.environ,
                    {"MUTATE_TARGET_CLASSES": "App\\Models\\Fund"},
                    clear=False,
                ),
                mock.patch.object(module, "build_inputs", return_value=inputs),
                mock.patch.object(
                    module,
                    "run_shard",
                    return_value=(0, output, 1.1),
                ),
                mock.patch.object(module, "load_min_msi", return_value=0.0),
            ):
                result = module.command_run(SimpleNamespace(cache_dir=temp_dir))

            self.assertEqual(1, result)
            self.assertFalse((Path(temp_dir) / "fund.json").exists())

    def test_safe_new_test_hit_promotes_test_manifest(self) -> None:
        module = load_module()
        old_inputs = {
            "common": "a",
            "dependencies": "b",
            "tests": {"tests/A.php": "c"},
        }
        current_inputs = {
            **old_inputs,
            "tests": {
                **old_inputs["tests"],
                "tests/New.php": "new",
            },
        }
        cached = {
            "schema_version": 1,
            "created_at": "old",
            "shard": {
                "id": "fund",
                "class": "App\\Models\\Fund",
                "path": "backend/app/Models/Fund.php",
            },
            "inputs": old_inputs,
            "result": {
                "files": 1,
                "generated": 10,
                "untested": 0,
                "uncovered": 0,
                "pending": 0,
                "timeout": 0,
                "tested": 10,
                "score": 100.0,
                "duration_seconds": 1.0,
                "survivors": [],
                "slow_mutations": [],
            },
        }
        with tempfile.TemporaryDirectory() as temp_dir:
            cache_path = Path(temp_dir) / "fund.json"
            module.atomic_json_write(cache_path, cached)
            with (
                mock.patch.dict(
                    os.environ,
                    {"MUTATE_TARGET_CLASSES": "App\\Models\\Fund"},
                    clear=False,
                ),
                mock.patch.object(
                    module,
                    "build_inputs",
                    return_value=current_inputs,
                ),
                mock.patch.object(module, "load_min_msi", return_value=0.0),
                mock.patch.object(module, "run_shard") as run_shard,
            ):
                result = module.command_run(
                    SimpleNamespace(cache_dir=temp_dir)
                )

            self.assertEqual(0, result)
            run_shard.assert_not_called()
            promoted = json.loads(cache_path.read_text(encoding="utf-8"))
            self.assertEqual(
                current_inputs["tests"],
                promoted["inputs"]["tests"],
            )


if __name__ == "__main__":
    unittest.main()
