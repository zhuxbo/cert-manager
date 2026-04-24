import { http } from "@/utils/http";
import { useUserStoreHook } from "@/store/modules/user";

/** 从响应中提取 balance 并同步余额 */
function syncBalance(res: BaseResponse): BaseResponse {
  if (res?.code === 1) {
    const balance = res.data?.balance;
    if (balance != null) {
      useUserStoreHook().updateBalance(String(balance));
    }
  }
  return res;
}

export interface Acme {
  id: number;
  user_id: number;
  product_id: number;
  brand: string;
  period: number;
  plus: number;
  amount: string;
  purchased_standard_count: number;
  purchased_wildcard_count: number;
  refer_id: string | null;
  api_id: string | null;
  vendor_id: string | null;
  contact_email: string | null;
  eab_kid: string | null;
  eab_hmac: string | null;
  period_from: string | null;
  period_till: string | null;
  cancelled_at: string | null;
  status: string;
  channel: string;
  directory_url: string | null;
  remark: string | null;
  created_at: string;
  updated_at: string;
  product?: { id: number; name: string };
}

export interface CreateAcmeForm {
  product_id: number | undefined;
  period: number | string;
}

export interface AcmeParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  id?: number | string;
  statusSet?: string;
  status?: string;
  brand?: string;
  period?: number;
  eab_kid?: string;
  product_name?: string;
  amount?: (number | undefined)[];
  created_at?: string[];
  period_till?: string[];
}

/** 创建 ACME 订阅订单 */
export function createOrder(data: {
  product_id: number;
  period: number;
  plus?: number;
  contact_email: string;
}): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, typeof data>("/acme/new", { data });
}

/** 支付 ACME 订单 */
export function payOrder(id: number): Promise<BaseResponse> {
  return http
    .post<BaseResponse<null>, null>(`/acme/pay/${id}`)
    .then(syncBalance);
}

/** 提交 ACME 订单 */
export function commitOrder(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, null>(`/acme/commit/${id}`);
}

/** 获取 ACME 订单列表 */
export function getAcmes(params: AcmeParams): Promise<BaseResponse> {
  return http.get<BaseResponse, AcmeParams>("/acme", { params });
}

/** 获取 ACME 订单详情 */
export function getAcmeDetail(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, null>(`/acme/${id}`);
}

/** 取消 ACME 订单 */
export function cancelAcme(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, null>(`/acme/commit-cancel/${id}`);
}

/** 撤回 ACME 取消 */
export function revokeCancelAcme(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, null>(`/acme/revoke-cancel/${id}`);
}

/** ACME 备注 */
export function remarkAcme(id: number, remark: string): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { remark: string }>(
    `/acme/remark/${id}`,
    { data: { remark } }
  );
}

/** 同步 ACME（单体，补齐） */
export function syncAcme(id: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, null>(`/acme/sync/${id}`);
}

/** 批量支付 */
export function batchPayAcme(ids: number[]): Promise<BaseResponse> {
  return http
    .post<BaseResponse<null>, { ids: number[] }>("/acme/batch-pay", {
      data: { ids }
    })
    .then(syncBalance);
}

/** 批量提交 */
export function batchCommitAcme(ids: number[]): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: number[] }>(
    "/acme/batch-commit",
    { data: { ids } }
  );
}

/** 批量同步 */
export function batchSyncAcme(ids: number[]): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: number[] }>("/acme/batch-sync", {
    data: { ids }
  });
}

/** 批量取消（含退费 → 同步余额） */
export function batchCommitCancelAcme(ids: number[]): Promise<BaseResponse> {
  return http
    .post<
      BaseResponse<null>,
      { ids: number[] }
    >("/acme/batch-commit-cancel", { data: { ids } })
    .then(syncBalance);
}

/** 批量撤回取消 */
export function batchRevokeCancelAcme(ids: number[]): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { ids: number[] }>(
    "/acme/batch-revoke-cancel",
    { data: { ids } }
  );
}

/** 批量复制 EAB */
export function batchCopyEabAcme(
  ids: number[]
): Promise<BaseResponse<{ text: string; count: number }>> {
  return http.post<
    BaseResponse<{ text: string; count: number }>,
    { ids: number[] }
  >("/acme/batch-copy-eab", { data: { ids } });
}
