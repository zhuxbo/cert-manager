import { http } from "@/utils/http";

export interface PluginInfo {
  name: string;
  version: string;
  description: string;
  release_url: string;
  provider: string;
}

export interface PluginUpdateInfo {
  name: string;
  current_version: string;
  latest_version: string | null;
  has_update: boolean;
  release_name?: string;
  release_body?: string;
  error?: string;
}

export interface PluginActionResult {
  name: string;
  version?: string;
  from_version?: string;
  message: string;
  remove_data?: boolean;
}

export interface PluginOperation {
  uuid: string;
  type: "install_remote" | "install_upload" | "update";
  plugin_name: string;
  version?: string | null;
  status: "queued" | "running" | "succeeded" | "failed";
  stage: string;
  message?: string | null;
  error?: string | null;
  result?: PluginActionResult | null;
  admin_id?: number | null;
  created_at?: string | null;
  updated_at?: string | null;
  started_at?: string | null;
  finished_at?: string | null;
  is_stale?: boolean;
}

// 已安装插件列表
export function getInstalledPlugins(): Promise<
  BaseResponse<{ plugins: PluginInfo[] }>
> {
  return http.request<BaseResponse<{ plugins: PluginInfo[] }>>(
    "get",
    "/plugin/installed"
  );
}

// 检查插件更新
export function checkPluginUpdates(): Promise<
  BaseResponse<{ updates: PluginUpdateInfo[] }>
> {
  return http.request<BaseResponse<{ updates: PluginUpdateInfo[] }>>(
    "get",
    "/plugin/check-updates"
  );
}

// 远程安装插件
export function installPlugin(data: {
  name: string;
  release_url?: string;
  version?: string;
}): Promise<BaseResponse<{ operation: PluginOperation }>> {
  return http.request<BaseResponse<{ operation: PluginOperation }>>(
    "post",
    "/plugin/install",
    { data }
  );
}

// 上传安装插件
export function installPluginFromFile(
  file: File
): Promise<BaseResponse<{ operation: PluginOperation }>> {
  const formData = new FormData();
  formData.append("file", file);
  return http.request<BaseResponse<{ operation: PluginOperation }>>(
    "post",
    "/plugin/install",
    { data: formData }
  );
}

// 更新插件
export function updatePlugin(
  name: string,
  version?: string
): Promise<BaseResponse<{ operation: PluginOperation }>> {
  return http.request<BaseResponse<{ operation: PluginOperation }>>(
    "post",
    "/plugin/update",
    { data: { name, version } }
  );
}

export function getPluginOperations(): Promise<
  BaseResponse<{ operations: PluginOperation[] }>
> {
  return http.request<BaseResponse<{ operations: PluginOperation[] }>>(
    "get",
    "/plugin/operations"
  );
}

export function failStalePluginOperation(
  uuid: string
): Promise<BaseResponse<{ operation: PluginOperation }>> {
  return http.request<BaseResponse<{ operation: PluginOperation }>>(
    "post",
    `/plugin/operations/${uuid}/fail-stale`
  );
}

// 卸载插件
export function uninstallPlugin(
  name: string,
  removeData: boolean = false
): Promise<BaseResponse<PluginActionResult>> {
  return http.request<BaseResponse<PluginActionResult>>(
    "post",
    "/plugin/uninstall",
    { data: { name, remove_data: removeData } }
  );
}
