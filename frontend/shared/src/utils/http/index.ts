import Axios, {
  type AxiosInstance,
  type AxiosRequestConfig,
  type CustomParamsSerializer
} from "axios";
import type {
  PureHttpError,
  RequestMethods,
  PureHttpResponse,
  PureHttpRequestConfig,
  HttpStoreHooks
} from "./types";
import { stringify } from "qs";
import NProgress from "../progress";
import { getToken, formatToken } from "../auth";
import { getConfig } from "../../config";
import { message } from "../message";
import { messageBox } from "../messageBox";

export type * from "./types";

// 相关配置请参考：www.axios-js.com/zh-cn/docs/#axios-request-config-1
const defaultConfig: AxiosRequestConfig = {
  // 请求超时时间
  timeout: 10000,
  headers: {
    Accept: "application/json, text/plain, */*",
    "Content-Type": "application/json",
    "X-Requested-With": "XMLHttpRequest",
    "X-Timezone": Intl.DateTimeFormat().resolvedOptions().timeZone
  },
  // 数组格式参数序列化（https://github.com/axios/axios/issues/5142）
  paramsSerializer: {
    serialize: stringify as unknown as CustomParamsSerializer
  }
};

/** 全局 store hooks */
let storeHooks: HttpStoreHooks | null = null;

/** 全局 http 实例 */
let httpInstance: PureHttp | null = null;

class PureHttp {
  constructor() {
    this.httpInterceptorsRequest();
    this.httpInterceptorsResponse();
  }

  /** `token`过期后，暂存待执行的请求 */
  private static requests: Array<(token: string) => void> = [];

  /** 防止重复刷新`token` */
  private static isRefreshing = false;

  /** 初始化配置对象 */
  private static initConfig: PureHttpRequestConfig = {};

  /** 保存当前`Axios`实例对象 */
  private static axiosInstance: AxiosInstance = Axios.create(defaultConfig);

  /** 重连原始请求 */
  private static retryOriginalRequest(config: PureHttpRequestConfig) {
    return new Promise(resolve => {
      PureHttp.requests.push((token: string) => {
        config.headers!["Authorization"] = formatToken(token);
        resolve(config);
      });
    });
  }

  /** 请求拦截 */
  private httpInterceptorsRequest(): void {
    PureHttp.axiosInstance.interceptors.request.use(
      async (config: PureHttpRequestConfig): Promise<any> => {
        // 开启进度条动画
        NProgress.start();
        // 优先判断post/get等方法是否传入回调，否则执行初始化设置等回调
        if (typeof config.beforeRequestCallback === "function") {
          config.beforeRequestCallback(config);
          return config;
        }
        if (PureHttp.initConfig.beforeRequestCallback) {
          PureHttp.initConfig.beforeRequestCallback(config);
          return config;
        }
        // 过滤 GET 请求参数
        if (
          config.params &&
          typeof config.params === "object" &&
          !Array.isArray(config.params)
        ) {
          config.params = Object.fromEntries(
            Object.entries(config.params).filter(([key, value]) => {
              // 过滤掉 空字符串 null 空数组
              if (Array.isArray(value) && value.length === 0) return false;
              if (value === "" || value === null) return false;
              // 过滤掉默认的分页参数
              if (key === "pageSize" && value === 10) return false;
              if (key === "currentPage" && value === 1) return false;
              return true;
            })
          );
        }
        // FormData 需要浏览器自动设置 Content-Type（含 boundary）
        if (config.data instanceof FormData) {
          delete config.headers!["Content-Type"];
        }
        // 过滤 POST 请求数据（跳过 FormData）
        if (
          config.data &&
          typeof config.data === "object" &&
          !Array.isArray(config.data) &&
          !(config.data instanceof FormData)
        ) {
          config.data = Object.fromEntries(
            Object.entries(config.data).filter(([_, value]) => {
              // 过滤掉 null
              if (value === null) return false;
              return true;
            })
          );
        }
        /** 请求白名单，放置一些不需要`token`的接口 */
        const whiteList = ["/refresh-token", "/login"];
        return whiteList.some(url => config.url!.endsWith(url))
          ? config
          : new Promise(resolve => {
              const data = getToken();
              if (data) {
                const now = new Date().getTime();
                const expiresTime = (() => {
                  if (typeof data.expires_in === "number") {
                    return data.expires_in;
                  }
                  // 尝试转换为数字
                  const timestamp = Number(data.expires_in);
                  // 如果是有效的时间戳数字
                  if (!isNaN(timestamp) && timestamp > 0) {
                    return timestamp;
                  }
                  // 否则当作 ISO 时间字符串处理
                  return new Date(
                    data.expires_in as unknown as string
                  ).getTime();
                })();
                const expired = expiresTime - now <= 0;
                if (expired) {
                  if (!PureHttp.isRefreshing) {
                    PureHttp.isRefreshing = true;
                    // token过期刷新
                    if (storeHooks) {
                      storeHooks
                        .refreshToken({ refresh_token: data.refresh_token })
                        .then(res => {
                          const token = res.data.access_token;
                          config.headers!["Authorization"] = formatToken(token);
                          PureHttp.requests.forEach(cb => cb(token));
                          PureHttp.requests = [];
                        })
                        .catch(() => {
                          // 刷新失败，清空请求队列并退出登录
                          if (storeHooks) storeHooks.logout();
                          PureHttp.requests = [];
                        })
                        .finally(() => {
                          PureHttp.isRefreshing = false;
                        });
                    } else {
                      // storeHooks 未初始化，重置状态避免死锁
                      PureHttp.isRefreshing = false;
                      PureHttp.requests = [];
                    }
                  }
                  resolve(PureHttp.retryOriginalRequest(config));
                } else {
                  config.headers!["Authorization"] = formatToken(
                    data.access_token
                  );
                  resolve(config);
                }
              } else {
                resolve(config);
              }
            });
      },
      error => {
        return Promise.reject(error);
      }
    );
  }

  /** 响应拦截 */
  private httpInterceptorsResponse(): void {
    const instance = PureHttp.axiosInstance;
    instance.interceptors.response.use(
      (response: PureHttpResponse) => {
        const $config = response.config;
        // 关闭进度条动画
        NProgress.done();
        // 优先判断post/get等方法是否传入回调，否则执行初始化设置等回调
        if (typeof $config.beforeResponseCallback === "function") {
          $config.beforeResponseCallback(response);
          return response.data;
        }
        if (PureHttp.initConfig.beforeResponseCallback) {
          PureHttp.initConfig.beforeResponseCallback(response);
          return response.data;
        }
        // 返回错误信息则抛出错误
        if (response.data.code === 0) {
          if (!$config.suppressErrorMessage) {
            message(response.data.msg, { type: "error" });
            response.data?.errors &&
              messageBox(response.data?.msg, response.data?.errors);
          }
          return Promise.reject({ response: response });
        }
        return response.data;
      },
      (error: PureHttpError) => {
        if (error.response?.status === 401) {
          const originalRequest = error.config as any;
          const requestUrl: string = originalRequest?.url || "";
          // 若是刷新接口本身返回401，直接登出，避免循环
          if (requestUrl.endsWith("/refresh-token")) {
            if (storeHooks) storeHooks.logout();
            NProgress.done();
            return Promise.reject(error);
          }

          // 避免同一请求重复触发刷新逻辑
          if (originalRequest._retry) {
            if (storeHooks) storeHooks.logout();
            NProgress.done();
            return Promise.reject(error);
          }
          originalRequest._retry = true;

          // 统一通过队列 + isRefreshing 处理401刷新
          if (!PureHttp.isRefreshing) {
            PureHttp.isRefreshing = true;
            const tokenData = getToken();
            if (tokenData?.refresh_token && storeHooks) {
              storeHooks
                .refreshToken({ refresh_token: tokenData.refresh_token })
                .then(res => {
                  const token = res.data.access_token;
                  PureHttp.requests.forEach(cb => cb(token));
                  PureHttp.requests = [];
                })
                .catch(() => {
                  if (storeHooks) storeHooks.logout();
                  PureHttp.requests = [];
                })
                .finally(() => {
                  PureHttp.isRefreshing = false;
                });
            } else {
              if (storeHooks) storeHooks.logout();
              NProgress.done();
              return Promise.reject(error);
            }
          }

          // 等待刷新完成后重放原请求
          return PureHttp.retryOriginalRequest(originalRequest).then(
            (config: PureHttpRequestConfig) => {
              return PureHttp.axiosInstance.request(config);
            }
          );
        } else {
          // 返回错误信息则抛出错误
          const data = error.response?.data as any;
          const $config = error.config as PureHttpRequestConfig | undefined;
          if (data?.code === 0 && !$config?.suppressErrorMessage) {
            message(data?.msg, { type: "error" });
            data?.errors && messageBox(data?.msg, data?.errors);
          }
        }
        // 继续错误处理
        const $error = error;
        $error.isCancelRequest = Axios.isCancel($error);
        // 关闭进度条动画
        NProgress.done();
        // 所有的响应异常 区分来源为取消请求/非取消请求
        return Promise.reject($error);
      }
    );
  }

  /** 通用请求工具函数 */
  public request<T>(
    method: RequestMethods,
    path: string,
    param?: AxiosRequestConfig,
    axiosConfig?: PureHttpRequestConfig
  ): Promise<T> {
    const config = {
      method,
      url: getConfig()?.BaseUrlApi + path,
      ...param,
      ...axiosConfig
    } as PureHttpRequestConfig;

    // 单独处理自定义请求/响应回调
    return new Promise((resolve, reject) => {
      PureHttp.axiosInstance
        .request(config)
        .then((response: undefined) => {
          resolve(response);
        })
        .catch(error => {
          reject(error);
        });
    });
  }

  /** 单独抽离的`post`工具函数 */
  public post<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("post", url, params, config);
  }

  /** 单独抽离的`get`工具函数 */
  public get<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("get", url, params, config);
  }

  /** 单独抽离的`put`工具函数 */
  public put<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("put", url, params, config);
  }

  /** 单独抽离的`patch`工具函数 */
  public patch<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("patch", url, params, config);
  }

  /** 单独抽离的`delete`工具函数 */
  public delete<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("delete", url, params, config);
  }
}

// 全局处理未捕获的 Promise 错误，避免控制台警告
if (typeof window !== "undefined") {
  window.addEventListener("unhandledrejection", event => {
    const reason = event.reason;

    // 更全面的 HTTP 错误检测
    const isHttpError =
      reason &&
      // Axios 错误
      (reason.isAxiosError === true ||
        // 取消的请求
        reason.isCancelRequest === true ||
        // 包含 response 和 config 的错误对象（Axios 格式）
        (reason.response && reason.config && reason.request) ||
        // 自定义 HTTP 错误标记
        reason._isHttpError === true ||
        // 检查错误消息中是否包含 HTTP 相关内容
        (reason.message &&
          /^(Network Error|timeout|Request failed)/i.test(reason.message)));

    if (isHttpError) {
      // 阻止默认的控制台错误输出
      event.preventDefault();

      // 根据环境选择日志级别
      const isDev = import.meta.env.DEV;
      const logMethod = isDev ? console.warn : console.debug;

      // 更详细的错误信息
      logMethod("HTTP request error handled by global interceptor:", {
        message: reason.message || "Unknown error",
        url: reason.config?.url || "Unknown URL",
        method: reason.config?.method || "Unknown method",
        status: reason.response?.status,
        timestamp: new Date().toISOString(),
        // 开发环境显示完整错误
        ...(isDev && { fullError: reason })
      });
    }
  });
}

/**
 * 初始化 Http 实例
 * @param hooks Store 钩子函数
 */
export function createHttp(hooks: HttpStoreHooks): PureHttp {
  storeHooks = hooks;
  httpInstance = new PureHttp();
  return httpInstance;
}

/**
 * 获取当前 Http 实例
 * @throws 如果未初始化则抛出错误
 */
export function getHttpInstance(): PureHttp {
  if (!httpInstance) {
    throw new Error(
      "[http] Http not initialized. Please call createHttp() first."
    );
  }
  return httpInstance;
}

/** Http 代理接口，用于类型推断 */
export interface HttpProxy {
  request<T>(
    method: RequestMethods,
    url: string,
    param?: AxiosRequestConfig,
    axiosConfig?: PureHttpRequestConfig
  ): Promise<T>;
  post<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T>;
  get<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T>;
  put<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T>;
  patch<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T>;
  delete<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T>;
}

/** 导出 http 实例（便捷访问） */
export const http: HttpProxy = {
  request<T>(
    method: RequestMethods,
    url: string,
    param?: AxiosRequestConfig,
    axiosConfig?: PureHttpRequestConfig
  ): Promise<T> {
    return getHttpInstance().request<T>(method, url, param, axiosConfig);
  },
  post<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return getHttpInstance().post<T, P>(url, params, config);
  },
  get<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return getHttpInstance().get<T, P>(url, params, config);
  },
  put<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return getHttpInstance().put<T, P>(url, params, config);
  },
  patch<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return getHttpInstance().patch<T, P>(url, params, config);
  },
  delete<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return getHttpInstance().delete<T, P>(url, params, config);
  }
};
