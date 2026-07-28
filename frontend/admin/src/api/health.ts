import type { SystemHealthData } from "@/types/health";

export async function getSystemHealth(): Promise<SystemHealthData> {
  const response = await fetch("/api/health", {
    headers: { Accept: "application/json" },
    cache: "no-store"
  });
  const data = (await response.json()) as Partial<SystemHealthData>;

  if (!data.status || !data.checks) {
    throw new Error("健康检查响应格式错误");
  }

  return data as SystemHealthData;
}
