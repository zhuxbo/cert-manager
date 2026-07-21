// 用户端Dashboard数据类型定义

// 资产统计数据
export interface AssetsData {
  balance: number;
}

// 订单统计数据（包含订单状态分布）
export interface OrdersData {
  total_orders: number;
  active_orders: number;
  expiring_7_days: number;
  expiring_30_days: number;
  cancelled_orders: number;
  net_orders: number;
  status_distribution: Record<string, number>;
  monthly_orders: number;
  monthly_cancelled_orders: number;
  monthly_net_orders: number;
  monthly_consumption: number;
}

// 趋势数据点
export interface TrendDataPoint {
  date: string;
  orders: number;
  cancelled_orders: number;
  net_orders: number;
  consumption: number;
}

// 月度对比数据
export interface MonthlyComparisonData {
  current_month: {
    orders: number;
    cancelled_orders: number;
    net_orders: number;
    consumption: number;
  };
  last_month: {
    orders: number;
    cancelled_orders: number;
    net_orders: number;
    consumption: number;
  };
  growth: {
    orders: number;
    cancelled_orders: number;
    net_orders: number;
    consumption: number;
  };
}

// Dashboard总览数据
export interface DashboardOverview {
  overview: {
    balance: number;
    total_orders: number;
    active_orders: number;
    expiring_7_days: number;
    expiring_30_days: number;
    monthly_orders: number;
    monthly_consumption: number;
  };
  trend: TrendDataPoint[];
}
