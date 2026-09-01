<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import {
  ElButton,
  ElCard,
  ElTable,
  ElTableColumn,
  ElTag,
  ElDialog,
  ElCheckbox,
  ElAlert,
  ElPopconfirm,
  ElEmpty,
  ElMessageBox,
  ElDescriptions,
  ElDescriptionsItem,
  ElCollapse,
  ElCollapseItem
} from "element-plus";
import { message } from "@shared/utils";
import { usePolling } from "@shared/hooks";
import {
  listBackups,
  createBackup,
  getJobStatus,
  getRestorePreflight,
  restoreBackup,
  deleteBackup,
  issueDownloadToken,
  type BackupItem,
  type JobProgress,
  type RestorePreflightMessage,
  type RestorePreflightResult
} from "@/api/databaseBackup";

defineOptions({ name: "DatabaseBackup" });

const items = ref<BackupItem[]>([]);
const loading = ref(false);

// 当前运行的 Job（创建 or 恢复）
const activeJob = reactive({
  token: "" as string,
  backupId: "" as string,
  allowSchemaDifference: false,
  progress: null as JobProgress | null
});
const jobRunning = computed(
  () =>
    activeJob.progress?.status === "queued" ||
    activeJob.progress?.status === "running"
);

// 恢复弹窗状态
const restoreDialog = reactive({
  visible: false,
  backup: null as BackupItem | null,
  loadingPreflight: false,
  preflight: null as RestorePreflightResult | null,
  allowSchemaDifference: false,
  submitting: false
});
const restoreDetailSections = ref<string[]>([]);
let restorePreflightRequestId = 0;

const needsSchemaConfirmation = computed(() =>
  restoreDialog.preflight?.confirmations.some(item =>
    ["schema_difference", "schema_not_authoritative"].includes(item.code)
  )
);
const hasPreflightWarnings = computed(
  () => (restoreDialog.preflight?.warnings.length ?? 0) > 0
);

const canSubmitRestore = computed(
  () =>
    restoreDialog.preflight !== null &&
    restoreDialog.preflight.hard_blockers.length === 0 &&
    (!needsSchemaConfirmation.value || restoreDialog.allowSchemaDifference)
);

function formatSize(bytes: number): string {
  const units = ["B", "KB", "MB", "GB"];
  let i = 0;
  let v = bytes;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return `${v.toFixed(2)} ${units[i]}`;
}

async function load() {
  loading.value = true;
  try {
    const resp = await listBackups();
    items.value = resp.data?.items ?? [];
  } catch {
    message("加载备份列表失败", { type: "error" });
  } finally {
    loading.value = false;
  }
}

function startPolling(
  token: string,
  backupId = "",
  allowSchemaDifference = false
) {
  activeJob.token = token;
  activeJob.backupId = backupId;
  activeJob.allowSchemaDifference = allowSchemaDifference;
  activeJob.progress = { status: "queued", message: "任务已入队" };
}

async function pollActiveJob() {
  if (!activeJob.token || !jobRunning.value) return;
  try {
    const resp = await getJobStatus(activeJob.token);
    const progress = resp.data?.progress;
    if (!progress) return;
    activeJob.progress = progress;
    if (progress.status === "completed" || progress.status === "failed") {
      const type = progress.status === "completed" ? "success" : "error";
      message(progress.message || progress.status, { type });
      await load();
    }
  } catch {
    activeJob.progress = { status: "failed", message: "轮询进度失败" };
  }
}

usePolling(pollActiveJob, {
  interval: 2000,
  shouldSkip: () => !activeJob.token || !jobRunning.value
});

async function handleCreate() {
  try {
    await ElMessageBox.confirm(
      "立即创建一份数据库备份？将在后台异步执行。",
      "创建备份",
      { type: "info" }
    );
  } catch {
    return;
  }

  try {
    const resp = await createBackup();
    const token = resp.data?.token;
    if (!token) throw new Error("无 token");
    startPolling(token);
  } catch {
    message("创建备份失败", { type: "error" });
  }
}

async function handleDelete(backup: BackupItem) {
  try {
    await deleteBackup(backup.id);
    message("已删除", { type: "success" });
    load();
  } catch {
    message("删除失败", { type: "error" });
  }
}

async function handleDownload(backup: BackupItem) {
  try {
    const resp = await issueDownloadToken(backup.id);
    const url = resp.data?.url;
    if (!url) throw new Error("无下载链接");
    // 浏览器原生流式下载：新开标签，内容直接写磁盘
    window.open(url, "_blank");
  } catch {
    message("获取下载链接失败", { type: "error" });
  }
}

function openRestore(backup: BackupItem) {
  restoreDialog.backup = backup;
  restoreDialog.preflight = null;
  restoreDialog.allowSchemaDifference = false;
  restoreDetailSections.value = [];
  restoreDialog.visible = true;
  loadPreflight();
}

async function loadPreflight() {
  if (!restoreDialog.backup) return;
  const backupId = restoreDialog.backup.id;
  const requestId = ++restorePreflightRequestId;
  const isCurrentRequest = () =>
    requestId === restorePreflightRequestId &&
    restoreDialog.visible &&
    restoreDialog.backup?.id === backupId;
  restoreDialog.loadingPreflight = true;
  try {
    const resp = await getRestorePreflight(backupId);
    if (!isCurrentRequest()) return;
    restoreDialog.preflight = resp.data ?? null;
  } catch {
    if (!isCurrentRequest()) return;
    restoreDialog.preflight = null;
    message("加载恢复预检失败", { type: "error" });
  } finally {
    if (isCurrentRequest()) {
      restoreDialog.loadingPreflight = false;
    }
  }
}

async function submitRestore() {
  if (!restoreDialog.backup) return;

  if (!canSubmitRestore.value) return;
  try {
    await ElMessageBox.confirm(
      "确认执行原子数据库恢复？恢复期间将冻结写入、暂停队列并进入维护模式。",
      "恢复确认",
      {
        type: "warning",
        confirmButtonText: "我明白，执行恢复",
        cancelButtonText: "取消"
      }
    );
  } catch {
    return;
  }

  restoreDialog.submitting = true;
  try {
    const backupId = restoreDialog.backup.id;
    const allowSchemaDifference = restoreDialog.allowSchemaDifference;
    const resp = await restoreBackup(backupId, allowSchemaDifference);
    const token = resp.data?.token;
    if (!token) throw new Error("无 token");
    restoreDialog.visible = false;
    startPolling(token, backupId, allowSchemaDifference);
  } catch {
    message("发起恢复失败", { type: "error" });
  } finally {
    restoreDialog.submitting = false;
  }
}

function schemaDiffSummary(preflight: RestorePreflightResult): string {
  const diff = preflight.schema.diff;
  const parts: string[] = [];
  if (diff.missing_tables.length) {
    parts.push(`备份多 ${diff.missing_tables.length} 张表`);
  }
  if (diff.extra_tables.length) {
    parts.push(`当前库多 ${diff.extra_tables.length} 张表`);
  }
  if (diff.changed_tables.length) {
    parts.push(`${diff.changed_tables.length} 张表结构有变化`);
  }
  return parts.join("，") || "结构一致";
}

function isForeignKeyBlocker(item: RestorePreflightMessage): boolean {
  return ["cross_range_foreign_key", "foreign_key_name_conflict"].includes(
    item.code
  );
}

function blockerShortLabel(item: RestorePreflightMessage): string {
  const labels: Record<string, string> = {
    artifact_invalid: "备份文件无效",
    current_schema_unavailable: "无法读取当前数据库结构",
    invalid_identifier: "备份中包含无效表名",
    schema_invalid: "备份 Schema 无效",
    sql_table_set_mismatch: "备份 SQL 与 Schema 的表不一致",
    sql_unsafe: "备份 SQL 未通过安全检查",
    restore_state_not_clean: "存在未清理的恢复现场",
    restore_state_unavailable: "无法检查恢复现场"
  };
  return labels[item.code] || "存在其它恢复阻断";
}

function blockerSummary(preflight: RestorePreflightResult): string[] {
  const summaries = new Set<string>();
  const blockers = preflight.hard_blockers;
  if (blockers.some(item => item.code === "toolchain_unsupported")) {
    summaries.add("当前 MySQL 工具链不受支持");
  }
  const foreignKeyCount = blockers.filter(isForeignKeyBlocker).length;
  if (foreignKeyCount > 0) {
    summaries.add(`存在 ${foreignKeyCount} 个无法自动处理的外键`);
  }
  blockers.forEach(item => {
    if (item.code !== "toolchain_unsupported" && !isForeignKeyBlocker(item)) {
      summaries.add(blockerShortLabel(item));
    }
  });
  return [...summaries];
}

function toolchainDetails(preflight: RestorePreflightResult): string[] {
  if (preflight.toolchain.errors.length) return preflight.toolchain.errors;
  return preflight.hard_blockers
    .filter(item => item.code === "toolchain_unsupported")
    .map(item => item.message);
}

function otherBlockers(
  preflight: RestorePreflightResult
): RestorePreflightMessage[] {
  return preflight.hard_blockers.filter(
    item => item.code !== "toolchain_unsupported"
  );
}

function preflightConclusion(preflight: RestorePreflightResult): string {
  if (preflight.hard_blockers.length > 0) {
    return `暂时无法恢复（${preflight.hard_blockers.length} 项阻断）`;
  }
  if (needsSchemaConfirmation.value) return "可以恢复，但需确认 Schema 差异";
  if (hasPreflightWarnings.value) return "可以恢复，但存在兼容提示";
  return "预检通过，可以恢复";
}

function applicationVersion(
  facts: Record<string, string | null> | null
): string {
  if (!facts) return "未记录";
  const version = facts.version || "未知";
  const channel = facts.channel ? ` (${facts.channel})` : "";
  return `${version}${channel}`;
}

function mysqlVersion(
  facts: { vendor: string; version: string; series: string } | null | undefined
): string {
  return facts
    ? `${facts.vendor} ${facts.version} (series ${facts.series})`
    : "未记录";
}

const progressStageLabels: Record<string, string> = {
  preflight: "恢复预检",
  freeze: "冻结写入与队列",
  create_shadow: "创建影子表",
  import: "导入备份",
  prepare_structure: "整理恢复结构",
  validate: "校验数据库",
  wait_metadata_lock: "等待元数据锁",
  cutover: "原子切换",
  runtime_cleanup: "清理运行时",
  complete: "恢复完成"
};

function progressStage(stage?: string): string {
  return stage ? progressStageLabels[stage] || stage : "等待执行";
}

function retryCommand(): string {
  if (!activeJob.backupId) return "";
  const flag = activeJob.allowSchemaDifference
    ? " --allow-schema-difference"
    : "";
  return `php artisan database:restore ${activeJob.backupId}${flag}`;
}

onMounted(load);
</script>

<template>
  <div class="p-4">
    <el-card shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="text-base font-medium">数据库备份</span>
          <div class="flex gap-2">
            <el-button
              type="primary"
              :disabled="jobRunning"
              @click="handleCreate"
            >
              创建备份
            </el-button>
            <el-button :loading="loading" @click="load">刷新</el-button>
          </div>
        </div>
      </template>

      <!-- 当前任务进度 -->
      <el-alert
        v-if="activeJob.progress && activeJob.token"
        :title="`${progressStage(activeJob.progress.stage)}：${activeJob.progress.message}`"
        :type="
          activeJob.progress.status === 'failed'
            ? 'error'
            : activeJob.progress.status === 'completed'
              ? 'success'
              : 'info'
        "
        :closable="false"
        class="mb-3"
      />
      <el-alert
        v-if="activeJob.progress?.status === 'failed' && retryCommand()"
        type="warning"
        :closable="false"
        class="mb-3"
      >
        <template #title>可在服务器上同步续接恢复</template>
        <code class="text-xs">{{ retryCommand() }}</code>
      </el-alert>

      <el-table v-loading="loading" :data="items" empty-text="暂无备份" stripe>
        <el-table-column prop="filename" label="文件名" min-width="260" />
        <el-table-column label="类型" width="110">
          <template #default="{ row }">
            <el-tag
              :type="row.prefix === 'pre_restore' ? 'warning' : 'success'"
              size="small"
            >
              {{ row.prefix === "pre_restore" ? "历史恢复快照" : "常规备份" }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="大小" width="120">
          <template #default="{ row }">{{ formatSize(row.size) }}</template>
        </el-table-column>
        <el-table-column label="结构" width="100">
          <template #default="{ row }">
            <el-tag v-if="row.has_schema" type="info" size="small"
              >已附带</el-tag
            >
            <el-tag v-else type="warning" size="small">缺失</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="180" />
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button
              size="small"
              type="primary"
              :disabled="jobRunning"
              @click="openRestore(row)"
            >
              恢复
            </el-button>
            <el-button size="small" @click="handleDownload(row)">
              下载
            </el-button>
            <el-popconfirm
              title="确定删除该备份（含 schema.json）？"
              @confirm="handleDelete(row)"
            >
              <template #reference>
                <el-button size="small" type="danger" :disabled="jobRunning">
                  删除
                </el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>

        <template #empty>
          <el-empty description="暂无备份数据" />
        </template>
      </el-table>
    </el-card>

    <!-- 恢复弹窗 -->
    <el-dialog
      v-model="restoreDialog.visible"
      title="恢复数据库"
      width="720px"
      destroy-on-close
      class="restore-dialog"
    >
      <template v-if="restoreDialog.backup">
        <div class="mb-3 text-sm">
          将从 <b>{{ restoreDialog.backup.filename }}</b> 恢复
          <span class="text-gray-400">
            ({{ formatSize(restoreDialog.backup.size) }} ·
            {{ restoreDialog.backup.created_at }})
          </span>
        </div>

        <!-- 恢复预检 -->
        <div class="mb-4">
          <div class="mb-2 text-sm font-medium">恢复预检</div>
          <div
            v-if="restoreDialog.loadingPreflight"
            class="text-gray-400 text-sm"
          >
            正在检查备份完整性、工具链和 Schema...
          </div>
          <template v-else-if="restoreDialog.preflight">
            <el-alert
              :type="
                restoreDialog.preflight.hard_blockers.length
                  ? 'error'
                  : needsSchemaConfirmation
                    ? 'warning'
                    : hasPreflightWarnings
                      ? 'warning'
                      : 'success'
              "
              :closable="false"
              :title="preflightConclusion(restoreDialog.preflight)"
              class="mb-3"
            >
              <template #default>
                <ul
                  v-if="restoreDialog.preflight.hard_blockers.length"
                  class="mt-2 list-disc pl-5 text-xs"
                >
                  <li
                    v-for="line in blockerSummary(restoreDialog.preflight)"
                    :key="line"
                  >
                    {{ line }}
                  </li>
                </ul>
                <div v-else class="mt-1 text-xs">
                  {{
                    !restoreDialog.preflight.artifact.legacy &&
                    restoreDialog.preflight.artifact.integrity.verified
                      ? "备份文件 SHA-256 校验通过。"
                      : "旧版备份没有完整性元数据，将按兼容规则校验。"
                  }}
                </div>
                <div
                  v-if="restoreDialog.preflight.warnings.length"
                  class="mt-1 text-xs"
                >
                  另有 {{ restoreDialog.preflight.warnings.length }}
                  条提示，可展开查看。
                </div>
              </template>
            </el-alert>

            <el-alert
              v-if="restoreDialog.preflight.schema.diff.has_difference"
              type="warning"
              :closable="false"
              :title="`Schema 存在差异：${schemaDiffSummary(restoreDialog.preflight)}`"
              class="mb-3"
            />

            <el-collapse
              v-model="restoreDetailSections"
              class="restore-preflight-details"
            >
              <el-collapse-item name="preflight" title="查看完整预检详情">
                <el-descriptions :column="2" border size="small" class="mb-3">
                  <el-descriptions-item label="备份程序版本">
                    {{
                      applicationVersion(
                        restoreDialog.preflight.versions.backup_application
                      )
                    }}
                  </el-descriptions-item>
                  <el-descriptions-item label="当前程序版本">
                    {{
                      applicationVersion(
                        restoreDialog.preflight.versions.current_application
                      )
                    }}
                  </el-descriptions-item>
                  <el-descriptions-item label="备份时 MySQL">
                    服务端
                    {{
                      restoreDialog.preflight.versions.backup_toolchain
                        ?.server_version || "未记录"
                    }}，客户端
                    {{
                      restoreDialog.preflight.versions.backup_toolchain
                        ?.client_version || "未记录"
                    }}
                  </el-descriptions-item>
                  <el-descriptions-item label="当前 MySQL">
                    服务端
                    {{
                      mysqlVersion(
                        restoreDialog.preflight.versions.current_server
                      )
                    }}，客户端
                    {{
                      mysqlVersion(
                        restoreDialog.preflight.versions.current_mysql_client
                      )
                    }}
                  </el-descriptions-item>
                  <el-descriptions-item label="工具链">
                    {{
                      restoreDialog.preflight.toolchain.supported
                        ? "已匹配"
                        : "不受支持"
                    }}
                  </el-descriptions-item>
                  <el-descriptions-item label="容量估算">
                    {{
                      formatSize(
                        restoreDialog.preflight.space
                          .total_estimated_footprint_bytes
                      )
                    }}
                  </el-descriptions-item>
                </el-descriptions>

                <div
                  v-if="restoreDialog.preflight.schema.diff.has_difference"
                  class="mb-3 text-xs"
                >
                  <div class="mb-1 font-medium">Schema 差异表</div>
                  <div
                    v-if="
                      restoreDialog.preflight.schema.diff.missing_tables.length
                    "
                    class="mb-1 break-all"
                  >
                    <b>仅备份：</b>
                    {{
                      restoreDialog.preflight.schema.diff.missing_tables.join(
                        ", "
                      )
                    }}
                  </div>
                  <div
                    v-if="
                      restoreDialog.preflight.schema.diff.extra_tables.length
                    "
                    class="mb-1 break-all"
                  >
                    <b>仅当前库：</b>
                    {{
                      restoreDialog.preflight.schema.diff.extra_tables.join(
                        ", "
                      )
                    }}
                  </div>
                  <div
                    v-if="
                      restoreDialog.preflight.schema.diff.changed_tables.length
                    "
                    class="break-all"
                  >
                    <b>结构变化：</b>
                    {{
                      restoreDialog.preflight.schema.diff.changed_tables.join(
                        ", "
                      )
                    }}
                  </div>
                </div>

                <div
                  v-if="toolchainDetails(restoreDialog.preflight).length"
                  class="mb-3 text-xs"
                >
                  <div class="mb-1 font-medium text-red-500">工具链诊断</div>
                  <ul class="list-disc pl-5 break-all">
                    <li
                      v-for="(line, i) in toolchainDetails(
                        restoreDialog.preflight
                      )"
                      :key="`toolchain-${i}`"
                    >
                      {{ line }}
                    </li>
                  </ul>
                </div>

                <div
                  v-if="otherBlockers(restoreDialog.preflight).length"
                  class="mb-3 text-xs"
                >
                  <div class="mb-1 font-medium text-red-500">其它阻断</div>
                  <ul class="list-disc pl-5 break-all">
                    <li
                      v-for="item in otherBlockers(restoreDialog.preflight)"
                      :key="`blocker-${item.code}-${item.message}`"
                    >
                      {{ item.message }}
                    </li>
                  </ul>
                </div>

                <div
                  v-if="restoreDialog.preflight.warnings.length"
                  class="mb-3 text-xs"
                >
                  <div class="mb-1 font-medium text-amber-500">兼容提示</div>
                  <ul class="list-disc pl-5 break-all">
                    <li
                      v-for="item in restoreDialog.preflight.warnings"
                      :key="`warning-${item.code}`"
                    >
                      {{ item.message }}
                    </li>
                  </ul>
                </div>

                <div class="text-xs text-gray-500">
                  {{ restoreDialog.preflight.space.note }}
                </div>
              </el-collapse-item>
            </el-collapse>

            <el-checkbox
              v-if="
                needsSchemaConfirmation &&
                restoreDialog.preflight.hard_blockers.length === 0
              "
              v-model="restoreDialog.allowSchemaDifference"
              class="mt-4 h-auto items-start whitespace-normal"
            >
              我已确认 Schema 差异，仍然恢复
            </el-checkbox>
          </template>
        </div>
      </template>

      <template #footer>
        <el-button @click="restoreDialog.visible = false">取消</el-button>
        <el-button
          type="danger"
          :loading="restoreDialog.submitting"
          :disabled="
            restoreDialog.loadingPreflight || !canSubmitRestore || jobRunning
          "
          @click="submitRestore"
        >
          执行恢复
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>
