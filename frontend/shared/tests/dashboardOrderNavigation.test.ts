import assert from "node:assert/strict";
import test from "node:test";
import dayjs from "dayjs";
import {
  buildExpiringOrderQuery,
  buildProcessingOrderQuery,
  processingStatusOptions
} from "../../user/src/views/welcome/orderNavigation.ts";

test("首页 7/30 天到期入口携带自然日区间和证书到期升序", () => {
  const now = dayjs("2026-07-26 12:00:00");

  assert.deepEqual(buildExpiringOrderQuery(7, now), {
    expires_at: ["2026-07-26", "2026-08-01"],
    sort_prop: "expires_at",
    sort_order: "asc"
  });
  assert.deepEqual(buildExpiringOrderQuery(30, now), {
    expires_at: ["2026-07-26", "2026-08-24"],
    sort_prop: "expires_at",
    sort_order: "asc"
  });
});

test("首页处理中入口覆盖四种活动状态并生成单状态筛选", () => {
  assert.deepEqual(
    processingStatusOptions.map(item => item.status),
    ["unpaid", "pending", "processing", "approving"]
  );
  assert.deepEqual(buildProcessingOrderQuery("processing"), {
    status: "processing"
  });
});
