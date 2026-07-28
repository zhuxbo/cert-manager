# 前端表格规范

## 适用范围

用于 admin、user 与 IIFE 插件中的菜单表格、页面内嵌表格、批量选择、字典列渲染和自动刷新。

## 表格类型选择

先按承载位置选择表格实现，禁止两套方案混用：

| 类型     | 场景                                   | 组件                                                              |
| -------- | -------------------------------------- | ----------------------------------------------------------------- |
| 菜单表格 | 菜单路由对应的独立列表页               | PureAdmin 的 `PureTableBar` + `<pure-table>`                      |
| 内嵌表格 | 详情、表单、弹窗、抽屉或卡片内的子表格 | Element Plus 原生 `<el-table size="small">` + `<el-table-column>` |

内嵌表格的操作按钮统一使用 `<el-button size="small">`，保持信息密度和视觉层级；不要为了复用菜单列表能力在内嵌场景套 `PureTableBar`。

## 菜单表格

主系统在 admin / user 的 `main.ts` 全局注册 `PureTableBar`，也可从 `@shared/components` 显式导入。插件模板可直接使用；TSX 中通过 `resolveComponent()` 动态解析。

`PureTableBar` 负责列显隐、刷新和全屏。列表页将完整列定义传给 `:columns="tableColumns"`，实际表格渲染 `dynamicColumns`，刷新事件统一回到当前查询函数。

### 列表轮询与选择态

列表页自动刷新统一使用 `usePolling`。存在批量选择时传 `shouldSkip: () => selectedIds.value.length > 0`，避免刷新后选择对象与当前数据错位；keep-alive 列表按需启用 `keepAlive`。

### 字典列显示

- 用 `options.find()?.label` 渲染字典值时，必须用 `?? row.xxx` 回落显示原值，防止插件卸载后字典不全导致空白。
- 插件通过 `dictionaries` 机制（`mergePluginDictionaries`）在运行时追加 options 和 map；主系统表格不得假定扩展字典永远存在。

## 插件兼容

`PureTableBar` 在 admin / user 两端都可用。插件不得重复打包共享组件或依赖自身注册顺序，使用方式与其他全局组件一致；完整 IIFE 约束见 `../plugins/frontend.md`。
