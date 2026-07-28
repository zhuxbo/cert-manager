import { http } from "@/utils/http";

export type NotificationStatus = "pending" | "sending" | "sent" | "failed";

export interface NotificationRecord {
  id: number;
  template_id: number;
  notifiable_type: string;
  notifiable_id: number;
  status: NotificationStatus;
  data: Record<string, any>;
  created_at: string;
  sent_at?: string;
  template?: {
    name: string;
    code: string;
  };
  notifiable?: {
    username?: string;
    email?: string;
  };
}

export interface NotificationQuery {
  currentPage?: number;
  pageSize?: number;
  status?: NotificationStatus | "";
  template_code?: string;
  user_id?: number;
  notifiable_type?: string;
  created_at?: [string, string];
}

export function index(params: NotificationQuery) {
  return http.get<
    BaseResponse<{
      items: NotificationRecord[];
      total: number;
      pageSize: number;
      currentPage: number;
    }>,
    NotificationQuery
  >("/notification", { params });
}

export function show(id: number) {
  return http.get<BaseResponse<NotificationRecord>, null>(
    `/notification/${id}`
  );
}

export function resend(id: number, data: Record<string, never>) {
  return http.post<BaseResponse<{ notification_id: number }>, typeof data>(
    `/notification/resend/${id}`,
    { data }
  );
}

export function sendTest(data: {
  notifiable_type: string;
  notifiable_id: number;
  template_type: string;
  data?: Record<string, any>;
}) {
  return http.post<BaseResponse<{ notification_id: number }>, typeof data>(
    "/notification/test-send",
    { data }
  );
}
