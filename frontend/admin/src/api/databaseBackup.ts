import { http } from "@/utils/http";

export interface BackupItem {
  id: string;
  prefix: string; // backup | pre_restore
  filename: string;
  path: string;
  size: number;
  encrypted: boolean; // 6-1：是否为 AES-256-CBC 加密产物（true=*.sql.gz.enc，false=*.sql.gz）
  created_at: string;
  has_schema: boolean;
  schema_size: number;
}

export interface JobProgress {
  status: "queued" | "running" | "completed" | "failed";
  stage?: string;
  message: string;
  backup_id?: string;
  mode?: "full" | "incremental";
  admin_id?: number;
  output?: string;
  updated_at?: string;
}

export interface SchemaDiffSummary {
  missing_tables: string[];
  extra_tables: string[];
  modified_tables: Record<string, Record<string, string[]>>;
}

export interface TableOverviewItem {
  name: string;
  comment: string;
  columns: number;
}

export interface SchemaDiffResult {
  has_schema: boolean;
  has_diff?: boolean;
  message?: string;
  summary?: SchemaDiffSummary;
  tables_overview?: TableOverviewItem[];
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

export function getSchemaDiff(
  backupId: string
): Promise<BaseResponse<SchemaDiffResult>> {
  return http.request<BaseResponse<SchemaDiffResult>>(
    "get",
    `/database/backups/${backupId}/schema-diff`
  );
}

export function restoreBackup(
  backupId: string,
  mode: "full" | "incremental"
): Promise<BaseResponse<{ token: string }>> {
  return http.request<BaseResponse<{ token: string }>>(
    "post",
    `/database/backups/${backupId}/restore`,
    { data: { mode } }
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
