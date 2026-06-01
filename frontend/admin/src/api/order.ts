import { http } from "@/utils/http";
import { downloadByData } from "@pureadmin/utils";

export interface IndexParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  statusSet?: string;
  id?: number;
  period?: number;
  amount?: [number, number];
  username?: string;
  product_name?: string;
  domain?: string;
  channel?: string;
  action?: string;
  created_at?: [string, string];
  expires_at?: [string, string];
  status?: string;
  sort_prop?: string;
  sort_order?: string;
}

export const ACTION_PARAMS_DEFAULT = {
  csr_generate: 1,
  encryption: {
    alg: "rsa",
    bits: 2048,
    digest_alg: "sha256"
  },
  plus: 1
};

/** 获取订单列表 */
export function index(params: IndexParams): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, IndexParams>("/order", { params });
}

/** 获取订单详情 */
export function show(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { id: number }>(`/order/${id}`);
}

/** 批量获取订单 */
export function batchShow(
  ids: string | number | number[]
): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { ids: string | number | number[] }>(
    `/order/batch`,
    {
      params: { ids }
    }
  );
}

/** 新建订单 */
export function apply(data: any): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>("/order/new", { data });
}

/** 批量新建订单 */
export function batchApply(data: any): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>("/order/batch-new", { data });
}

/** 续费订单 */
export function renew(data: any): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>("/order/renew", { data });
}

/** 补发订单 */
export function reissue(data: any): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>("/order/reissue", { data });
}

/** 支付订单 */
export function pay(id: number, data?: any): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(`/order/pay/${id}`, { data });
}

/** 提交订单 */
export function commit(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(`/order/commit/${id}`);
}

/** 重新验证订单 */
export function revalidate(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(`/order/revalidate/${id}`);
}

/** 更新DCV */
export function updateDCV(id: number, method: string): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(`/order/update-dcv/${id}`, {
    data: { method }
  });
}

/** 同步订单 */
export function sync(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(`/order/sync/${id}`);
}

/** 提交取消订单 */
export function commitCancel(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(`/order/commit-cancel/${id}`);
}

/** 撤销取消订单 */
export function revokeCancel(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(`/order/revoke-cancel/${id}`);
}

/** 备注订单 */
export function remark(id: number, remark: string): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(`/order/remark/${id}`, {
    data: { remark }
  });
}

/** 下载订单 */
export function download(
  ids: string | number | number[],
  type?: string
): Promise<any> {
  return http.get<any, { ids: string | number | number[]; type?: string }>(
    `/order/download`,
    {
      params: { ids, type }
    },
    {
      responseType: "blob",
      beforeResponseCallback: response => {
        const disposition = response.headers["content-disposition"];
        let filename = "certs.zip";
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

/** 下载验证文件 */
export function downloadValidateFile(id: number): Promise<any> {
  return http.get<any, { id: number }>(
    `/order/download-validate-file/${id}`,
    {},
    {
      responseType: "blob",
      beforeResponseCallback: response => {
        // 从响应头获取文件名
        const disposition = response.headers["content-disposition"];
        let filename = "验证文件.zip";
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

/** 发送激活邮件 */
export function sendActive(id: number, email?: string): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { id: number; email?: string }>(
    `/order/send-active/${id}`,
    {
      params: { email }
    }
  );
}

/** 批量支付订单 */
export function batchPay(
  ids: string | number | number[]
): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: string | number | number[] }>(
    "/order/batch-pay",
    { data: { ids } }
  );
}

/** 批量提交订单 */
export function batchCommit(
  ids: string | number | number[]
): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: string | number | number[] }>(
    "/order/batch-commit",
    { data: { ids } }
  );
}

/** 批量重新验证 */
export function batchRevalidate(
  ids: string | number | number[]
): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: string | number | number[] }>(
    "/order/batch-revalidate",
    { data: { ids } }
  );
}

/** 批量同步 */
export function batchSync(
  ids: string | number | number[]
): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: string | number | number[] }>(
    "/order/batch-sync",
    { data: { ids } }
  );
}

/** 批量提交取消 */
export function batchCommitCancel(
  ids: string | number | number[]
): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: string | number | number[] }>(
    "/order/batch-commit-cancel",
    { data: { ids } }
  );
}

/** 批量撤销取消 */
export function batchRevokeCancel(
  ids: string | number | number[]
): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: string | number | number[] }>(
    `/order/batch-revoke-cancel`,
    {
      data: { ids }
    }
  );
}

/** 获取部署命令 */
export function deployCommands(
  orderIds: string | number
): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { order_ids: string | number }>(
    "/order/deploy-commands",
    { params: { order_ids: orderIds } }
  );
}

/** 导入证书 */
export function importCert(data: any): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>("/order/input", { data });
}

/** 过户证书 */
export function transfer(data: any): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>("/order/transfer", { data });
}

/** 修改未支付订单价格 */
export function updateAmount(
  id: number,
  amount: number | string
): Promise<BaseResponse> {
  return http.patch<BaseResponse<null>, { amount: number | string }>(
    `/order/amount/${id}`,
    { data: { amount } }
  );
}

/** 上传验证文档 */
export function uploadDocument(
  id: number,
  data: FormData
): Promise<BaseResponse> {
  return http.request<BaseResponse>(
    "post",
    `/order/upload-document/${id}`,
    { data },
    { timeout: 60000 }
  );
}

/** 更新文档信息（文件名、类型） */
export function updateDocument(
  id: number,
  data: { file_name: string; type: string }
): Promise<BaseResponse> {
  return http.request<BaseResponse>("patch", `/order/document/${id}`, { data });
}

/** 获取文档列表 */
export function getDocuments(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, any>(`/order/documents/${id}`);
}

/** 获取文档预览/下载短时签名 URL（access_token 不进 URL） */
export function previewDocumentUrl(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, any>(`/order/document-preview-url/${id}`);
}

/** 删除文档 */
export function deleteDocument(id: number): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, any>(`/order/document/${id}`);
}

/** 提交文档到上游 */
export function submitDocuments(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, any>(
    `/order/submit-documents/${id}`,
    {},
    { timeout: 300000 }
  );
}

/** 更新订单自动设置 */
export function updateAutoSettings(
  id: number,
  data: { auto_renew?: boolean | null; auto_reissue?: boolean | null }
): Promise<BaseResponse> {
  return http.patch<
    BaseResponse<null>,
    { auto_renew?: boolean | null; auto_reissue?: boolean | null }
  >(`/order/auto-settings/${id}`, { data });
}
