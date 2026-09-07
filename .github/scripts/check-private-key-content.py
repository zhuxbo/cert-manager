#!/usr/bin/env python3
"""检查已跟踪文本文件中的 PEM 私钥数据，不将格式名称视为密钥。"""

import re
import subprocess
from pathlib import Path


PRIVATE_KEY = re.compile(
    rb"-----BEGIN (?:RSA |OPENSSH |EC |DSA |ENCRYPTED )?PRIVATE KEY-----"
    rb"[ \t]*(?:\r?\n|\\r\\n|\\n)[ \t]*"
    rb"(?:[A-Za-z0-9+/=]{16,}|Proc-Type:)"
)
EXTENSIONS = {
    ".conf", ".sh", ".json", ".yml", ".yaml", ".php", ".js", ".ts", ".md", ".txt"
}


def main():
    files = subprocess.check_output(["git", "ls-files", "-z"]).split(b"\0")
    found = False
    for name in filter(None, files):
        path = Path(name.decode("utf-8", errors="surrogateescape"))
        if path.suffix not in EXTENSIONS and not path.name.startswith(".env"):
            continue
        match = PRIVATE_KEY.search(path.read_bytes())
        if match:
            # 只报告路径，避免 CI 日志再次泄露密钥内容。
            print(f"发现私钥数据：{path}")
            found = True
    if found:
        print("::error::文件内容包含私钥数据，请勿提交私钥到仓库")
        return 1
    print("✅ 文件内容未包含私钥数据")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
