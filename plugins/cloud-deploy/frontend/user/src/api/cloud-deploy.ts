import { http } from "../http";

const base = "/cloud-deploy";

// catalog schema 元素类型（与后端 Registry::catalog 对齐）
export interface ConfigField {
  key: string;
  label: string;
  type: "string" | "number" | "select";
  required?: boolean;
  options?: Array<{ label: string; value: string }>;
}
export interface CredentialField {
  key: string;
  label: string;
  required?: boolean;
  secret?: boolean;
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

// —— 列表查询参数（钉死命名，与后端 targets()/logs() 搜索契约对齐；保留 [k:string] 兼容旧调用）——
export interface TargetQuery {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  provider?: string;
  product?: string;
  order_id?: number;
  enabled?: boolean;
  last_status?: string; // 等值匹配；特殊值 'unpushed' → 后端 whereNull
  last_deployed_at_start?: string;
  last_deployed_at_end?: string;
  created_at_start?: string;
  created_at_end?: string;
  keyword?: string; // 域名（cert.common_name），后端 whereHas('order.latestCert')
  [k: string]: any;
}
export interface LogQuery {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  order_id?: number;
  target_id?: number;
  status?: string;
  is_final?: boolean; // 默认 true 去噪只显终态；显示重试细节传 false
  provider?: string;
  product?: string;
  trigger?: string;
  keyword?: string; // 域名（logs.resource_summary 快照）
  created_at_start?: string;
  created_at_end?: string;
  [k: string]: any;
}

export const providers = () => http.get(`${base}/providers`);

// 进程内缓存 catalog：表单每次打开复用，避免重复拉取（catalog 随版本静态）
let _catalog: Promise<ProviderCatalogItem[]> | null = null;
export function getProviders(force = false): Promise<ProviderCatalogItem[]> {
  if (force) _catalog = null;
  if (!_catalog) {
    _catalog = providers()
      .then((res: any) => (res?.data?.providers ?? []) as ProviderCatalogItem[])
      .catch(err => {
        _catalog = null; // 失败不缓存，下次重试
        throw err;
      });
  }
  return _catalog;
}

export const accessList = (params: Record<string, any>) =>
  http.get(`${base}/access`, { params });
export const accessStore = (data: Record<string, any>) =>
  http.post(`${base}/access`, data);
export const accessUpdate = (id: number, data: Record<string, any>) =>
  http.put(`${base}/access/${id}`, { data });
export const accessDestroy = (id: number) =>
  http.delete(`${base}/access/${id}`);

export const targetList = (params: TargetQuery) =>
  http.get(`${base}/target`, { params });
export const targetStore = (data: Record<string, any>) =>
  http.post(`${base}/target`, data);
export const targetUpdate = (id: number, data: Record<string, any>) =>
  http.put(`${base}/target/${id}`, { data });
export const targetDestroy = (id: number) =>
  http.delete(`${base}/target/${id}`);

export const logList = (params: LogQuery) =>
  http.get(`${base}/log`, { params });
export const deploy = (data: Record<string, any>) =>
  http.post(`${base}/deploy`, data);
