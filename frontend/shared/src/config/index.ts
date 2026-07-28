import axios from "axios";
import type { App } from "vue";
import type { PlatformConfigs } from "./types";
import { mergePlatformConfigSources } from "./merge";

let config: PlatformConfigs = {};

const setConfig = (cfg?: PlatformConfigs) => {
  config = Object.assign(config, cfg);
};

const getConfig = (key?: string): any => {
  if (typeof key === "string") {
    const arr = key.split(".");
    if (arr && arr.length) {
      let data: any = config;
      arr.forEach(v => {
        if (data && typeof data[v] !== "undefined") {
          data = data[v];
        } else {
          data = null;
        }
      });
      return data;
    }
  }
  return config;
};

/** 获取项目动态全局配置 */
export const getPlatformConfig = async (
  app: App,
  backendConfig?: PlatformConfigs
): Promise<PlatformConfigs> => {
  app.config.globalProperties.$config = getConfig();
  const publicPath = import.meta.env.VITE_PUBLIC_PATH || "/";
  return axios({
    method: "get",
    url: `${publicPath}platform-config.json`
  })
    .then(({ data: configData }) => {
      let $config = app.config.globalProperties.$config;
      // 自动注入系统配置
      if (app && $config && typeof configData === "object") {
        $config = Object.assign(
          $config,
          mergePlatformConfigSources(configData, backendConfig)
        );
        app.config.globalProperties.$config = $config;
        // 设置全局配置
        setConfig($config);
      }
      // 检测必要配置项
      const requiredKeys = ["StorageNameSpace", "ResponsiveStorageNameSpace"];
      const missing = requiredKeys.filter(key => !(key in configData));
      if (missing.length > 0) {
        console.warn(`[platform-config] 缺少配置项: ${missing.join(", ")}`);
      }
      return $config;
    })
    .catch(() => {
      throw "请在public文件夹下添加platform-config.json配置文件";
    });
};

/** 本地响应式存储的命名空间 */
const responsiveStorageNameSpace = () => getConfig().ResponsiveStorageNameSpace;

/** 获取存储命名空间前缀 */
const storageNameSpace = () => getConfig().StorageNameSpace || "";

export { getConfig, setConfig, responsiveStorageNameSpace, storageNameSpace };

export * from "./types";
