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

/** 查看产品 */
export function show(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse, null>(`/product/${id}`);
}

/**
 * 导出产品价格列表
 */
export interface ExportParams {
  brands?: string[];
  priceRate?: number;
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
