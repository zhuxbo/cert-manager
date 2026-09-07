import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCANNER = Path(__file__).with_name("check-private-key-content.py").resolve()


class PrivateKeyContentTest(unittest.TestCase):
    def scan(self, content, filename="fixture.php"):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            subprocess.run(["git", "init", "-q", directory], check=True)
            (root / filename).write_text(content)
            subprocess.run(["git", "add", "--", filename], cwd=root, check=True)
            return subprocess.run(
                [sys.executable, str(SCANNER)], cwd=root,
                capture_output=True, text=True,
            )

    def test_format_names_and_assertions_are_not_keys(self):
        for kind in ("RSA ", "EC ", ""):
            header = f"-----BEGIN {kind}PRIVATE KEY-----"
            with self.subTest(kind=kind):
                result = self.scan(f"expect($key)->toStartWith('{header}');\n")
                self.assertEqual(result.returncode, 0, result.stdout)
                result = self.scan(f"格式名称：`{header}`。\n", "fixture.md")
                self.assertEqual(result.returncode, 0, result.stdout)

    def test_key_payloads_are_blocked_without_printing_them(self):
        payload = "QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo="
        for kind in ("RSA ", "EC ", "DSA ", "OPENSSH ", "ENCRYPTED ", ""):
            for newline in ("\n", "\r\n", "\\n", "\\r\\n"):
                with self.subTest(kind=kind, newline=newline):
                    # 合成载荷即可验证扫描器，不在仓库中保存真实私钥。
                    key = f"-----BEGIN {kind}PRIVATE KEY-----{newline}{payload}"
                    result = self.scan(key)
                    self.assertEqual(result.returncode, 1, result.stdout)
                    self.assertIn("fixture.php", result.stdout)
                    self.assertNotIn(payload, result.stdout)

    def test_legacy_encrypted_pem_is_blocked(self):
        key = "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\n"
        result = self.scan(key, ".env.example")
        self.assertEqual(result.returncode, 1, result.stdout)


if __name__ == "__main__":
    unittest.main()
