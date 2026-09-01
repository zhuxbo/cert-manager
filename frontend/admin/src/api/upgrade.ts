import { http } from "@/utils/http";

// 版本信息类型
export interface VersionInfo {
  version: string;
  name: string;
  build_time: string;
  build_commit: string;
  channel: string;
}

// 更新检查结果类型
export interface UpdateCheckResult {
  has_update: boolean;
  current_version: string;
  latest_version: string | null;
  changelog: string;
  download_url: string | null;
  package_size: string;
  release_date: string;
  channel: string;
}

// Release 信息类型
export interface ReleaseInfo {
  version: string;
  tag_name: string;
  name: string;
  body: string;
  prerelease: boolean;
  created_at: string;
  published_at: string;
  assets: Array<{
    name: string;
    size: number;
    browser_download_url: string;
  }>;
}

// 备份信息类型
export interface BackupInfo {
  id: string;
  version: string;
  created_at: string;
  size: number;
  includes: {
    backend: boolean;
    database: boolean;
    frontend: boolean;
  };
}

// 升级步骤类型
export interface UpgradeStep {
  step: string;
  status: "pending" | "running" | "completed" | "failed";
  error?: string;
  backup_id?: string;
}

// 升级结果类型（执行后返回）
export interface UpgradeResult {
  success: boolean;
  from_version?: string;
  to_version?: string;
  backup_id?: string;
  steps: UpgradeStep[];
  error?: string;
}

// 升级启动响应类型
export interface UpgradeStartResult {
  started: boolean;
  version: string;
  message: string;
}

export interface StructureCheckSummary {
  missing_tables: string[];
  extra_tables: string[];
  missing_columns: string[];
  extra_columns: string[];
  modified_columns: string[];
  missing_indexes: string[];
  extra_indexes: string[];
  modified_indexes: string[];
  missing_foreign_keys: string[];
  extra_foreign_keys: string[];
  modified_foreign_keys: string[];
  manual_actions: string[];
  can_auto_fix: boolean;
}

// 数据库结构检查结果
export interface StructureCheckResult {
  has_diff: boolean;
  auto_fixed: boolean;
  summary?: StructureCheckSummary;
  executed_count?: number;
  message?: string;
}

// 升级状态类型（轮询获取）
// PHP 环境检测失败时的结构化失败上下文（由后端 EnvironmentChecker::summarize 产出）
export interface PhpEnvironmentErrorDetails {
  type: "php_environment";
  current_php?: string;
  required_php?: string | null;
  recommended_php?: string | null;
  missing_extensions?: string[];
  recommended_missing_extensions?: string[];
  disabled_functions?: string[];
  message?: string;
  remediation?: string;
}

export interface UpgradeStatus {
  status: "idle" | "running" | "completed" | "failed";
  version?: string;
  started_at?: string;
  completed_at?: string;
  failed_at?: string;
  current_step?: string;
  steps: UpgradeStep[];
  progress: number;
  from_version?: string;
  to_version?: string;
  error?: string;
  error_details?: PhpEnvironmentErrorDetails | Record<string, unknown>;
  message?: string;
  structure_check?: StructureCheckResult;
}

// 获取当前版本信息
export function getVersion(): Promise<BaseResponse<VersionInfo>> {
  return http.request<BaseResponse<VersionInfo>>("get", "/upgrade/version");
}

// 检查更新
export function checkUpdate(): Promise<BaseResponse<UpdateCheckResult>> {
  return http.request<BaseResponse<UpdateCheckResult>>("get", "/upgrade/check");
}

// 获取历史版本列表
export function getReleases(): Promise<
  BaseResponse<{ releases: ReleaseInfo[]; current_version: string }>
> {
  return http.request<
    BaseResponse<{ releases: ReleaseInfo[]; current_version: string }>
  >("get", "/upgrade/releases");
}

// 启动升级任务（后台执行）
export function executeUpgrade(
  version: string = "latest"
): Promise<BaseResponse<UpgradeStartResult>> {
  return http.request<BaseResponse<UpgradeStartResult>>(
    "post",
    "/upgrade/execute",
    { data: { version } }
  );
}

// 获取升级状态（轮询使用）
export function getUpgradeStatus(): Promise<BaseResponse<UpgradeStatus>> {
  return http.request<BaseResponse<UpgradeStatus>>("get", "/upgrade/status");
}

// 获取备份列表
export function getBackups(): Promise<BaseResponse<{ backups: BackupInfo[] }>> {
  return http.request<BaseResponse<{ backups: BackupInfo[] }>>(
    "get",
    "/upgrade/backups"
  );
}

// 执行回滚
export function executeRollback(backupId: string): Promise<
  BaseResponse<{
    success: boolean;
    backup_id: string;
    restored_version: string;
  }>
> {
  return http.request<
    BaseResponse<{
      success: boolean;
      backup_id: string;
      restored_version: string;
    }>
  >("post", "/upgrade/rollback", { data: { backup_id: backupId } });
}

// 删除备份
export function deleteBackup(
  backupId: string
): Promise<BaseResponse<{ deleted: boolean }>> {
  return http.request<BaseResponse<{ deleted: boolean }>>(
    "delete",
    "/upgrade/backup",
    { data: { backup_id: backupId } }
  );
}

// 设置发布通道
export function setChannel(
  channel: "main" | "dev"
): Promise<BaseResponse<{ channel: string; message: string }>> {
  return http.request<BaseResponse<{ channel: string; message: string }>>(
    "post",
    "/upgrade/channel",
    { data: { channel } }
  );
}
