import assert from "node:assert/strict";
import test from "node:test";

import { buildStructureWarningView } from "./structureWarning.ts";

const emptySummary = () => ({
  missing_tables: [],
  extra_tables: [],
  missing_columns: [],
  extra_columns: [],
  modified_columns: [],
  missing_indexes: [],
  extra_indexes: [],
  modified_indexes: [],
  missing_foreign_keys: [],
  extra_foreign_keys: [],
  modified_foreign_keys: [],
  manual_actions: [],
  can_auto_fix: false
});

test("默认摘要只显示真实待处理差异的数量", () => {
  const summary = emptySummary();
  summary.missing_tables = ["users_archive"];
  summary.extra_tables = ["agisos", "cloud_deploy_logs"];
  summary.modified_columns = [
    "users.email: (未知差异)",
    "transactions.dedup_key: (未知差异)"
  ];

  const view = buildStructureWarningView(summary);

  assert.equal(view.message, "发现 1 张缺失表、2 个字段差异");
  assert.doesNotMatch(view.message, /agisos|cloud_deploy|未知差异|多余表/);
});

test("插件额外表只进入详情且旧状态中的删除建议会被隐藏", () => {
  const summary = emptySummary();
  summary.extra_tables = ["agisos", "easy_logs"];
  summary.manual_actions = ["删除多余表 agisos", "修改列 users.email"];

  const view = buildStructureWarningView(summary);

  assert.equal(view.message, "核心数据库结构无待处理差异");
  assert.deepEqual(view.details, ["未纳入核心结构的表：agisos, easy_logs"]);
  assert.deepEqual(view.manualActions, ["修改列 users.email"]);
});

test("旧版本升级状态缺少新增摘要字段时仍可展示", () => {
  const summary = emptySummary();
  summary.missing_columns = ["users.email"];
  delete summary.modified_indexes;
  delete summary.modified_foreign_keys;
  delete summary.manual_actions;

  const view = buildStructureWarningView(summary);

  assert.equal(view.message, "发现 1 个缺失字段");
  assert.deepEqual(view.manualActions, []);
});
