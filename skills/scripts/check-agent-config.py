#!/usr/bin/env python3
"""Deterministic guard for shared agent rules and thin tool entrypoints."""

import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]

EXPECTED_CLAUDE = """# 项目智能体规则

@AGENTS.md

本文件仅为 Claude 兼容入口。禁止在此追加项目规则；需要调整时修改 `AGENTS.md` 或其引用的权威资料。
"""

EXPECTED_COMMANDS = {
    "db-structure.md": """读取并严格遵循 `skills/db-structure.md`。

将用户参数 `$ARGUMENTS` 原样作为数据库结构导出流程参数传入。
""",
    "finish-check.md": """读取并严格遵循 `skills/finish-check.md`。

将用户参数 `$ARGUMENTS` 原样作为检查范围或附加要求传入。
""",
    "remote-release.md": """读取并严格遵循 `skills/remote-release.md`。

将用户参数 `$ARGUMENTS` 原样作为发布流程参数传入。
""",
}

EXPECTED_CODEX_SKILLS = {
    "db-structure": """---
name: db-structure
description: Use when the user asks to regenerate, export, or verify ssl-manager's backend/database/structure.json.
---

读取并严格遵循仓库根目录的 `skills/db-structure.md`。

将用户请求中的导出范围及附加要求原样作为数据库结构导出流程参数传入。
""",
    "finish-check": """---
name: finish-check
description: Use when the user asks to run finish-check, simulate CI, or verify ssl-manager before completion.
---

读取并严格遵循仓库根目录的 `skills/finish-check.md`。

将用户请求中的检查范围及附加要求原样作为完成检查参数传入。
""",
    "remote-release": """---
name: remote-release
description: Use when the user asks to publish an ssl-manager dev or main release or verify release completion.
---

读取并严格遵循仓库根目录的 `skills/remote-release.md`。

将用户请求中的版本及附加要求原样作为发布流程参数传入。
""",
}

REQUIRED_AGENT_ROUTES = (
    "无对应入口时先读取 `skills/SKILL.md`，再按路由读取对应叶子资源。",
    "数据库结构导出：`skills/db-structure.md`",
    "完成检查：`skills/finish-check.md`",
    "远程发布：`skills/remote-release.md`",
)


def main() -> int:
    errors: list[str] = []

    agents_path = ROOT / "AGENTS.md"
    if not agents_path.is_file() or agents_path.is_symlink():
        errors.append("AGENTS.md 必须是普通文件")
    else:
        agents = agents_path.read_text(encoding="utf-8")
        for route in REQUIRED_AGENT_ROUTES:
            if route not in agents:
                errors.append(f"AGENTS.md 缺少权威路由：{route}")

    claude_path = ROOT / "CLAUDE.md"
    if claude_path.is_symlink() or not claude_path.is_file():
        errors.append("CLAUDE.md 必须是普通文件")
    elif claude_path.read_text(encoding="utf-8") != EXPECTED_CLAUDE:
        errors.append("CLAUDE.md 不符合固定薄模板")

    claude_commands_root = ROOT / ".claude" / "commands"
    actual_commands = (
        {path.name for path in claude_commands_root.iterdir() if path.is_file()}
        if claude_commands_root.is_dir()
        else set()
    )
    if actual_commands != set(EXPECTED_COMMANDS):
        errors.append("Claude 薄命令集合必须且只能包含 db-structure、finish-check、remote-release")
    for name, expected in EXPECTED_COMMANDS.items():
        path = ROOT / ".claude" / "commands" / name
        if path.is_symlink() or not path.is_file():
            errors.append(f"{path.relative_to(ROOT)} 必须是普通文件")
        elif path.read_text(encoding="utf-8") != expected:
            errors.append(f"{path.relative_to(ROOT)} 不符合固定薄模板")

    codex_root = ROOT / ".agents" / "skills"
    actual_codex = {path.name for path in codex_root.iterdir() if path.is_dir()} if codex_root.is_dir() else set()
    if actual_codex != set(EXPECTED_CODEX_SKILLS):
        errors.append("Codex 原生薄 Skill 集合必须且只能包含 db-structure、finish-check、remote-release")
    for name, expected in EXPECTED_CODEX_SKILLS.items():
        skill_dir = codex_root / name
        files = {path.name for path in skill_dir.iterdir()} if skill_dir.is_dir() else set()
        if files != {"SKILL.md"}:
            errors.append(f".agents/skills/{name} 只能包含 SKILL.md")
            continue
        path = skill_dir / "SKILL.md"
        if path.is_symlink() or path.read_text(encoding="utf-8") != expected:
            errors.append(f"{path.relative_to(ROOT)} 不符合固定薄模板")

    for name in ("db-structure.md", "finish-check.md", "remote-release.md"):
        path = ROOT / "skills" / name
        if path.is_symlink() or not path.is_file():
            errors.append(f"skills/{name} 必须是普通权威正文")
        elif "$ARGUMENTS" in path.read_text(encoding="utf-8"):
            errors.append(f"skills/{name} 不得依赖 Claude 参数占位符")

    makefile = (ROOT / "Makefile").read_text(encoding="utf-8")
    if "check-agent-config:" not in makefile or "python3 skills/scripts/check-agent-config.py" not in makefile:
        errors.append("Makefile 未接入 check-agent-config")

    workflow = (ROOT / ".github" / "workflows" / "ci.yml").read_text(encoding="utf-8")
    if "run: make check-agent-config" not in workflow:
        errors.append("GitHub Actions 未执行 make check-agent-config")

    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1

    print("智能体配置与 Claude/Codex 薄入口防漂移检查通过")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
