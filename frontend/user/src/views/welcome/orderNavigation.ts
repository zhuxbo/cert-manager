import dayjs, { type Dayjs } from "dayjs";

export const processingStatusOptions = [
  { status: "unpaid", label: "待支付" },
  { status: "pending", label: "待提交" },
  { status: "processing", label: "待验证" },
  { status: "approving", label: "审核中" }
] as const;

export type ProcessingStatus =
  (typeof processingStatusOptions)[number]["status"];

export function buildExpiringOrderQuery(days: 7 | 30, now: Dayjs = dayjs()) {
  return {
    expires_at: [
      now.format("YYYY-MM-DD"),
      now.add(days - 1, "day").format("YYYY-MM-DD")
    ],
    sort_prop: "expires_at",
    sort_order: "asc"
  };
}

export function buildProcessingOrderQuery(status: ProcessingStatus) {
  return { status };
}
