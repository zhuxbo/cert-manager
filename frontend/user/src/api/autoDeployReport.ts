import { http } from "@/utils/http";

export interface AutoDeployReportIndexParams {
  currentPage?: number;
  pageSize?: number;
  quickSearch?: string;
  order_id?: number;
  status?: "success" | "failure";
  ip?: string;
  time?: [string, string];
}

export function index(params: AutoDeployReportIndexParams) {
  return http.get<BaseResponse<null>, AutoDeployReportIndexParams>(
    "/auto-deploy-report",
    { params }
  );
}
