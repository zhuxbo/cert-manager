import { getCdn } from "./cdn.js";
import vue from "@vitejs/plugin-vue";
import { viteBuildInfo } from "./info.js";
import svgLoader from "vite-svg-loader";
import Icons from "unplugin-icons/vite";
import type { PluginOption } from "vite";
import vueJsx from "@vitejs/plugin-vue-jsx";
import tailwindcss from "@tailwindcss/vite";
import { configCompressPlugin } from "./compress.js";
import removeNoMatch from "vite-plugin-router-warn";
import { visualizer } from "rollup-plugin-visualizer";
import removeConsole from "vite-plugin-remove-console";
// import { vitePluginFakeServer } from "vite-plugin-fake-server";

export interface PluginsOptions {
  /** removeConsole 排除的文件路径列表 */
  removeConsoleExternal?: string[];
}

export function getPluginsList(
  VITE_CDN: boolean,
  VITE_COMPRESSION: ViteCompression,
  options: PluginsOptions = {}
): PluginOption[] {
  const lifecycle = process.env.npm_lifecycle_event;

  // 默认排除 shared http 模块
  const defaultExternal = ["src/assets/iconfont/iconfont.js"];
  const removeConsoleExternal =
    options.removeConsoleExternal ?? defaultExternal;

  return [
    tailwindcss(),
    vue(),
    // jsx、tsx语法支持
    vueJsx(),
    viteBuildInfo(),
    /**
     * 开发环境下移除非必要的vue-router动态路由警告No match found for location with path
     * 非必要具体看 https://github.com/vuejs/router/issues/521 和 https://github.com/vuejs/router/issues/359
     * vite-plugin-router-warn只在开发环境下启用，只处理vue-router文件并且只在服务启动或重启时运行一次，性能消耗可忽略不计
     */
    removeNoMatch(),
    // mock支持
    // vitePluginFakeServer({
    //   logger: false,
    //   include: "mock",
    //   infixName: false,
    //   enableProd: true
    // }),
    // svg组件化支持
    svgLoader(),
    // 自动按需加载图标
    Icons({
      compiler: "vue3",
      scale: 1
    }),
    // CDN 模式仅在构建时启用
    VITE_CDN ? getCdn() : null,
    configCompressPlugin(VITE_COMPRESSION),
    // 线上环境删除console
    removeConsole({
      external: removeConsoleExternal
    }),
    // 打包分析
    lifecycle === "report"
      ? visualizer({ open: true, brotliSize: true, filename: "report.html" })
      : (null as any)
  ];
}
