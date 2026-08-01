import assert from "node:assert/strict";
import test from "node:test";
import { getSmimeDownloadOptions } from "../src/smimeDownloadOptions.ts";

test("S/MIME 单证书只提供 PEM 和 PFX", () => {
  assert.deepEqual(getSmimeDownloadOptions(), [
    { label: "PEM", value: "pem" },
    { label: "PFX", value: "pfx" }
  ]);
});
