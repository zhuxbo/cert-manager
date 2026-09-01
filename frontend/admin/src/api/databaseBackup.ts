import { http } from "@/utils/http";

export interface BackupItem {
  id: string;
  prefix: string;
  filename: string;
  path: string;
  size: number;
  created_at: string;
  has_schema: boolean;
  schema_size: number;
}

export interface JobProgress {
  status: "queued" | "running" | "completed" | "failed";
  stage?: string;
  message: string;
  backup_id?: string;
  progress?: number;
  percent?: number;
  updated_at?: string;
}

export interface RestoreSchemaDiff {
  has_difference: boolean;
  missing_tables: string[];
  extra_tables: string[];
  changed_tables: string[];
}

export interface RestorePreflightMessage {
  code: string;
  message: string;
  facts?: Record<string, unknown>;
}

export interface MysqlVersionFacts {
  vendor: string;
  version: string;
  series: string;
}

export interface RestorePreflightResult {
  runnable: boolean;
  hard_blockers: RestorePreflightMessage[];
  confirmations: RestorePreflightMessage[];
  warnings: RestorePreflightMessage[];
  artifact: {
    id: string | null;
    legacy: boolean | null;
    sql: string | null;
    schema: string | null;
    integrity: {
      verified: boolean;
      compressed_bytes?: number;
      uncompressed_bytes?: number;
      gzip_eof?: boolean;
    };
  };
  toolchain: {
    supported: boolean;
    errors: string[];
    warnings: string[];
    server?: MysqlVersionFacts;
    mysql?: MysqlVersionFacts | null;
    gzip?: { version: string };
  };
  versions: {
    backup_application: Record<string, string | null> | null;
    current_application: Record<string, string | null>;
    backup_toolchain: Record<string, string | null> | null;
    current_server: MysqlVersionFacts | null;
    current_mysql_client: MysqlVersionFacts | null;
  };
  schema: {
    authoritative: boolean;
    diff: RestoreSchemaDiff;
  };
  space: {
    backup_data_and_indexes_bytes: number;
    current_tables_retained_bytes: number;
    streaming_temp_bytes: number;
    total_estimated_footprint_bytes: number;
    available_bytes: number | null;
    verified: boolean;
    note: string;
  };
  state: { state: string };
}

export function listBackups(): Promise<
  BaseResponse<{ items: BackupItem[]; total: number }>
> {
  return http.request<BaseResponse<{ items: BackupItem[]; total: number }>>(
    "get",
    "/database/backups"
  );
}

export function createBackup(): Promise<BaseResponse<{ token: string }>> {
  return http.request<BaseResponse<{ token: string }>>(
    "post",
    "/database/backups"
  );
}

export function getJobStatus(
  token: string
): Promise<BaseResponse<{ progress: JobProgress }>> {
  return http.request<BaseResponse<{ progress: JobProgress }>>(
    "get",
    `/database/jobs/${token}`
  );
}

export function getRestorePreflight(
  backupId: string
): Promise<BaseResponse<RestorePreflightResult>> {
  return http.request<BaseResponse<RestorePreflightResult>>(
    "get",
    `/database/backups/${backupId}/restore-preflight`,
    undefined,
    { timeout: 300000, suppressErrorMessage: true }
  );
}

export function restoreBackup(
  backupId: string,
  allowSchemaDifference: boolean
): Promise<BaseResponse<{ token: string }>> {
  return http.request<BaseResponse<{ token: string }>>(
    "post",
    `/database/backups/${backupId}/restore`,
    { data: { allow_schema_difference: allowSchemaDifference } }
  );
}

export function deleteBackup(
  backupId: string
): Promise<BaseResponse<{ deleted: number }>> {
  return http.request<BaseResponse<{ deleted: number }>>(
    "delete",
    `/database/backups/${backupId}`
  );
}

export function issueDownloadToken(
  backupId: string
): Promise<BaseResponse<{ token: string; expires_in: number; url: string }>> {
  return http.request<
    BaseResponse<{ token: string; expires_in: number; url: string }>
  >("post", `/database/backups/${backupId}/download-token`);
}
