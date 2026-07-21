export type HealthStatus = "ok" | "degraded" | "error";
export type QueueLagUnit = "seconds" | "jobs";

export interface SystemHealthData {
  status: HealthStatus;
  freeze: boolean;
  check_statuses: {
    db: HealthStatus;
    cache: HealthStatus;
    heartbeat: HealthStatus;
    queue: HealthStatus;
    disk: HealthStatus;
  };
  queue_lag_unit: QueueLagUnit;
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
