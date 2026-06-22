import { http } from "@/utils/http";

export interface IndexParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  zone?: string;
  prefix?: "_certum" | "_pki-validation" | "_dnsauth";
  valid?: boolean;
}

export interface DelegationItem {
  id: number;
  user_id: number;
  zone: string;
  prefix: string;
  label: string;
  proxy_zone: string;
  target_fqdn: string;
  valid: boolean;
  last_checked_at: string | null;
  fail_count: number;
  last_error: string;
  created_at: string;
  updated_at: string;
  cname_to: {
    host: string;
    value: string;
  };
  warning?: string;
}

/** 获取委托列表 */
export function index(params: IndexParams): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, IndexParams>("/delegation", {
    params
  });
}

// 定义 FormParams 的默认值对象
// 委托创建按 CA 选择，后端经 ca_map 派生 prefix + zone
export const FORM_PARAMS_DEFAULT = {
  zone: "",
  ca: ""
};

// 从默认值对象中提取键
export const FORM_PARAMS_KEYS = Object.keys(
  FORM_PARAMS_DEFAULT
) as (keyof typeof FORM_PARAMS_DEFAULT)[];

// 从默认值对象中提取类型
export type FormParams = {
  [K in keyof typeof FORM_PARAMS_DEFAULT]?: (typeof FORM_PARAMS_DEFAULT)[K];
};

export type StoreParams = FormParams;

/** 创建委托 */
export function store(
  data: StoreParams
): Promise<BaseResponse<DelegationItem>> {
  return http.post<BaseResponse<DelegationItem>, StoreParams>("/delegation", {
    data
  });
}

/** 获取委托详情 */
export function show(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { id: number }>(`/delegation/${id}`);
}

/** 批量获取委托 */
export function batchShow(ids: number[]): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { ids: number[] }>(`/delegation/batch`, {
    params: { ids }
  });
}

export interface UpdateParams {
  regen_label?: boolean;
}

/** 更新委托 */
export function update(
  id: number,
  data: UpdateParams
): Promise<BaseResponse<DelegationItem>> {
  return http.put<BaseResponse<DelegationItem>, UpdateParams>(
    `/delegation/${id}`,
    { data }
  );
}

/** 手动触发健康检查 */
export function check(id: number): Promise<BaseResponse<DelegationItem>> {
  return http.post<BaseResponse<DelegationItem>, null>(
    `/delegation/check/${id}`
  );
}

/** 删除委托 */
export function destroy(id: number): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { id: number }>(`/delegation/${id}`);
}

/** 批量删除委托 */
export function batchDestroy(ids: number[]): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { ids: number[] }>(
    `/delegation/batch`,
    {
      data: { ids }
    }
  );
}

export interface BatchStoreParams {
  zones: string;
  ca: string;
}

export interface BatchStoreResult {
  created: DelegationItem[];
  failed: { zone: string; error: string }[];
  total: number;
  success_count: number;
  fail_count: number;
}

/** 批量创建委托 */
export function batchStore(
  data: BatchStoreParams
): Promise<BaseResponse<BatchStoreResult>> {
  return http.post<BaseResponse<BatchStoreResult>, BatchStoreParams>(
    "/delegation/batch-store",
    { data }
  );
}
