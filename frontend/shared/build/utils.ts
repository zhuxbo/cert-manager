import dayjs from "dayjs";
import { readdir, stat } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { sum, formatBytes } from "@pureadmin/utils";

/** 启动`node`进程时所在工作目录的绝对路径 */
export const root: string = process.cwd();

/**
 * 创建构建工具函数
 * @param appRoot 应用根目录绝对路径
 * @param pkg 应用的 package.json 内容
 */
export function createBuildUtils(
  appRoot: string,
  pkg: {
    name?: string;
    version?: string;
    engines?: Record<string, string>;
    dependencies?: Record<string, string>;
    devDependencies?: Record<string, string>;
  }
) {
  /**
   * @description 根据可选的路径片段生成一个新的绝对路径
   * @param dir 路径片段，默认当前目录
   * @param metaUrl 模块的完整`url`，如果在`build`目录外调用必传`import.meta.url`
   */
  const pathResolve = (dir = ".", metaUrl = import.meta.url) => {
    // 当前文件目录的绝对路径
    const currentFileDir = dirname(fileURLToPath(metaUrl));
    // build 目录的绝对路径
    const buildDir = resolve(currentFileDir, "build");
    // 解析的绝对路径
    const resolvedPath = resolve(currentFileDir, dir);
    // 检查解析的绝对路径是否在 build 目录内
    if (resolvedPath.startsWith(buildDir)) {
      // 在 build 目录内，返回当前文件路径
      return fileURLToPath(metaUrl);
    }
    // 不在 build 目录内，返回解析后的绝对路径
    return resolvedPath;
  };

  /** 设置别名 - 基于应用根目录计算 */
  const alias: Record<string, string> = {
    "@": resolve(appRoot, "src"),
    "@build": resolve(appRoot, "build"),
    "@shared": resolve(appRoot, "../shared/src")
  };

  /** 平台的名称、版本、运行所需的`node`和`pnpm`版本、依赖、最后构建时间的类型提示 */
  const __APP_INFO__ = {
    pkg: {
      name: pkg.name,
      version: pkg.version,
      engines: pkg.engines,
      dependencies: pkg.dependencies,
      devDependencies: pkg.devDependencies
    },
    lastBuildTime: dayjs(new Date()).format("YYYY-MM-DD HH:mm:ss")
  };

  /** 处理环境变量 */
  const wrapperEnv = (envConf: Recordable): ViteEnv => {
    // 默认值
    const ret: ViteEnv = {
      VITE_PORT: 8848,
      VITE_PUBLIC_PATH: "",
      VITE_ROUTER_HISTORY: "",
      VITE_CDN: false,
      VITE_HIDE_HOME: "false",
      VITE_COMPRESSION: "none"
    };

    for (const envName of Object.keys(envConf)) {
      let realName = envConf[envName].replace(/\\n/g, "\n");
      realName =
        realName === "true" ? true : realName === "false" ? false : realName;

      if (envName === "VITE_PORT") {
        realName = Number(realName);
      }
      ret[envName] = realName;
      if (typeof realName === "string") {
        process.env[envName] = realName;
      } else if (typeof realName === "object") {
        process.env[envName] = JSON.stringify(realName);
      }
    }
    return ret;
  };

  return {
    pathResolve,
    alias,
    __APP_INFO__,
    wrapperEnv
  };
}

const fileListTotal: number[] = [];

/** 获取指定文件夹中所有文件的总大小 */
export const getPackageSize = (options: {
  folder?: string;
  callback: (size: string | number) => void;
  format?: boolean;
}) => {
  const { folder = "dist", callback, format = true } = options;
  readdir(folder, (err, files: string[]) => {
    if (err) throw err;
    let count = 0;
    const checkEnd = () => {
      ++count == files.length &&
        callback(format ? formatBytes(sum(fileListTotal)) : sum(fileListTotal));
    };
    files.forEach((item: string) => {
      stat(`${folder}/${item}`, async (err, stats) => {
        if (err) throw err;
        if (stats.isFile()) {
          fileListTotal.push(stats.size);
          checkEnd();
        } else if (stats.isDirectory()) {
          getPackageSize({
            folder: `${folder}/${item}/`,
            callback: checkEnd
          });
        }
      });
    });
    files.length === 0 && callback(0);
  });
};
