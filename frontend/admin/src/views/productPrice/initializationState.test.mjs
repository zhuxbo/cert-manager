import assert from "node:assert/strict";
import test from "node:test";

import {
  buildForceConfirmation,
  canAcceptLevelList,
  canAcceptExecutionResult,
  canExecuteCurrentPreview,
  canExecutePreview,
  cloneExecutionRequest,
  createPreviewState,
  loadAllLevels,
  makeSelectedCodesRevision,
  makeInitializationRevision,
  mapSelectedLevelDetails,
  mergeSelectedLevels,
  normalizeInitializationParams,
  syncCostRatesHint
} from "./initializationState.ts";

const profile = (code, name, custom, costRate) => ({
  id: code.length,
  code,
  name,
  custom,
  cost_rate: costRate,
  weight: 100
});

test("基础和定制选择跨切换保留，定制项覆盖同 code 并稳定排序", () => {
  const result = mergeSelectedLevels(
    [
      { code: "standard", name: "标准", custom: 0, cost_rate: "1.0000" },
      { code: "shared", name: "基础同名", custom: 0, cost_rate: "1.1000" }
    ],
    [
      { code: "vip", name: "VIP", custom: 1, cost_rate: "1.2000" },
      { code: "shared", name: "定制同名", custom: 1, cost_rate: "1.3000" }
    ]
  );

  assert.deepEqual(
    result.map(item => item.code),
    ["shared", "standard", "vip"]
  );
  assert.equal(result[0].name, "定制同名");
  assert.equal(result[0].cost_rate, "1.3000");
});

test("两类 code 仅从完整级别资料映射名称、类型和默认倍率", () => {
  const result = mapSelectedLevelDetails(
    ["base-b", "base-a"],
    ["custom-a"],
    [
      profile("custom-a", "客户甲", 1, "1.3456"),
      profile("base-a", "基础甲", 0, "1.1000"),
      profile("base-b", "基础乙", 0, "1.2000")
    ]
  );

  assert.deepEqual(result, [
    { code: "base-a", name: "基础甲", custom: 0, cost_rate: "1.1000" },
    { code: "base-b", name: "基础乙", custom: 0, cost_rate: "1.2000" },
    { code: "custom-a", name: "客户甲", custom: 1, cost_rate: "1.3456" }
  ]);
});

test("完整资料刷新时保留客户已经逐级修改的倍率", () => {
  const result = mapSelectedLevelDetails(
    ["base-a"],
    ["custom-a"],
    [
      profile("base-a", "基础甲", 0, "1.1000"),
      profile("custom-a", "客户甲", 1, "1.3000")
    ],
    new Map([["base-a", "2.2500"]])
  );

  assert.equal(result[0].cost_rate, "2.2500");
  assert.equal(result[1].cost_rate, "1.3000");
});

test("分页累计 12 个基础级别并默认全选，不额外请求第三页", async () => {
  const calls = [];
  const pages = {
    1: Array.from({ length: 10 }, (_, index) =>
      profile(`base-${index + 1}`, `基础 ${index + 1}`, 0, "1.0000")
    ),
    2: [
      profile("base-11", "基础 11", 0, "1.0000"),
      profile("base-12", "基础 12", 0, "1.0000")
    ]
  };

  const result = await loadAllLevels(async (currentPage, pageSize) => {
    calls.push([currentPage, pageSize]);
    return { items: pages[currentPage] ?? [], total: 12 };
  }, 10);

  assert.equal(result.length, 12);
  assert.deepEqual(
    result.map(item => item.code),
    Array.from({ length: 12 }, (_, index) => `base-${index + 1}`).sort()
  );
  assert.deepEqual(calls, [
    [1, 10],
    [2, 10]
  ]);
});

test("分页超过 100 页时仍按稳定 total 完整加载", async () => {
  let calls = 0;
  const total = 10001;

  const result = await loadAllLevels(
    async (currentPage, pageSize) => {
      calls += 1;
      const offset = (currentPage - 1) * pageSize;
      const count = Math.min(pageSize, total - offset);

      return {
        items: Array.from({ length: count }, (_, index) =>
          profile(
            `base-${offset + index + 1}`,
            `基础 ${offset + index + 1}`,
            0,
            "1.0000"
          )
        ),
        total
      };
    },
    100
  );

  assert.equal(calls, 101);
  assert.equal(result.length, total);
});

test("分页 total 变化时拒绝混合两份列表快照", async () => {
  let calls = 0;

  await assert.rejects(
    loadAllLevels(async (currentPage, pageSize) => {
      calls += 1;
      const offset = (currentPage - 1) * pageSize;
      return {
        items: Array.from({ length: pageSize }, (_, index) =>
          profile(
            `base-${offset + index + 1}`,
            `基础 ${offset + index + 1}`,
            0,
            "1.0000"
          )
        ),
        total: currentPage === 1 ? 200 : 300
      };
    }, 100),
    /total.*变化/i
  );

  assert.equal(calls, 2);
});

test("取消后重新选择仍使用客户编辑过的倍率", () => {
  const profiles = [
    profile("base-a", "基础甲", 0, "1.1000"),
    profile("custom-a", "客户甲", 1, "1.3000")
  ];
  const overrides = new Map([["custom-a", "2.5000"]]);

  const afterCancel = mapSelectedLevelDetails(
    ["base-a"],
    [],
    profiles,
    overrides
  );
  const afterReselect = mapSelectedLevelDetails(
    ["base-a"],
    ["custom-a"],
    profiles,
    overrides
  );

  assert.deepEqual(afterCancel.map(item => item.code), ["base-a"]);
  assert.equal(
    afterReselect.find(item => item.code === "custom-a")?.cost_rate,
    "2.5000"
  );
});

test("完整资料映射发现任一所选 code 缺失时拒绝继续", () => {
  assert.throws(
    () =>
      mapSelectedLevelDetails(
        ["base-a", "base-missing"],
        ["custom-a"],
        [
          profile("base-a", "基础甲", 0, "1.1000"),
          profile("custom-a", "客户甲", 1, "1.3000")
        ]
      ),
    /base-missing/
  );
});

test("规范参数不限制十个级别并固定四位小数", () => {
  const params = normalizeInitializationParams({
    levels: Array.from({ length: 12 }, (_, index) => ({
      code: `level-${index + 1}`,
      cost_rate: index === 0 ? 1.2 : "1.0000"
    })),
    precision: 2,
    force: false,
    sync_cost_rates: false,
    preview: true
  });

  assert.equal(params.levels.length, 12);
  assert.equal(
    params.levels.find(item => item.code === "level-1")?.cost_rate,
    "1.2000"
  );
});

test("revision 覆盖嵌套倍率和全部业务参数，并忽略级别顺序", () => {
  const base = {
    levels: [
      { code: "b", cost_rate: "1.2000" },
      { code: "a", cost_rate: "1.1000" }
    ],
    precision: 2,
    force: false,
    sync_cost_rates: false,
    preview: true
  };
  const revision = makeInitializationRevision(base);

  assert.equal(
    revision,
    makeInitializationRevision({ ...base, levels: [...base.levels].reverse() })
  );
  assert.notEqual(
    revision,
    makeInitializationRevision({
      ...base,
      levels: [
        { code: "b", cost_rate: "1.2001" },
        { code: "a", cost_rate: "1.1000" }
      ]
    })
  );
  assert.notEqual(
    revision,
    makeInitializationRevision({ ...base, precision: 1 })
  );
  assert.notEqual(
    revision,
    makeInitializationRevision({ ...base, force: true })
  );
  assert.notEqual(
    revision,
    makeInitializationRevision({ ...base, sync_cost_rates: true })
  );
});

test("旧 revision 的乱序预览响应不能覆盖新参数", () => {
  const state = createPreviewState();
  const oldRevision = state.begin({
    levels: [{ code: "base", cost_rate: "1.0000" }],
    precision: 2,
    force: false,
    sync_cost_rates: false,
    preview: true
  });
  const newRevision = state.begin({
    levels: [{ code: "base", cost_rate: "1.0000" }],
    precision: 1,
    force: false,
    sync_cost_rates: false,
    preview: true
  });

  assert.equal(
    state.accept(oldRevision, {
      can_execute: true,
      preview_token: "old-token",
      warnings: []
    }),
    false
  );
  assert.equal(
    state.accept(newRevision, {
      can_execute: true,
      preview_token: "new-token",
      warnings: []
    }),
    true
  );
  assert.equal(state.snapshot().result?.preview_token, "new-token");
});

test("相同参数连续预览时，旧请求响应不能覆盖新 token", () => {
  const state = createPreviewState();
  const params = {
    levels: [{ code: "base", cost_rate: "1.0000" }],
    precision: 2,
    force: false,
    sync_cost_rates: false,
    preview: true
  };
  const oldRequest = state.begin(params);
  const newRequest = state.begin(params);

  assert.equal(oldRequest.revision, newRequest.revision);
  assert.notEqual(oldRequest.requestId, newRequest.requestId);
  assert.equal(
    state.accept(oldRequest, {
      can_execute: true,
      preview_token: "old-token",
      warnings: []
    }),
    false
  );
  assert.equal(
    state.accept(newRequest, {
      can_execute: true,
      preview_token: "new-token",
      warnings: []
    }),
    true
  );
  assert.equal(state.snapshot().result?.preview_token, "new-token");
});

test("旧请求 finally 不能提前清除新请求的 loading", () => {
  const state = createPreviewState();
  const params = {
    levels: [{ code: "base", cost_rate: "1.0000" }],
    precision: 2,
    force: false,
    sync_cost_rates: false,
    preview: true
  };
  const oldRequest = state.begin(params);
  const newRequest = state.begin(params);
  let loading = true;

  if (state.isLatest(oldRequest)) loading = false;
  assert.equal(loading, true);

  if (state.isLatest(newRequest)) loading = false;
  assert.equal(loading, false);
});

test("warning、can_execute=false 或无 token 均禁止执行", () => {
  assert.equal(
    canExecutePreview({
      can_execute: true,
      preview_token: "token",
      warnings: [{ message: "成本缺失" }]
    }),
    false
  );
  assert.equal(
    canExecutePreview({
      can_execute: false,
      preview_token: "token",
      warnings: []
    }),
    false
  );
  assert.equal(
    canExecutePreview({ can_execute: true, preview_token: "", warnings: [] }),
    false
  );
});

test("原始 code 取消或新增后旧预览立即不可执行", () => {
  const selectedLevels = [
    { code: "base-a", name: "基础甲", custom: 0, cost_rate: "1.1000" },
    { code: "base-b", name: "基础乙", custom: 0, cost_rate: "1.2000" }
  ];
  const frozen = cloneExecutionRequest(
    {
      levels: selectedLevels,
      precision: 2,
      force: false,
      sync_cost_rates: false,
      preview: true
    },
    "preview-token"
  );
  const preview = {
    can_execute: true,
    preview_token: "preview-token",
    warnings: []
  };
  const currentRevision = makeInitializationRevision({
    levels: selectedLevels,
    precision: 2,
    force: false,
    sync_cost_rates: false,
    preview: true
  });

  assert.equal(
    canExecuteCurrentPreview(
      preview,
      frozen,
      ["base-a", "base-b"],
      [],
      selectedLevels,
      currentRevision
    ),
    true
  );
  assert.equal(
    canExecuteCurrentPreview(
      preview,
      frozen,
      ["base-a"],
      [],
      selectedLevels,
      currentRevision
    ),
    false
  );
  assert.equal(
    canExecuteCurrentPreview(
      preview,
      frozen,
      ["base-a", "base-b", "base-c"],
      [],
      selectedLevels,
      currentRevision
    ),
    false
  );
});

test("关闭并重开后拒绝旧的基础或定制列表响应", () => {
  assert.equal(canAcceptLevelList(1, 2, true), false);
  assert.equal(canAcceptLevelList(2, 2, false), false);
  assert.equal(canAcceptLevelList(2, 2, true), true);
});

test("code 集合 revision 忽略顺序，但识别新增或取消", () => {
  const original = makeSelectedCodesRevision(["base-b", "base-a"], ["vip"]);
  const reordered = makeSelectedCodesRevision(["base-a", "base-b"], ["vip"]);
  const added = makeSelectedCodesRevision(
    ["base-a", "base-b", "base-c"],
    ["vip"]
  );

  assert.equal(original, reordered);
  assert.notEqual(original, added);
});

test("执行只有 executed=true 才接受；stale 和成本校验均要求保留弹窗重预览", () => {
  assert.deepEqual(
    canAcceptExecutionResult({ executed: true, reason: null, warnings: [] }),
    { accepted: true, close: true, invalidate: false }
  );
  assert.deepEqual(
    canAcceptExecutionResult({
      executed: false,
      reason: "stale_preview",
      warnings: []
    }),
    { accepted: false, close: false, invalidate: true }
  );
  assert.deepEqual(
    canAcceptExecutionResult({
      executed: false,
      reason: "cost_validation",
      warnings: [{ message: "成本缺失" }]
    }),
    { accepted: false, close: false, invalidate: true }
  );
});

test("执行请求使用预览时冻结的深拷贝参数", () => {
  const editing = {
    levels: [{ code: "base", cost_rate: "1.1000" }],
    precision: 2,
    force: false,
    sync_cost_rates: false,
    preview: true
  };
  const frozen = cloneExecutionRequest(editing, "preview-token");

  editing.levels[0].cost_rate = "9.9999";
  editing.force = true;

  assert.equal(frozen.levels[0].cost_rate, "1.1000");
  assert.equal(frozen.force, false);
  assert.equal(frozen.preview, false);
  assert.equal(frozen.preview_token, "preview-token");
  assert.ok(Object.isFrozen(frozen));
  assert.ok(Object.isFrozen(frozen.levels));
  assert.ok(Object.isFrozen(frozen.levels[0]));
});

test("强制确认包含红色危险语义、全部级别 name/code 和实际统计", () => {
  const confirmation = buildForceConfirmation(
    [
      { code: "base", name: "基础", custom: 0, cost_rate: "1.0000" },
      { code: "vip", name: "VIP客户", custom: 1, cost_rate: "1.2000" }
    ],
    { deleted_count: 18, rebuilt_count: 24 }
  );

  assert.match(confirmation, /color:\s*#(?:f56c6c|ff0000)|红色危险/i);
  assert.match(confirmation, /基础（base）/);
  assert.match(confirmation, /VIP客户（vip）/);
  assert.match(confirmation, /删除[^0-9]*18/);
  assert.match(confirmation, /重建[^0-9]*24/);
});

test("同步倍率但不强制时提示既有价格不会变化", () => {
  assert.match(syncCostRatesHint(false, true), /仅新增价格/);
  assert.match(syncCostRatesHint(false, true), /既有价格不会/);
  assert.equal(syncCostRatesHint(true, true), "");
  assert.equal(syncCostRatesHint(false, false), "");
});
