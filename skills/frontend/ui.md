# 前端通用 UI 规范

## 基础约定

### 代码组织

- 按功能模块组织
- TypeScript 类型安全
- Vue 3 Composition API
- 单文件组件 (.vue)

### API 接口

- 统一 HTTP 请求封装
- 请求响应拦截器
- 错误统一处理
- 支持请求取消

### 状态管理

- Pinia 按模块划分
- 支持持久化存储

### 样式

- Sass 预处理器
- TailwindCSS 工具类
- 响应式设计
- 主题定制化

## 轮询与视口懒加载

列表/详情页轮询统一走 `usePolling`（`@shared/hooks`），勿再手写 `setInterval + visibilitychange + 卸载清理` 样板。options：`interval`（默认 3min）、`shouldSkip`、`immediate`、`keepAlive`（keep-alive 页面额外在 `onActivated`/`onDeactivated` 装载/暂停）。回调由调用方注入，composable 不耦合任何 admin/user 专属 API。

视口懒加载（图表区进视口才加载次批数据）走 `useLazyVisible(sentinelRef, onVisible, { rootMargin })`，含 IntersectionObserver 不支持时降级直接加载、once 守卫、卸载 disconnect。

## 图表组件 (echarts)

`@shared/components/Charts` 下 LineChart/BarChart/PieChart 各自 `echarts.use([...])` 按需注册（无全局 `$echarts`，旧 `plugins/echarts.ts` 已废弃删除）。

- **首帧空数据陷阱**：LineChart 的 `yAxis` 由 `yAxisConfig` 数组 `map` 生成。父组件异步加载时首帧常传入空 `series` + 空 `yAxisConfig`（如 Dashboard 趋势图），option 变成「有 `xAxis` 但 `yAxis: []`」——echarts 没有 series 不建 Grid 坐标系、不给轴挂 `getAxesOnZeroOf`，但 x 轴视图仍渲染，抛 `axis.getAxesOnZeroOf is not a function`；数据到达后自愈（所以表现为“第一次报错、第二次正常”）。**修复：空 `yAxisConfig` 回退到单个默认 Y 轴**（见 `LineChart.vue`）。BarChart 的 yAxis 是单对象、PieChart 无 cartesian，均不受影响。
- **排查方法**：此类 echarts 报错极易误判为版本不匹配 / Vite 依赖缓存 / 双实例。最快定位是 **node SSR 最小复现**——`echarts.init(null, null, { ssr: true, renderer: "svg", width, height })` + `setOption`，不依赖浏览器/DOM。能跑通即证明包与用法无问题、矛盾在集成或数据；用真实的空数据 option 一跑即可稳定复现边界 bug。

## 详情聚合页轮询与 `order.sync` 刷新信号

`order` / `acme` 详情聚合页（`details.vue`，`v-for` 渲染多卡片 + `batchShow ids=1,2,3`）：

- **轮询必须上提到父级**：父级单个 `batchShow` 定时批量刷新（3 分钟 + `visibilitychange` 切回前台立即刷、后台跳过），替代每卡片各自 `setInterval`——否则 N 卡片 = N 并发 `show()` 风暴，切回前台时齐发。统一通过 `usePolling` 管理生命周期。
- **按 id 就地更新，不替换数组**：子卡 `reactive(props.modelValue)` 持同一引用，父级刷新走 `Object.assign(it, updated)`（按 id 匹配），不能 `details.value = fresh` 替换整个数组（已挂载子组件不会更新）。每卡片各自 UUID（`forEach` 内各 `buildUUID()` 一次，非单一全局）。
- **`order.sync`（`buildUUID`）是 order 详情的“刷新信号总线”**：子组件的派生数据（`ssl|smime/issueList` 签发记录走 `CertApi.index` 分页、`documentUpload` 文档列表）**不在 order 对象里**，各自独立 API 拉取。`Object.assign` 更新 order 主体字段能自动刷新模板绑定，但带不动子组件重拉，故 `get`/`sync`/各 `operate`/`order.vue` 刷新后 bump `order.sync`，子组件 `watch(() => order.sync)` 据此重载。**去掉信号 → 手动同步后看不到新签发证书（bug）**。
- **父级轮询不 bump `order.sync`**：只 `Object.assign` 更新 order 主体。文档变化低频、签发记录靠切 tab 的 `activeTab` watch 拉取，无需随轮询重载；手动同步/刷新/各操作仍 bump，该刷照刷。（`updated` 来自后端不含 `sync` key，`Object.assign(it, updated)` 不会覆盖 `it.sync`。）
- **acme 无此信号**：`acme/detail-card.vue` 自包含、子数据内联，`Object.assign` 直接更新视图；order 因子数据是独立分页 API 才需 `order.sync`。改动两端时勿照抄。
- **两端 `batchShow` 返回结构不同**（后端各自约定，非 bug）：admin `res.data`（纯数组）、user `res.data.items`（带 balance）。
