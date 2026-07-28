import { http } from "@/utils/http";

export function alipay(amount: string): Promise<BaseResponse> {
  return http.post<BaseResponse, { amount: string }>("/top-up/alipay", {
    data: { amount }
  });
}

export function wechat(amount: string): Promise<BaseResponse> {
  return http.post<BaseResponse, { amount: string }>("/top-up/wechat", {
    data: { amount }
  });
}

export function check(id: string): Promise<BaseResponse> {
  return http.post<BaseResponse, null>(`/top-up/check/${id}`);
}

export function getBankAccount(): Promise<BaseResponse> {
  return http.get<BaseResponse, null>("/top-up/get-bank-account");
}
