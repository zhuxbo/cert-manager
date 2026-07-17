import { http } from "@/utils/http";

export interface IndexParams {
  currentPage?: number;
  pageSize?: number;
  product_id?: number;
  level_code?: string;
  period?: number;
}

export interface InitializationLevel {
  code: string;
  cost_rate: string;
}

export interface InitializationRequest {
  levels: InitializationLevel[];
  precision: 0 | 1 | 2;
  force: boolean;
  sync_cost_rates: boolean;
  preview: boolean;
  preview_token?: string;
}

export interface InitializationWarning {
  product_id: number;
  product_name: string;
  period: number | null;
  field: string;
  message: string;
}

export interface InitializationResult {
  preview: boolean;
  can_execute: boolean;
  executed: boolean;
  reason: "cost_validation" | "stale_preview" | null;
  preview_token?: string | null;
  product_count: number;
  level_count: number;
  target_count: number;
  created_count: number;
  preserved_count: number;
  deleted_count: number;
  rebuilt_count: number;
  synced_level_count: number;
  warnings: InitializationWarning[];
}

/** 获取产品价格列表 */
export function index(params: IndexParams): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, IndexParams>("/product-price", {
    params
  });
}

// 定义 FormParams 的默认值对象
export const FORM_PARAMS_DEFAULT = {
  product_id: 0,
  level_code: "",
  period: 0,
  price: 0,
  alternative_standard_price: 0,
  alternative_wildcard_price: 0
};

// 从默认值对象中提取键
export const FORM_PARAMS_KEYS = Object.keys(
  FORM_PARAMS_DEFAULT
) as (keyof typeof FORM_PARAMS_DEFAULT)[];

// 从默认值对象中提取类型
export type FormParams = {
  [K in keyof typeof FORM_PARAMS_DEFAULT]?: (typeof FORM_PARAMS_DEFAULT)[K];
};

/** 添加产品价格 */
export function store(data: FormParams): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, FormParams>("/product-price", { data });
}

/** 更新产品价格 */
export function update(id: number, data: FormParams): Promise<BaseResponse> {
  return http.put<BaseResponse<null>, FormParams>(`/product-price/${id}`, {
    data
  });
}

/** 获取产品价格 */
export function show(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { id: number }>(`/product-price/${id}`);
}

/** 批量获取产品价格 */
export function batchShow(ids: number[]): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { ids: number[] }>(
    `/product-price/batch`,
    {
      params: { ids }
    }
  );
}

/** 删除产品价格 */
export function destroy(id: number): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { id: number }>(
    `/product-price/${id}`
  );
}

/** 批量删除产品价格 */
export function batchDestroy(ids: number[]): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { ids: number[] }>(
    `/product-price/batch`,
    {
      data: { ids }
    }
  );
}

/** 获取产品价格 */
export function get(
  product_id: number,
  level_codes: string[]
): Promise<BaseResponse> {
  return http.get<
    BaseResponse<null>,
    { product_id: number; level_codes: string[] }
  >(`/product-price/get`, {
    params: { product_id, level_codes }
  });
}

/** 设置产品价格 */
export function set(
  product_id: number,
  product_price: object
): Promise<BaseResponse> {
  return http.put<
    BaseResponse<null>,
    { product_id: number; product_price: object }
  >(`/product-price/set`, {
    data: { product_id, product_price }
  });
}

/** 预览或执行产品价格批量初始化 */
export function initialize(
  data: InitializationRequest
): Promise<BaseResponse<InitializationResult>> {
  return http.post<BaseResponse<InitializationResult>, InitializationRequest>(
    "/product-price/initialization",
    { data }
  );
}

/** 导出产品价格 */
export function exportTable(): Promise<BaseResponse<null>> {
  return http.get<BaseResponse<null>, null>("/product-price/export");
}
