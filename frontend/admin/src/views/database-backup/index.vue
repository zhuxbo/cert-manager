<script setup lang="ts">
import { onMounted, onUnmounted, reactive, ref } from "vue";
import {
  ElButton,
  ElCard,
  ElTable,
  ElTableColumn,
  ElTag,
  ElDialog,
  ElRadioGroup,
  ElRadio,
  ElAlert,
  ElPopconfirm,
  ElEmpty,
  ElMessageBox,
  ElCollapse,
  ElCollapseItem
} from "element-plus";
import { message } from "@shared/utils";
import {
  listBackups,
  createBackup,
  getJobStatus,
  getSchemaDiff,
  restoreBackup,
  deleteBackup,
  issueDownloadToken,
  type BackupItem,
  type JobProgress,
  type SchemaDiffResult
} from "@/api/databaseBackup";

defineOptions({ name: "DatabaseBackup" });

const items = ref<BackupItem[]>([]);
const loading = ref(false);

// 当前运行的 Job（创建 or 恢复）
const activeJob = reactive({
  token: "" as string,
  progress: null as JobProgress | null
});
let pollTimer: ReturnType<typeof setInterval> | null = null;

// 恢复弹窗状态
const restoreDialog = reactive({
  visible: false,
  backup: null as BackupItem | null,
  mode: "incremental" as "incremental" | "full",
  loadingDiff: false,
  diff: null as SchemaDiffResult | null,
  submitting: false
});

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

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

function startPolling(token: string, onDone: () => void) {
  stopPolling();
  activeJob.token = token;
  activeJob.progress = { status: "queued", message: "任务已入队" };

  pollTimer = setInterval(async () => {
    try {
      const resp = await getJobStatus(token);
      const p = resp.data?.progress;
      if (!p) return;
      activeJob.progress = p;
      if (p.status === "completed" || p.status === "failed") {
        stopPolling();
        const type = p.status === "completed" ? "success" : "error";
        message(p.message || p.status, { type });
        onDone();
      }
    } catch {
      stopPolling();
      activeJob.progress = { status: "failed", message: "轮询进度失败" };
    }
  }, 2000);
}

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
    startPolling(token, () => {
      activeJob.token = "";
      load();
    });
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
  restoreDialog.mode = "incremental";
  restoreDialog.diff = null;
  restoreDialog.visible = true;
  loadDiff();
}

async function loadDiff() {
  if (!restoreDialog.backup) return;
  restoreDialog.loadingDiff = true;
  try {
    const resp = await getSchemaDiff(restoreDialog.backup.id);
    restoreDialog.diff = resp.data ?? null;
  } catch {
    restoreDialog.diff = { has_schema: false, message: "加载 schema 失败" };
  } finally {
    restoreDialog.loadingDiff = false;
  }
}

async function submitRestore() {
  if (!restoreDialog.backup) return;

  const modeLabel =
    restoreDialog.mode === "full" ? "全量恢复（覆盖当前库）" : "增量恢复";
  try {
    await ElMessageBox.confirm(
      `确认执行 ${modeLabel}？系统将自动创建"恢复前快照"作为保险，恢复期间进入维护模式。`,
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
    const resp = await restoreBackup(
      restoreDialog.backup.id,
      restoreDialog.mode
    );
    const token = resp.data?.token;
    if (!token) throw new Error("无 token");
    restoreDialog.visible = false;
    startPolling(token, () => {
      activeJob.token = "";
      load();
    });
  } catch {
    message("发起恢复失败", { type: "error" });
  } finally {
    restoreDialog.submitting = false;
  }
}

function diffSummaryText(diff: SchemaDiffResult): string[] {
  if (!diff.has_schema || !diff.summary) return [];
  const out: string[] = [];
  const s = diff.summary;
  if (s.missing_tables.length) {
    out.push(
      `备份中存在、当前库缺少的表 (${s.missing_tables.length}): ${s.missing_tables.join(", ")}`
    );
  }
  if (s.extra_tables.length) {
    out.push(
      `当前库存在、备份中没有的表 (${s.extra_tables.length}): ${s.extra_tables.join(", ")}`
    );
  }
  Object.entries(s.modified_tables).forEach(([table, parts]) => {
    const descs: string[] = [];
    if (parts.missing_columns?.length)
      descs.push(`缺失列 ${parts.missing_columns.join("/")}`);
    if (parts.extra_columns?.length)
      descs.push(`多余列 ${parts.extra_columns.join("/")}`);
    if (parts.modified_columns?.length)
      descs.push(`列类型变更 ${parts.modified_columns.join("/")}`);
    if (parts.missing_indexes?.length)
      descs.push(`缺失索引 ${parts.missing_indexes.join("/")}`);
    if (parts.extra_indexes?.length)
      descs.push(`多余索引 ${parts.extra_indexes.join("/")}`);
    if (descs.length) out.push(`${table}: ${descs.join("；")}`);
  });
  return out;
}

onMounted(load);
onUnmounted(stopPolling);
</script>

<template>
  <div class="p-4">
    <!-- 备份加密密钥关键告警，置顶常驻不可关闭 -->
    <el-alert
      type="warning"
      :closable="false"
      show-icon
      class="mb-3"
      style="margin-bottom: 12px"
    >
      <template #title>
        <strong>密钥丢失 = 备份不可恢复</strong>
      </template>
      <template #default>
        备份默认启用 AES-256-CBC 加密。请将
        <code>.env</code> 中的 <code>BACKUP_ENC_KEY</code>
        离线保存（U 盘 / 密码管理器）。密钥丢失后，无法恢复任何加密备份。
      </template>
    </el-alert>

    <el-card shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="text-base font-medium">数据库备份</span>
          <div class="flex gap-2">
            <el-button
              type="primary"
              :disabled="!!activeJob.token"
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
        :title="`任务进行中：${activeJob.progress.message}`"
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

      <el-table v-loading="loading" :data="items" empty-text="暂无备份" stripe>
        <el-table-column prop="filename" label="文件名" min-width="260" />
        <el-table-column label="类型" width="110">
          <template #default="{ row }">
            <el-tag
              :type="row.prefix === 'pre_restore' ? 'warning' : 'success'"
              size="small"
            >
              {{ row.prefix === "pre_restore" ? "恢复前快照" : "常规备份" }}
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
              :disabled="!!activeJob.token"
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
                <el-button
                  size="small"
                  type="danger"
                  :disabled="!!activeJob.token"
                >
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

        <!-- 结构对比 -->
        <div class="mb-4">
          <div class="mb-2 text-sm font-medium">结构对比</div>
          <div v-if="restoreDialog.loadingDiff" class="text-gray-400 text-sm">
            正在对比...
          </div>
          <template v-else-if="restoreDialog.diff">
            <el-alert
              v-if="!restoreDialog.diff.has_schema"
              type="warning"
              :closable="false"
              :title="
                restoreDialog.diff.message ||
                '旧备份无结构信息，无法对比。增量恢复可能遇到列不存在错误。'
              "
            />
            <el-alert
              v-else-if="!restoreDialog.diff.has_diff"
              type="success"
              :closable="false"
              title="当前数据库结构与备份完全一致"
            />
            <el-alert
              v-else
              type="warning"
              :closable="false"
              title="结构存在差异，增量恢复可能失败；建议先审查差异"
            >
              <template #default>
                <ul class="mt-2 list-disc pl-5 text-xs">
                  <li
                    v-for="(line, i) in diffSummaryText(restoreDialog.diff)"
                    :key="i"
                  >
                    {{ line }}
                  </li>
                </ul>
              </template>
            </el-alert>
          </template>
        </div>

        <!-- 备份结构中文概览（折叠，无论是否有差异都可查看） -->
        <el-collapse
          v-if="
            restoreDialog.diff?.has_schema &&
            restoreDialog.diff.tables_overview?.length
          "
          class="mb-4"
        >
          <el-collapse-item name="overview">
            <template #title>
              <span class="text-sm">
                查看备份结构（{{ restoreDialog.diff.tables_overview.length }}
                张表）
              </span>
            </template>
            <el-table
              :data="restoreDialog.diff.tables_overview"
              size="small"
              max-height="260"
              stripe
            >
              <el-table-column
                prop="name"
                label="表名"
                min-width="180"
                show-overflow-tooltip
              />
              <el-table-column
                prop="comment"
                label="说明"
                min-width="200"
                show-overflow-tooltip
              >
                <template #default="{ row }">
                  <span :class="{ 'text-gray-400': !row.comment }">
                    {{ row.comment || "—" }}
                  </span>
                </template>
              </el-table-column>
              <el-table-column
                prop="columns"
                label="字段数"
                width="90"
                align="center"
              />
            </el-table>
          </el-collapse-item>
        </el-collapse>

        <!-- 模式选择 -->
        <div class="mb-2 text-sm font-medium">恢复模式</div>
        <el-radio-group
          v-model="restoreDialog.mode"
          class="restore-mode-group flex w-full flex-col gap-3"
        >
          <el-radio value="incremental" class="restore-mode-radio">
            <div class="flex flex-col gap-1">
              <span class="font-semibold">增量恢复（INSERT IGNORE）</span>
              <span class="text-xs text-gray-500 leading-relaxed">
                保留当前库数据，仅补回备份中存在、当前库缺失的主键行；不改结构
              </span>
            </div>
          </el-radio>
          <el-radio value="full" class="restore-mode-radio">
            <div class="flex flex-col gap-1">
              <span class="font-semibold"
                >全量恢复（DROP + CREATE + INSERT）</span
              >
              <span class="text-xs text-gray-500 leading-relaxed">
                把库回滚到备份时刻，当前库内容被覆盖，请谨慎
              </span>
            </div>
          </el-radio>
        </el-radio-group>
      </template>

      <template #footer>
        <el-button @click="restoreDialog.visible = false">取消</el-button>
        <el-button
          type="danger"
          :loading="restoreDialog.submitting"
          @click="submitRestore"
        >
          执行恢复
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
/* el-radio 默认 inline、有 margin-left；改为左对齐 + 顶对齐 + label 可换行 */
.restore-mode-group :deep(.el-radio) {
  width: 100%;
  margin-right: 0;
  margin-left: 0;
}

.restore-mode-radio {
  align-items: flex-start;
  height: auto;
  white-space: normal;
}

.restore-mode-radio :deep(.el-radio__label) {
  padding-left: 8px;
  line-height: 1.4;
  white-space: normal;
}

.restore-mode-radio :deep(.el-radio__input) {
  margin-top: 3px;
}
</style>
