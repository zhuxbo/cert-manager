import { http } from "@/utils/http";

// 通用日志查询参数接口
export interface BaseLogsParams {
  currentPage?: number;
  pageSize?: number;
  created_at?: [string, string];
  params?: string;
  response?: string;
  status?: number;
}

// Web日志查询参数（admin/user）
export interface WebLogsParams extends BaseLogsParams {
  username?: string;
  module?: string;
  action?: string;
  status_code?: number;
  ip?: string;
}

// API日志查询参数
export interface ApiLogsParams extends BaseLogsParams {
  username?: string;
  module?: string;
  action?: string;
  version?: string;
  status_code?: number;
  ip?: string;
}

// 回调日志查询参数
export interface CallbackLogsParams extends BaseLogsParams {
  module?: string;
  action?: string;
  url?: string;
  ip?: string;
}

// CA日志查询参数
export interface CaLogsParams extends BaseLogsParams {
  method?: string;
  status_code?: number;
  url?: string;
}

// 错误日志查询参数
export interface ErrorLogsParams {
  currentPage?: number;
  pageSize?: number;
  created_at?: [string, string];
  module?: string;
  action?: string;
  url?: string;
  method?: string;
  exception?: string;
  message?: string;
  trace?: string;
  status_code?: number;
  ip?: string;
}

// 管理员日志
export function getAdminLogs(params: WebLogsParams): Promise<BaseResponse> {
  return http.get("/logs/admin", { params });
}

// 用户日志
export function getUserLogs(params: WebLogsParams): Promise<BaseResponse> {
  return http.get("/logs/user", { params });
}

// API日志
export function getApiLogs(params: ApiLogsParams): Promise<BaseResponse> {
  return http.get("/logs/api", { params });
}

// 回调日志
export function getCallbackLogs(
  params: CallbackLogsParams
): Promise<BaseResponse> {
  return http.get("/logs/callback", { params });
}

// CA日志
export function getCaLogs(params: CaLogsParams): Promise<BaseResponse> {
  return http.get("/logs/ca", { params });
}

// 错误日志
export function getErrorLogs(params: ErrorLogsParams): Promise<BaseResponse> {
  return http.get("/logs/error", { params });
}

// 日志详情
type LogType = "admin" | "user" | "api" | "callback" | "ca" | "error";

export function getLogDetail(type: LogType, id: number): Promise<BaseResponse> {
  return http.get(`/logs/${type}/${id}`);
}
