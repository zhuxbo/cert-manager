import { http } from "@/utils/http";

export interface IndexParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  code?: string;
  custom?: number;
}

export interface UserLevelDto {
  id: number;
  code: string;
  name: string;
  custom: number;
  cost_rate: string;
  weight: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface IndexData {
  items: UserLevelDto[];
  total: number;
  pageSize: number;
  currentPage: number;
}

/** 获取用户级别列表 */
export function index(params: IndexParams): Promise<BaseResponse<IndexData>> {
  return http.get<BaseResponse<IndexData>, IndexParams>("/user-level", {
    params
  });
}

// 定义 FormParams 的默认值对象
export const FORM_PARAMS_DEFAULT = {
  code: "",
  name: "",
  custom: 1,
  cost_rate: 1.0,
  weight: 100
};

// 从默认值对象中提取键
export const FORM_PARAMS_KEYS = Object.keys(
  FORM_PARAMS_DEFAULT
) as (keyof typeof FORM_PARAMS_DEFAULT)[];

// 从默认值对象中提取类型
export type FormParams = {
  [K in keyof typeof FORM_PARAMS_DEFAULT]?: (typeof FORM_PARAMS_DEFAULT)[K];
};

export type MutationParams = Omit<FormParams, "cost_rate"> & {
  cost_rate?: string;
};

/** 添加用户级别 */
export function store(data: MutationParams): Promise<BaseResponse> {
  return http.post<BaseResponse<null>, MutationParams>("/user-level", { data });
}

/** 更新用户级别 */
export function update(
  id: number,
  data: MutationParams
): Promise<BaseResponse> {
  return http.put<BaseResponse<null>, MutationParams>(`/user-level/${id}`, {
    data
  });
}

/** 获取用户级别 */
export function show(id: number): Promise<BaseResponse<UserLevelDto>> {
  return http.get<BaseResponse<UserLevelDto>, { id: number }>(
    `/user-level/${id}`
  );
}

/** 批量获取用户级别 */
export function batchShow(
  ids: number[]
): Promise<BaseResponse<UserLevelDto[]>> {
  return http.get<BaseResponse<UserLevelDto[]>, { ids: number[] }>(
    `/user-level/batch`,
    {
      params: { ids }
    }
  );
}

/** 批量获取用户级别 */
export function batchShowInCodes(
  codes: string[]
): Promise<BaseResponse<UserLevelDto[]>> {
  return http.get<BaseResponse<UserLevelDto[]>, { codes: string[] }>(
    `/user-level/batch-codes`,
    { params: { codes } }
  );
}

/** 删除用户级别 */
export function destroy(id: number): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { id: number }>(`/user-level/${id}`);
}

/** 批量删除用户级别 */
export function batchDestroy(ids: number[]): Promise<BaseResponse> {
  return http.delete<BaseResponse<null>, { ids: number[] }>(
    `/user-level/batch`,
    {
      data: { ids }
    }
  );
}
