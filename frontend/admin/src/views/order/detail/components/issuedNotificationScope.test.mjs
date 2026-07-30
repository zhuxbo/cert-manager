import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const frontendRoot = new URL("../../../../../../", import.meta.url);

function read(channel, type) {
  return readFileSync(
    new URL(
      `${channel}/src/views/order/detail/components/${type}/operate.vue`,
      frontendRoot
    ),
    "utf8"
  );
}

test("CodeSign 和 DocSign 两端均无系统签发通知入口", () => {
  for (const channel of ["admin", "user"]) {
    for (const type of ["codesign", "docsign"]) {
      const source = read(channel, type);
      assert.doesNotMatch(source, /command=["']send["']/);
      assert.doesNotMatch(source, /OrderApi\.sendActive/);
    }
  }
});

test("SSL 和 S/MIME 两端仍保留系统签发通知入口", () => {
  for (const channel of ["admin", "user"]) {
    for (const type of ["ssl", "smime"]) {
      const source = read(channel, type);
      assert.match(source, /command=["']send["']/);
      assert.match(source, /OrderApi\.sendActive/);
    }
  }
});
