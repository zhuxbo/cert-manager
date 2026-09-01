import { http } from "../http";

export interface EasyLogsParams {
  currentPage?: number;
  pageSize?: number;
  created_at?: [string, string];
  action?: string;
  url?: string;
  method?: string;
  params?: string;
  response?: string;
  ip?: string;
  status?: number;
}

export function getEasyLogs(params: EasyLogsParams): Promise<any> {
  return http.get("/logs/easy", { params });
}

export function getLogDetail(id: number): Promise<any> {
  return http.get(`/logs/easy/${id}`);
}
