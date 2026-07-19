import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import test from "node:test";
import { fileURLToPath } from "node:url";
import path from "node:path";

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, "../../../..");

test("管理端和用户端使用自动部署顶级菜单并提供部署记录页面", () => {
  const reportListPath = path.join(here, "AutoDeployReportList.vue");
  assert.equal(existsSync(reportListPath), true);

  const reportList = readFileSync(reportListPath, "utf8");
  assert.match(reportList, /<PlusSearch/);
  assert.match(reportList, /<PureTableBar/);
  assert.match(reportList, /<pure-table/);
  assert.match(reportList, /订单ID\/用户名\/邮箱\/域名\/IP\/失败信息/);
  assert.match(reportList, /订单ID\/域名\/IP\/失败信息/);
  assert.doesNotMatch(reportList, /<el-table/);
  assert.doesNotMatch(reportList, /<el-pagination/);

  const tableColumns = reportList.match(
    /const tableColumns = computed[\s\S]*?function onSearch/
  )?.[0];
  assert.ok(tableColumns);
  assert.match(tableColumns, /label: "ID"/);
  assert.match(
    tableColumns,
    /label: "用户名",[\s\S]*?prop: "order\.user\.username",[\s\S]*?width: 120/
  );
  assert.doesNotMatch(tableColumns, /label: "证书 ID"/);
  assert.doesNotMatch(tableColumns, /label: "上报 IP"/);
  assert.doesNotMatch(tableColumns, /label: "失败信息"/);
  assert.doesNotMatch(tableColumns, /label: "上报时间"/);
  assert.match(tableColumns, /row\.deployed_at \|\| row\.created_at/);
  assert.match(
    reportList,
    /function handleSearch\(\) \{\s*pagination\.currentPage = 1;\s*onSearch\(\);\s*\}/
  );
  assert.match(reportList, /@search="handleSearch"/);
  assert.match(reportList, /const onReset = \(\) => \{\s*handleSearch\(\);\s*\}/);

  for (const app of ["admin", "user"]) {
    const route = readFileSync(
      path.join(repo, `frontend/${app}/src/router/modules/delegation.ts`),
      "utf8"
    );
    const acmeRoute = readFileSync(
      path.join(repo, `frontend/${app}/src/router/modules/acme.ts`),
      "utf8"
    );
    const page = path.join(
      repo,
      `frontend/${app}/src/views/auto-deploy/reports.vue`
    );

    assert.match(route, /title: "自动部署"/);
    assert.match(route, /title: "域名委托"/);
    assert.match(route, /title: "部署记录"/);
    const autoDeployRank = Number(route.match(/rank: ([\d.]+)/)?.[1]);
    const acmeRank = Number(acmeRoute.match(/rank: ([\d.]+)/)?.[1]);
    assert.ok(acmeRank < autoDeployRank);
    assert.equal(existsSync(page), true);
  }
});
