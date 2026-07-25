import { http } from "../http";

export function getList(params?: any) {
  return http.get("/notice", { params });
}

export function create(data: any) {
  return http.post("/notice", data);
}

export function update(id: number, data: any) {
  return http.request("put", `/notice/${id}`, { data });
}

export function remove(id: number) {
  return http.delete(`/notice/${id}`);
}

export function toggle(id: number) {
  return http.request("patch", `/notice/toggle/${id}`);
}
