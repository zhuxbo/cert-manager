import type {
  Method,
  AxiosError,
  AxiosResponse,
  AxiosRequestConfig,
  InternalAxiosRequestConfig
} from "axios";

export type RequestMethods = Extract<
  Method,
  "get" | "post" | "put" | "delete" | "patch" | "option" | "head"
>;

export interface PureHttpError extends AxiosError {
  isCancelRequest?: boolean;
}

export interface PureHttpRequestConfig extends AxiosRequestConfig {
  beforeRequestCallback?: (request: PureHttpRequestConfig) => void;
  beforeResponseCallback?: (response: PureHttpResponse) => void;
  suppressErrorMessage?: boolean;
}

export interface PureHttpResponse<T = any, D = any> extends AxiosResponse<
  T,
  D
> {
  config: InternalAxiosRequestConfig<D> & PureHttpRequestConfig;
}

/** Http Store 钩子接口，用于依赖注入 */
export interface HttpStoreHooks {
  /** 刷新 token */
  refreshToken: (data: { refresh_token: string }) => Promise<any>;
  /** 登出 */
  logout: () => void;
}
