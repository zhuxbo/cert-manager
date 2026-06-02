import App from "./App.vue";
import router, { constantMenus } from "./router";
import { setupStore } from "@/store";
import { getPlatformConfig } from "./config";
import { MotionPlugin } from "@vueuse/motion";
import { createApp, type Directive } from "vue";
import { useElementPlus } from "@/plugins/elementPlus";
import {
  injectResponsiveStorage,
  fetchMeta,
  renderChannelDisabled
} from "@shared/utils";
import { routerArrays } from "@/layout/types";
import {
  initPluginSystem,
  exposeSharedDeps,
  loadPlugins,
  mergePluginDictionaries
} from "@shared/utils/plugin-loader";
import { http as sharedHttp } from "@shared/utils/http";

import Table from "@pureadmin/table";
// import PureDescriptions from "@pureadmin/descriptions";

// 初始化插件系统
initPluginSystem();

// 引入重置样式
import "./style/reset.scss";
// 导入公共样式
import "./style/index.scss";
// 一定要在main.ts中导入tailwind.css，防止vite每次hmr都会请求src/style/index.scss整体css文件导致热更新慢的问题
import "./style/tailwind.css";
import "element-plus/dist/index.css";
// 导入字体图标
import "./assets/iconfont/iconfont.js";
import "./assets/iconfont/iconfont.css";

const app = createApp(App);

// 自定义指令
import * as directives from "@shared/directives";
Object.keys(directives).forEach(key => {
  app.directive(key, (directives as { [key: string]: Directive })[key]);
});

// 全局注册@iconify/vue图标库
import {
  IconifyIconOffline,
  IconifyIconOnline,
  FontIcon
} from "@shared/components/ReIcon";
app.component("IconifyIconOffline", IconifyIconOffline);
app.component("IconifyIconOnline", IconifyIconOnline);
app.component("FontIcon", FontIcon);

// 全局注册按钮级别权限组件
import { Auth, Perms, PureTableBar } from "@shared/components";
app.component("Auth", Auth);
app.component("Perms", Perms);
app.component("PureTableBar", PureTableBar);

// 全局加载 PlusSearch 样式（插件依赖）
import "plus-pro-components/es/components/search/style/css";

// 全局注册vue-tippy
import "tippy.js/dist/tippy.css";
import "tippy.js/themes/light.css";
import VueTippy from "vue-tippy";
app.use(VueTippy);

getPlatformConfig(app).then(async config => {
  // 启动期检测 channels（前端编排）
  // 后端 channels.user=false 时跳过主应用初始化，渲染降级页
  // 注意：app.unmount() 释放 createApp(App) 已经创建的实例，避免持有 router/store 等模块的孤儿引用
  const meta = await fetchMeta();
  if (meta && meta.channels.user === false) {
    renderChannelDisabled("user");
    app.unmount();
    return;
  }

  setupStore(app);
  // 初始化 shared 模块（auth 和 http）
  const { setupSharedModules } = await import("@/utils/setup");
  setupSharedModules();
  await exposeSharedDeps();
  // 暴露主应用 http 给插件使用（已认证、带 token 刷新）
  window.__deps.http = sharedHttp;
  // ACME 菜单配置控制：默认显示，platform-config.json 显式 false 时隐藏
  if (config.Acme === false) {
    const acmeMenu = constantMenus.find((r: any) => r.name === "Acmes") as any;
    if (acmeMenu?.meta) acmeMenu.meta.showLink = false;
  }
  // 加载插件（在 router 安装之前，确保菜单数据就绪）
  await loadPlugins(router, "user", constantMenus);
  app.use(router);
  await router.isReady();
  injectResponsiveStorage(app, config, { routerArrays });
  app.use(MotionPlugin).use(useElementPlus).use(Table);
  // .use(PureDescriptions)

  // 合并插件字典
  const [fundsDict, transactionDict, orderDict, systemDict] = await Promise.all(
    [
      import("@/views/funds/dictionary"),
      import("@/views/transaction/dictionary"),
      import("@/views/order/dictionary"),
      import("@/views/system/dictionary")
    ]
  );
  mergePluginDictionaries({
    funds: fundsDict,
    transaction: transactionDict,
    order: orderDict,
    system: systemDict
  });

  app.mount("#app");
});
