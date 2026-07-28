import assert from "node:assert/strict";
import test from "node:test";
import { buildEndpointUrl } from "./endpointUrl.ts";

test("接口地址不继承设置页的查询参数和 hash", () => {
  const pageUrl = new URL(
    "https://example.com/user/setting?tab=deploy&source=menu#token"
  );

  assert.equal(
    buildEndpointUrl("/api/deploy", pageUrl.origin),
    "https://example.com/api/deploy"
  );
  assert.equal(
    buildEndpointUrl("/api/v2", pageUrl.origin),
    "https://example.com/api/v2"
  );
});
