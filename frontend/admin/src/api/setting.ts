import { http } from "@/utils/http";
import { isObject } from "@pureadmin/utils";

// 设置项默认值
export const FORM_PARAMS_DEFAULT = {
  group_id: undefined,
  key: "",
  type: "string",
  is_multiple: false,
  options: [],
  value: undefined,
  description: "",
  weight: 0
};

// 设置项字段
export const FORM_PARAMS_KEYS = Object.keys(FORM_PARAMS_DEFAULT);

// 设置项类型
export type FormParams = {
  [K in keyof typeof FORM_PARAMS_DEFAULT]?: (typeof FORM_PARAMS_DEFAULT)[K];
};

// 设置组默认值
export const GROUP_PARAMS_DEFAULT = {
  name: "",
  title: "",
  description: "",
  weight: 0
};

// 设置组字段
export const GROUP_PARAMS_KEYS = Object.keys(GROUP_PARAMS_DEFAULT);

// 设置组类型
export type GroupParams = {
  [K in keyof typeof GROUP_PARAMS_DEFAULT]?: (typeof GROUP_PARAMS_DEFAULT)[K];
};

// 获取所有设置
export function getAllSettings(): Promise<BaseResponse> {
  return http.request<BaseResponse>("get", "/setting");
}

// 获取指定组的设置
export function getGroupSettings(groupId: number): Promise<BaseResponse> {
  return http.request<BaseResponse>("get", `/setting/group/${groupId}`);
}

// 批量更新设置
export function batchUpdateSettings(settings: any[]): Promise<BaseResponse> {
  // 确保只传递id和value字段，并将所有值转换为字符串
  const settingsData = settings.map(setting => ({
    id: setting.id,
    value:
      isObject(setting.value) || Array.isArray(setting.value)
        ? setting.value
        : String(setting.value)
  }));

  return http.request<BaseResponse>("patch", "/setting/batch-update", {
    data: { settings: settingsData }
  });
}

// 设置组相关API
export function getGroups(): Promise<BaseResponse> {
  return http.request<BaseResponse>("get", "/setting-group");
}

export function showGroup(id: number): Promise<BaseResponse> {
  return http.request<BaseResponse>("get", `/setting-group/${id}`);
}

export function storeGroup(data: GroupParams): Promise<BaseResponse> {
  return http.request<BaseResponse>("post", "/setting-group", { data });
}

export function updateGroup(
  id: number,
  data: GroupParams
): Promise<BaseResponse> {
  return http.request<BaseResponse>("put", `/setting-group/${id}`, {
    data
  });
}

export function destroyGroup(id: number): Promise<BaseResponse> {
  return http.request<BaseResponse>("delete", `/setting-group/${id}`);
}

// 设置项相关API
export function store(data: FormParams): Promise<BaseResponse> {
  return http.request<BaseResponse>("post", "/setting", { data });
}

export function update(id: number, data: FormParams): Promise<BaseResponse> {
  return http.request<BaseResponse>("put", `/setting/${id}`, { data });
}

export function show(id: number): Promise<BaseResponse> {
  return http.request<BaseResponse>("get", `/setting/${id}`);
}

export function destroy(id: number): Promise<BaseResponse> {
  return http.request<BaseResponse>("delete", `/setting/${id}`);
}

export function batchDestroy(ids: number[]): Promise<BaseResponse> {
  return http.request<BaseResponse>("delete", "/setting/batch", {
    data: { ids }
  });
}

// 清除所有设置缓存
export function clearCache(): Promise<BaseResponse> {
  return http.request<BaseResponse>("post", "/setting/clear-cache");
}

// 安全刷新系统设置缓存（保留队列、调度、运行状态和会话）
export function refreshSettingCacheSafely(): Promise<BaseResponse> {
  return http.request<BaseResponse>("post", "/setting/clear-all-cache");
}

// 清除支付配置缓存并删除已落盘的支付证书；不传 type 清全部支付类型
export function clearPayCache(
  type?: "alipay" | "wechat"
): Promise<BaseResponse> {
  return http.request<BaseResponse>("post", "/setting/clear-pay-cache", {
    data: type ? { type } : {}
  });
}

export function uploadSiteImage(
  kind: "favicon" | "logo" | "logo-expanded" | "qrcode" | "login-image",
  file: File
): Promise<BaseResponse<{ url: string }>> {
  const formData = new FormData();
  formData.append("file", file);

  return http.request<BaseResponse<{ url: string }>>(
    "post",
    `/setting/site-image/${kind}`,
    { data: formData }
  );
}

// 清除站点图片并删除托管文件（恢复默认回落资源）
export function deleteSiteImage(
  kind: "favicon" | "logo" | "logo-expanded" | "qrcode" | "login-image"
): Promise<BaseResponse> {
  return http.request<BaseResponse>("delete", `/setting/site-image/${kind}`);
}
