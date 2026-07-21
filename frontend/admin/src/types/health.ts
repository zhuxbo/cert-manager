export type HealthStatus = "ok" | "degraded" | "error";

export interface SystemHealthData {
  status: HealthStatus;
  freeze: boolean;
  checks: {
    db: {
      ok: boolean;
      latency_ms: number;
    };
    cache: {
      ok: boolean;
    };
    queue_lag_seconds: number;
    disk_free_gb: number;
    heartbeat_age_seconds: number | null;
  };
}
