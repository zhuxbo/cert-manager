// 共享工具函数

// 事件总线
export { emitter } from "./mitt";

// 消息提示
export { message } from "./message";
export { messageBox } from "./messageBox";

// 存储工具
export {
  createStorage,
  type StorageOptions,
  type StorageInstance
} from "./storage";
export * from "./localforage";

// 进度条
export { default as NProgress } from "./progress";

// Auth 认证
export {
  createAuth,
  getAuthInstance,
  getToken,
  setToken,
  removeToken,
  formatToken,
  hasPerms
} from "./auth";
export type { DataInfo, AuthStoreHooks, AuthInstance } from "./auth";

// HTTP 请求
export { createHttp, getHttpInstance, http } from "./http";
export type {
  HttpStoreHooks,
  PureHttpRequestConfig,
  PureHttpResponse,
  PureHttpError,
  RequestMethods
} from "./http";

// Manager meta（前端编排：channels / plugins / version）
export {
  fetchMeta,
  getCachedMeta,
  clearMetaCache,
  renderChannelDisabled
} from "./meta";
export type { ManagerChannels, ManagerPluginInfo, ManagerMeta } from "./meta";

// 其他工具
export * from "./tree";
export * from "./print";
export * from "./datePicker";
export * from "./responsive";
export * from "./globalPolyfills";
export * from "./preventDefault";
export * from "./propTypes";
export * from "./siteLogo";
export * from "./brandOptions";
