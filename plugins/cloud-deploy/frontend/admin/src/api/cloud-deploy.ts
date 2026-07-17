import { http } from "../http";

// admin baseURL 已含 /api/admin，路径不重复 /admin 前缀（否则 /api/admin/admin/... 404）
const base = "/cloud-deploy";

// catalog schema 元素类型（与后端 Registry::catalog 对齐）
export interface ConfigField {
  key: string;
  label: string;
  type: "string" | "number" | "select";
  required?: boolean;
  options?: Array<{ label: string; value: string }>;
  description?: string;
  help?: string;
  tip?: string;
}
export interface CredentialField {
  key: string;
  label: string;
  required?: boolean;
  secret?: boolean;
  description?: string;
  help?: string;
  tip?: string;
}
export interface ProviderProduct {
  product: string;
  label: string;
  configSchema: ConfigField[];
}
export interface ProviderCatalogItem {
  key: string;
  label: string;
  credentialSchema: CredentialField[];
  products: ProviderProduct[];
}

export const providers = () => http.get(`${base}/providers`);

let _catalog: Promise<ProviderCatalogItem[]> | null = null;
export function getProviders(force = false): Promise<ProviderCatalogItem[]> {
  if (force) _catalog = null;
  if (!_catalog) {
    _catalog = providers()
      .then((res: any) => (res?.data?.providers ?? []) as ProviderCatalogItem[])
      .catch(err => {
        _catalog = null;
        throw err;
      });
  }
  return _catalog;
}

// 部署目标列表（全局视角；params 透传搜索/筛选 query：
// currentPage/pageSize/quickSearch/provider/product/order_id/enabled/last_status/
// last_deployed_at_start/last_deployed_at_end/created_at_start/created_at_end/keyword/user_id/username）
// 返回 item.config 已由后端逐键脱敏（secret 键打码 ******、保留 domain 等非 secret 键）
export const targetList = (params: Record<string, any>) =>
  http.get(`${base}/target`, { params });
export const targetShow = (id: number) => http.get(`${base}/target/${id}`);
export const targetStore = (data: Record<string, any>) =>
  http.post(`${base}/target`, data);
export const targetUpdate = (id: number, data: Record<string, any>) =>
  http.put(`${base}/target/${id}`, { data });
export const targetDestroy = (id: number) =>
  http.delete(`${base}/target/${id}`);

// 云凭证列表（脱敏，item 不含 credentials；params：
// currentPage/pageSize/quickSearch/name/provider/user_id/created_at_start/created_at_end）
export const accessList = (params: Record<string, any>) =>
  http.get(`${base}/access`, { params });
export const accessShow = (id: number) => http.get(`${base}/access/${id}`);
export const accessStore = (data: Record<string, any>) =>
  http.post(`${base}/access`, data);
export const accessUpdate = (id: number, data: Record<string, any>) =>
  http.put(`${base}/access/${id}`, { data });
export const accessDestroy = (id: number) =>
  http.delete(`${base}/access/${id}`);

export const orderOptionList = (params: Record<string, any>) =>
  http.get(`${base}/order-options`, { params });
export const orderOptionShow = (id: number, params?: Record<string, any>) =>
  http.get(`${base}/order-options/${id}`, { params });

// 部署历史列表（params：currentPage/pageSize/quickSearch/order_id/target_id/status/
// is_final/provider/product/trigger/keyword/created_at_start/created_at_end/user_id）
export const logList = (params: Record<string, any>) =>
  http.get(`${base}/log`, { params });

// admin 手动推送（共享 DeployService，admin 跨用户走 target_ids 模式；body {target_ids:int[], force?:bool}）
// 返回 {dispatched:int}（异步入队计数，真实成败事后看 last_status / 记录弹窗）
export const deploy = (data: Record<string, any>) =>
  http.post(`${base}/deploy`, data);
