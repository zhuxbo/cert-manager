import { http } from "../http";

export interface IndexParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  id?: number;
  username?: string;
  organization?: string;
  email?: string;
  status?: number;
  created_at?: [string, string];
}

export function index(params: IndexParams) {
  return http.get("/invoice", { params });
}

export const FORM_PARAMS_DEFAULT = {
  user_id: 0,
  amount: 0,
  organization: "",
  taxation: "",
  remark: "",
  email: "",
  status: 0
};

export const FORM_PARAMS_KEYS = Object.keys(
  FORM_PARAMS_DEFAULT
) as (keyof typeof FORM_PARAMS_DEFAULT)[];

export type FormParams = {
  [K in keyof typeof FORM_PARAMS_DEFAULT]?: (typeof FORM_PARAMS_DEFAULT)[K];
};

export function store(data: FormParams) {
  return http.post("/invoice", data);
}

export function update(id: number, data: FormParams) {
  return http.put(`/invoice/${id}`, { data });
}

export function show(id: number) {
  return http.get(`/invoice/${id}`);
}

export function batchShow(ids: number[]) {
  return http.get("/invoice/batch", { params: { ids } });
}

export function destroy(id: number) {
  return http.delete(`/invoice/${id}`);
}

export function batchDestroy(ids: number[]) {
  return http.delete("/invoice/batch", { data: { ids } });
}

export function quota(userId: number) {
  return http.get(`/invoice/quota/${userId}`);
}

export function showUser(id: number) {
  return http.get(`/user/${id}`);
}

export function getExternalConfig() {
  return http.get("/invoice/external-config");
}

export function updateExternalConfig(data: { allowed_ips: string }) {
  return http.post("/invoice/external-config", data);
}

export function regenerateExternalToken() {
  return http.post("/invoice/external-config/regenerate-token");
}
