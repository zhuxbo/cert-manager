import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import test from "node:test";
import { fileURLToPath } from "node:url";
import path from "node:path";

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, "../../../..");
const componentPath = path.join(here, "AutoDeployReports.vue");

test("管理端和用户端订单详情使用可分页筛选的内嵌部署记录组件", () => {
  assert.equal(existsSync(componentPath), true);

  const component = readFileSync(componentPath, "utf8");
  assert.match(component, /部署时间/);
  assert.match(component, /部署 IP/);
  assert.match(component, /说明/);
  assert.match(component, /结果/);
  assert.match(component, /row\.deployed_at \|\| row\.created_at/);
  assert.match(component, /<el-pagination/);
  assert.match(component, /v-model="search\.time"/);
  assert.match(component, /v-model="search\.ip"/);
  assert.match(component, /v-model="search\.status"/);
  assert.match(component, /<el-form-item label="部署时间"/);
  assert.match(component, /<el-form-item label="部署 IP"/);
  assert.equal(component.match(/<el-table-column/g)?.length, 4);
  assert.doesNotMatch(component, /PureTableBar|PlusSearch|pure-table/);
  assert.doesNotMatch(component, /\sstripe(?:\s|>)/);
  assert.match(component, /pageSize: 10/);
  assert.doesNotMatch(component, /pagination\.pageSize = data\.pageSize/);
  assert.match(component, /latestRequestId/);
  assert.match(component, /<el-form[^>]+size="small"/);
  assert.match(component, /<el-table[\s\S]+size="small"/);
  const pagination = component.match(/<el-pagination[\s\S]*?\/>/)?.[0] ?? "";
  assert.match(pagination, /size="small"/);
  assert.doesNotMatch(pagination, /\n\s+small\s*\n/);
  assert.match(
    component,
    /\.report-search-actions[\s\S]+margin-right: 0 !important;/
  );
  assert.match(component, /max-height="460"/);
  for (const className of [
    "report-search-time",
    "report-search-ip",
    "report-search-result"
  ]) {
    const rule = component.match(
      new RegExp(`\\.${className}\\s*\\{([^}]*)\\}`)
    )?.[1];
    assert.ok(rule, `${className} 应存在独立样式规则`);
    assert.match(rule, /flex:\s*[\d.]+\s+[\d.]+\s+\d+px;/);
    assert.match(rule, /min-width:\s*\d+px;/);
  }
  assert.doesNotMatch(component, /证书 ID/);
  assert.doesNotMatch(component, /上报时间/);

  for (const app of ["admin", "user"]) {
    const index = readFileSync(
      path.join(
        repo,
        `frontend/${app}/src/views/order/detail/components/ssl/index.vue`
      ),
      "utf8"
    );
    const cert = readFileSync(
      path.join(
        repo,
        `frontend/${app}/src/views/order/detail/components/ssl/cert.vue`
      ),
      "utf8"
    );
    const deploy = readFileSync(
      path.join(
        repo,
        `frontend/${app}/src/views/order/detail/components/ssl/deploy.vue`
      ),
      "utf8"
    );

    assert.doesNotMatch(index, /AutoDeployReports/);
    assert.match(deploy, /label="部署记录" name="reports" lazy/);
    assert.match(deploy, /:load="AutoDeployReportApi\.index"/);
    assert.match(deploy, /:order-id="order\.id"/);
    assert.doesNotMatch(cert, /auto_deploy_at/);
  }
});
