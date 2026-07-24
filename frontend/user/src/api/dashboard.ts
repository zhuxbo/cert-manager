import { http } from "@/utils/http";
import type {
  AssetsData,
  OrdersData,
  TrendDataPoint,
  TrendPeriod,
  MonthlyComparisonData
} from "@/types/dashboard";

// 获取资产统计
export function getAssetsData(): Promise<BaseResponse<AssetsData>> {
  return http.get<BaseResponse<AssetsData>, null>("/dashboard/assets");
}

// 获取订单统计
export function getOrdersData(): Promise<BaseResponse<OrdersData>> {
  return http.get<BaseResponse<OrdersData>, null>("/dashboard/orders");
}

// 获取趋势数据
export function getTrendData(
  period: TrendPeriod = "month"
): Promise<BaseResponse<TrendDataPoint[]>> {
  return http.get<BaseResponse<TrendDataPoint[]>, null>(
    `/dashboard/trend?period=${period}`
  );
}

// 获取月度对比数据
export function getMonthlyComparison(): Promise<
  BaseResponse<MonthlyComparisonData>
> {
  return http.get<BaseResponse<MonthlyComparisonData>, null>(
    "/dashboard/monthly-comparison"
  );
}
