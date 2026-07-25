import { http } from "@/utils/http";
import { downloadByData } from "@pureadmin/utils";

export interface IndexParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  name?: string;
  code?: string;
  brand?: string;
  product_type?: string | string[];
  encryption_standard?: string;
  encryption_alg?: string;
  validation_type?: string;
  name_type?: string;
  status?: number;
}

/** 获取产品列表 */
export function index(params: IndexParams): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, IndexParams>("/product", { params });
}

// 定义 FormParams 的默认值对象
export const FORM_PARAMS_DEFAULT = {
  code: "",
  name: "",
  api_id: "",
  source: "default",
  brand: "",
  ca: "",
  product_type: "ssl",
  warranty_currency: "$",
  warranty: 0,
  server: 0,
  encryption_standard: "international",
  encryption_alg: ["rsa", "ecdsa"] as string[],
  signature_digest_alg: ["sha256"] as string[],
  validation_type: "dv",
  common_name_types: ["standard"] as string[],
  alternative_name_types: [] as string[],
  validation_methods: [
    "admin",
    "administrator",
    "postmaster",
    "webmaster",
    "hostmaster"
  ] as string[],
  periods: [12] as number[],
  standard_min: 0,
  standard_max: 0,
  wildcard_min: 0,
  wildcard_max: 0,
  total_min: 1,
  total_max: 1,
  add_san: 0,
  replace_san: 0,
  reissue: 0,
  renew: 0,
  reuse_csr: 0,
  gift_root_domain: 0,
  refund_period: 30,
  remark: "",
  weight: 0,
  status: 1,
  cost: {},
  created_at: "",
  updated_at: ""
};

// 从默认值对象中提取键
export const FORM_PARAMS_KEYS = Object.keys(
  FORM_PARAMS_DEFAULT
) as (keyof typeof FORM_PARAMS_DEFAULT)[];

// 从默认值对象中提取类型
export type FormParams = {
  [K in keyof typeof FORM_PARAMS_DEFAULT]?: (typeof FORM_PARAMS_DEFAULT)[K];
};

/** 添加产品 */
export function store(data: FormParams): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, FormParams>("/product", { data });
}

/** 更新产品 */
export function update(id: number, data: FormParams): Promise<BaseResponse> {
  return http.put<BaseResponse<null>, FormParams>(`/product/${id}`, { data });
}

/** 查看产品 */
export function show(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse, null>(`/product/${id}`);
}

/** 批量获取产品 */
export function batchShow(ids: number[]): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { ids: number[] }>(`/product/batch`, {
    params: { ids }
  });
}

/** 删除产品 */
export function destroy(id: number): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { id: number }>(`/product/${id}`);
}

/** 批量删除产品 */
export function batchDestroy(ids: number[]): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { ids: number[] }>(`/product/batch`, {
    data: { ids }
  });
}

/** 批量开启或关闭产品委托验证 */
export function batchSetDelegation(
  ids: number[],
  enabled: boolean
): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: number[]; enabled: boolean }>(
    "/product/batch-delegation",
    {
      data: { ids, enabled }
    }
  );
}

/** 导入产品 */
export function importProduct(data: {
  source?: string;
  brand?: string;
  apiId?: string;
  type?: string;
}): Promise<BaseResponse> {
  return http.post<
    BaseResponse<null>,
    { source?: string; brand?: string; apiId?: string; type?: string }
  >("/product/import", { data });
}

/**
 * 获取产品成本
 */
export function getCost(id: number) {
  return http.get<
    BaseResponse<{
      periods: number[];
      alternative_name_types: string[];
      cost: Record<string, any>;
    }>,
    any
  >(`/product/cost/${id}`);
}

/**
 * 更新产品成本
 */
export function updateCost(id: number, cost: Record<string, any>) {
  return http.patch<BaseResponse<null>, any>(`/product/cost/${id}`, {
    data: {
      cost
    }
  });
}

/**
 * 获取来源列表
 */
export function getSourceList() {
  return http.get<BaseResponse<any>, any>("/product/source");
}

/**
 * 导出产品价格列表
 */
export interface ExportParams {
  brands?: string[];
  levelCustom?: number;
  levelCodes?: string[];
}

export function exportProduct(params: ExportParams) {
  return http.post(
    "/product/export",
    { data: params },
    {
      responseType: "blob",
      beforeResponseCallback: response => {
        const disposition = response.headers["content-disposition"];
        let filename = "产品价格列表.xlsx";
        if (disposition) {
          const filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
          const matches = filenameRegex.exec(disposition);
          if (matches != null && matches[1]) {
            filename = decodeURIComponent(matches[1].replace(/['"]/g, ""));
          }
        }

        if (response.data instanceof Blob) {
          downloadByData(response.data, filename);
        }
      }
    }
  );
}
