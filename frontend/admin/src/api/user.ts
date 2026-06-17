import { http } from "@/utils/http";

export interface IndexParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  username?: string;
  email?: string;
  mobile?: number;
  level_code?: string;
  custom_level_code?: string;
  status?: number;
  created_at?: [string, string];
  balance?: [string | null, string | null];
  credit_limit?: number;
  sort_prop?: string;
  sort_order?: string;
}

/** 获取用户列表 */
export function index(params: IndexParams): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, IndexParams>("/user", { params });
}

// 定义 FormParams 的默认值对象
export const FORM_PARAMS_DEFAULT = {
  username: "",
  password: "",
  email: "",
  mobile: 0,
  level_code: "",
  custom_level_code: "",
  credit_limit: 0,
  status: 1
};

// 从默认值对象中提取键
export const FORM_PARAMS_KEYS = Object.keys(
  FORM_PARAMS_DEFAULT
) as (keyof typeof FORM_PARAMS_DEFAULT)[];

// 从默认值对象中提取类型
export type FormParams = {
  [K in keyof typeof FORM_PARAMS_DEFAULT]?: (typeof FORM_PARAMS_DEFAULT)[K];
};

/** 添加用户 */
export function store(data: FormParams): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, FormParams>("/user", { data });
}

/** 更新用户 */
export function update(id: number, data: FormParams): Promise<BaseResponse> {
  return http.put<BaseResponse<null>, FormParams>(`/user/${id}`, { data });
}

/** 获取用户 */
export function show(id: number): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { id: number }>(`/user/${id}`);
}

/** 批量获取用户 */
export function batchShow(ids: number[]): Promise<BaseResponse> {
  return http.get<BaseResponse<null>, { ids: number[] }>(`/user/batch`, {
    params: { ids }
  });
}

/** 删除用户 */
export function destroy(id: number): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { id: number }>(`/user/${id}`);
}

/** 批量删除用户 */
export function batchDestroy(ids: number[]): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { ids: number[] }>(`/user/batch`, {
    data: { ids }
  });
}

/** 管理员直接登录用户 */
export function directLogin(userId: number): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { user_id: number }>(
    "/user/direct-login",
    {
      data: { user_id: userId }
    }
  );
}

/** 创建用户 */
export function createUser(data: {
  email: string;
  username?: string;
}): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, { email: string; username?: string }>(
    "/user/create-user",
    {
      data
    }
  );
}
