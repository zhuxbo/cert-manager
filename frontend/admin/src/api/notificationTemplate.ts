import { http } from "@/utils/http";

export interface TemplateItem {
  id: number;
  name: string;
  code: string;
  content: string;
  variables: string[];
  example?: string;
  status: number;
  updated_at: string;
  created_at: string;
}

export interface TemplateQuery {
  currentPage?: number;
  pageSize?: number;
  name?: string;
  type?: string;
  status?: number | "";
}

export interface TemplateForm {
  name: string;
  code: string;
  content: string;
  variables: string[];
  example?: string;
  status: number;
}

export function index(params: TemplateQuery) {
  return http.get<
    BaseResponse<{
      items: TemplateItem[];
      total: number;
      pageSize: number;
      currentPage: number;
    }>,
    TemplateQuery
  >("/notification-template", { params });
}

export function store(data: TemplateForm) {
  return http.post<BaseResponse<null>, TemplateForm>("/notification-template", {
    data
  });
}

export function update(id: number, data: TemplateForm) {
  return http.put<BaseResponse<null>, TemplateForm>(
    `/notification-template/${id}`,
    {
      data
    }
  );
}

export function destroy(id: number) {
  return http.delete<BaseResponse<null>, { id: number }>(
    `/notification-template/${id}`
  );
}
