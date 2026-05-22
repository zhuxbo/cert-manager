<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from "vue";
import {
  getVersion,
  checkUpdate,
  getReleases,
  executeUpgrade,
  getUpgradeStatus,
  getBackups,
  executeRollback,
  deleteBackup,
  setChannel,
  type VersionInfo,
  type UpdateCheckResult,
  type ReleaseInfo,
  type BackupInfo,
  type UpgradeStep,
  type UpgradeStatus,
  type PhpEnvironmentErrorDetails
} from "@/api/upgrade";
import { message } from "@shared/utils";
import {
  ElAlert,
  ElButton,
  ElCard,
  ElTag,
  ElPopconfirm,
  ElProgress,
  ElEmpty,
  ElTooltip,
  ElSelect,
  ElOption,
  ElDialog
} from "element-plus";
import { useRouter } from "vue-router";

defineOptions({
  name: "Upgrade"
});

const router = useRouter();

// 升级前先备份数据库
function goBackup() {
  router.push("/database-backup");
}

// 当前版本信息
const currentVersion = ref<VersionInfo | null>(null);
// 更新检查结果
const updateInfo = ref<UpdateCheckResult | null>(null);
// 历史版本列表
const releases = ref<ReleaseInfo[]>([]);
// 备份列表
const backups = ref<BackupInfo[]>([]);

// 加载状态
const loadingVersion = ref(false);
const loadingCheck = ref(false);
const loadingReleases = ref(false);
const loadingBackups = ref(false);
const upgrading = ref(false);
const rollingBack = ref(false);

// 升级步骤
const upgradeSteps = ref<UpgradeStep[]>([]);
const showUpgradeProgress = ref(false);
const upgradeStatus = ref<UpgradeStatus | null>(null);
const pollingInterval = ref<ReturnType<typeof setInterval> | null>(null);

// 数据库结构检查警告
const structureWarning = ref<{
  show: boolean;
  canAutoFix: boolean;
  autoFixed: boolean;
  message: string;
  manualActions: string[];
}>({
  show: false,
  canAutoFix: true,
  autoFixed: false,
  message: "",
  manualActions: []
});

// PHP 环境检测失败弹窗（后台升级仅检测，不能自动修复 PHP；引导用户用 upgrade.sh）
const phpEnvError = ref<{
  visible: boolean;
  details: PhpEnvironmentErrorDetails | null;
}>({
  visible: false,
  details: null
});

// 步骤名称映射
const stepNames: Record<string, string> = {
  fetch_release: "获取版本信息",
  check_version: "检查版本",
  check_sequential: "检查升级顺序",
  backup: "创建备份",
  maintenance_on: "进入维护模式",
  download: "下载升级包",
  extract: "解压升级包",
  apply: "应用升级",
  composer_install: "安装依赖",
  migrate: "运行数据库迁移",
  seed: "初始化数据",
  structure_check: "数据库结构校验",
  clear_cache: "清理缓存",
  update_version: "更新版本号",
  cleanup: "清理临时文件",
  maintenance_off: "退出维护模式"
};

// 计算当前升级进度
const upgradeProgress = computed(() => {
  // 优先使用服务器返回的进度
  if (upgradeStatus.value?.progress !== undefined) {
    return upgradeStatus.value.progress;
  }
  if (!upgradeSteps.value.length) return 0;
  const completed = upgradeSteps.value.filter(
    s => s.status === "completed"
  ).length;
  return Math.round((completed / upgradeSteps.value.length) * 100);
});

// 比较两个语义化版本
// 返回: 1 if v1 > v2, 0 if v1 == v2, -1 if v1 < v2
const compareVersions = (v1: string, v2: string): number => {
  // 移除 v 前缀
  const clean1 = v1.replace(/^v/i, "");
  const clean2 = v2.replace(/^v/i, "");

  // 分离版本号和预发布标识
  const [version1, pre1] = clean1.split("-");
  const [version2, pre2] = clean2.split("-");

  // 比较主版本号
  const parts1 = version1.split(".").map(Number);
  const parts2 = version2.split(".").map(Number);

  for (let i = 0; i < 3; i++) {
    const p1 = parts1[i] || 0;
    const p2 = parts2[i] || 0;
    if (p1 > p2) return 1;
    if (p1 < p2) return -1;
  }

  // 版本号相同时比较预发布标识
  // 没有预发布标识的版本 > 有预发布标识的版本
  if (!pre1 && pre2) return 1;
  if (pre1 && !pre2) return -1;
  if (pre1 && pre2) return pre1.localeCompare(pre2);

  return 0;
};

// 检查目标版本是否比当前版本新
const isNewerVersion = (target: string, current?: string): boolean => {
  if (!current) return false;
  return compareVersions(target, current) > 0;
};

// 格式化文件大小
const formatBytes = (bytes: number): string => {
  const units = ["B", "KB", "MB", "GB"];
  let index = 0;
  let size = bytes;
  while (size >= 1024 && index < units.length - 1) {
    size /= 1024;
    index++;
  }
  return `${size.toFixed(2)} ${units[index]}`;
};

// 格式化日期
const formatDate = (dateStr: string): string => {
  if (!dateStr) return "";
  const date = new Date(dateStr);
  return date.toLocaleString("zh-CN");
};

// 加载当前版本信息
const loadVersion = async () => {
  loadingVersion.value = true;
  try {
    const { data } = await getVersion();
    currentVersion.value = data;
  } finally {
    loadingVersion.value = false;
  }
};

// 检查更新
const handleCheckUpdate = async () => {
  loadingCheck.value = true;
  try {
    const { data } = await checkUpdate();
    updateInfo.value = data;
    if (data.has_update) {
      message("发现新版本: " + data.latest_version, { type: "success" });
    } else {
      message("当前已是最新版本", { type: "info" });
    }
  } finally {
    loadingCheck.value = false;
  }
};

// 加载历史版本
const loadReleases = async () => {
  loadingReleases.value = true;
  try {
    const { data } = await getReleases();
    releases.value = data.releases || [];
  } finally {
    loadingReleases.value = false;
  }
};

// 加载备份列表
const loadBackups = async () => {
  loadingBackups.value = true;
  try {
    const { data } = await getBackups();
    backups.value = data.backups || [];
  } finally {
    loadingBackups.value = false;
  }
};

// 停止轮询
const stopPolling = () => {
  if (pollingInterval.value) {
    clearInterval(pollingInterval.value);
    pollingInterval.value = null;
  }
};

// 轮询计数器（用于超时检测）
const pollCount = ref(0);
const maxPollCount = 300; // 最多轮询 300 次（10分钟，每2秒一次）

// 轮询升级状态
const pollUpgradeStatus = async () => {
  try {
    pollCount.value++;
    const { data } = await getUpgradeStatus();
    console.log("[Upgrade] 轮询状态:", data);

    upgradeStatus.value = data;
    upgradeSteps.value = data.steps || [];

    if (data.status === "completed") {
      stopPolling();
      upgrading.value = false;
      message(`升级成功！${data.from_version} -> ${data.to_version}`, {
        type: "success"
      });
      // 检查数据库结构警告
      if (data.structure_check?.has_diff && !data.structure_check?.auto_fixed) {
        const sc = data.structure_check;
        const summary = sc.summary;
        const items: string[] = [];

        if (summary.missing_tables?.length)
          items.push(`缺失表: ${summary.missing_tables.join(", ")}`);
        if (summary.extra_tables?.length)
          items.push(`多余表: ${summary.extra_tables.join(", ")}`);
        if (summary.missing_columns?.length)
          items.push(`缺失列: ${summary.missing_columns.join(", ")}`);
        if (summary.extra_columns?.length)
          items.push(`多余列: ${summary.extra_columns.join(", ")}`);
        if (summary.modified_columns?.length)
          items.push(`类型不匹配: ${summary.modified_columns.join(", ")}`);
        if (summary.missing_indexes?.length)
          items.push(`缺失索引: ${summary.missing_indexes.join(", ")}`);
        if (summary.extra_indexes?.length)
          items.push(`多余索引: ${summary.extra_indexes.join(", ")}`);
        if (summary.missing_foreign_keys?.length)
          items.push(`缺失外键: ${summary.missing_foreign_keys.join(", ")}`);
        if (summary.extra_foreign_keys?.length)
          items.push(`多余外键: ${summary.extra_foreign_keys.join(", ")}`);

        structureWarning.value = {
          show: true,
          canAutoFix: sc.summary?.can_auto_fix ?? false,
          autoFixed: sc.auto_fixed,
          message: items.join("\n"),
          manualActions: summary.manual_actions || []
        };
      } else {
        structureWarning.value.show = false;
      }
      // 刷新版本信息
      await loadVersion();
      await loadBackups();
      // 清除更新提示
      updateInfo.value = null;
    } else if (data.status === "failed") {
      stopPolling();
      upgrading.value = false;
      const errorMsg = data.error || "未知错误（请查看服务器日志）";
      console.error("[Upgrade] 升级失败:", errorMsg);

      // PHP 环境检测失败 → 弹窗展示详细缺失项 + 引导用 upgrade.sh
      const details = data.error_details as
        | PhpEnvironmentErrorDetails
        | undefined;
      if (details?.type === "php_environment") {
        phpEnvError.value.details = details;
        phpEnvError.value.visible = true;
      } else {
        message("升级失败: " + errorMsg, { type: "error" });
      }
    } else if (data.status === "idle") {
      // 状态文件不存在，可能进程启动失败或还未创建
      if (pollCount.value > 5) {
        // 超过5次仍为 idle，可能进程启动失败
        stopPolling();
        upgrading.value = false;
        console.error("[Upgrade] 进程可能启动失败，状态一直为 idle");
        message("升级进程启动失败，请查看服务器日志", { type: "error" });
      }
    } else if (pollCount.value >= maxPollCount) {
      // 超时
      stopPolling();
      upgrading.value = false;
      console.error("[Upgrade] 轮询超时");
      message("升级超时，请查看服务器状态", { type: "warning" });
    }
  } catch (err) {
    console.error("[Upgrade] 轮询失败:", err);
    // 轮询失败时继续尝试，但如果连续失败太多次则停止
    if (pollCount.value > 10) {
      stopPolling();
      upgrading.value = false;
      message("无法获取升级状态，请检查网络连接", { type: "error" });
    }
  }
};

// 启动轮询
const startPolling = () => {
  stopPolling();
  pollCount.value = 0; // 重置计数器
  pollingInterval.value = setInterval(pollUpgradeStatus, 2000);
  // 立即执行一次
  pollUpgradeStatus();
};

// 执行升级
const handleUpgrade = async (version: string = "latest") => {
  upgrading.value = true;
  showUpgradeProgress.value = true;
  upgradeSteps.value = [];
  upgradeStatus.value = null;

  try {
    const { data } = await executeUpgrade(version);

    if (data.started) {
      message("升级任务已启动", { type: "info" });
      // 启动轮询获取升级状态
      startPolling();
    } else {
      upgrading.value = false;
      message("启动升级任务失败", { type: "error" });
    }
  } catch {
    upgrading.value = false;
    message("升级请求失败", { type: "error" });
  }
};

// 执行回滚
const handleRollback = async (backupId: string) => {
  rollingBack.value = true;
  try {
    const { data } = await executeRollback(backupId);
    if (data.success) {
      message(`回滚成功！已恢复到版本 ${data.restored_version}`, {
        type: "success"
      });
      await loadVersion();
    }
  } catch {
    message("回滚失败", { type: "error" });
  } finally {
    rollingBack.value = false;
  }
};

// 删除备份
const handleDeleteBackup = async (backupId: string) => {
  try {
    await deleteBackup(backupId);
    message("备份已删除", { type: "success" });
    await loadBackups();
  } catch {
    message("删除失败", { type: "error" });
  }
};

// 关闭升级进度
const closeUpgradeProgress = () => {
  stopPolling();
  showUpgradeProgress.value = false;
  upgradeSteps.value = [];
  upgradeStatus.value = null;
};

// 通道切换状态
const changingChannel = ref(false);

// 切换通道
const handleChangeChannel = async (newChannel: "main" | "dev") => {
  if (!currentVersion.value) return;

  changingChannel.value = true;
  try {
    await setChannel(newChannel);
    message(`已切换到${newChannel === "main" ? "稳定版" : "开发版"}通道`, {
      type: "success"
    });
    // 重新加载版本信息和检查更新
    await loadVersion();
    updateInfo.value = null; // 清除旧的更新信息
  } catch {
    message("切换通道失败", { type: "error" });
    // 恢复原来的值
    await loadVersion();
  } finally {
    changingChannel.value = false;
  }
};

onMounted(() => {
  loadVersion();
  loadBackups();
});

onUnmounted(() => {
  stopPolling();
});
</script>

<template>
  <div class="main p-4">
    <!-- 升级前备份提示（默认不备份数据库） -->
    <el-alert
      type="warning"
      :closable="false"
      show-icon
      class="mb-4"
      style="margin-bottom: 16px"
    >
      <template #title>
        <strong>建议升级前先备份数据库</strong>
      </template>
      <template #default>
        升级流程默认<strong>不备份数据库</strong>，仅备份代码与前端。如升级失败需要还原数据，需要你升级前手工备份。
        <el-button type="primary" link @click="goBackup">立即备份</el-button>
      </template>
    </el-alert>

    <!-- 当前版本信息 -->
    <el-card class="mb-4">
      <template #header>
        <div class="flex justify-between items-center">
          <span class="text-lg font-bold">当前版本</span>
          <el-button
            type="primary"
            :loading="loadingCheck"
            @click="handleCheckUpdate"
          >
            检查更新
          </el-button>
        </div>
      </template>
      <div v-if="currentVersion" class="version-info">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <div class="text-gray-500 text-sm">版本号</div>
            <div class="text-lg font-bold">
              {{ currentVersion.version }}
              <el-tag
                v-if="currentVersion.channel === 'dev'"
                type="warning"
                size="small"
                class="ml-2"
              >
                开发版
              </el-tag>
            </div>
          </div>
          <div>
            <div class="text-gray-500 text-sm">应用名称</div>
            <div class="text-lg">{{ currentVersion.name }}</div>
          </div>
          <div v-if="currentVersion.build_time">
            <div class="text-gray-500 text-sm">构建时间</div>
            <div class="text-lg">
              {{ formatDate(currentVersion.build_time) }}
            </div>
          </div>
          <div>
            <div class="text-gray-500 text-sm mb-1">发布通道</div>
            <el-select
              :model-value="currentVersion.channel"
              size="small"
              :loading="changingChannel"
              @change="handleChangeChannel"
            >
              <el-option label="稳定版 (main)" value="main" />
              <el-option label="开发版 (dev)" value="dev" />
            </el-select>
          </div>
        </div>
      </div>
      <div v-else class="text-gray-400">加载中...</div>
    </el-card>

    <!-- 更新信息 -->
    <el-card v-if="updateInfo?.has_update" class="mb-4">
      <template #header>
        <div class="flex justify-between items-center">
          <span class="text-lg font-bold text-green-600">发现新版本</span>
          <el-button
            type="success"
            :loading="upgrading"
            @click="handleUpgrade(updateInfo.latest_version)"
          >
            立即升级
          </el-button>
        </div>
      </template>
      <div class="update-info">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
          <div>
            <div class="text-gray-500 text-sm">最新版本</div>
            <div class="text-lg font-bold text-green-600">
              {{ updateInfo.latest_version }}
            </div>
          </div>
          <div v-if="updateInfo.release_date">
            <div class="text-gray-500 text-sm">发布时间</div>
            <div class="text-lg">{{ formatDate(updateInfo.release_date) }}</div>
          </div>
          <div v-if="updateInfo.package_size">
            <div class="text-gray-500 text-sm">升级包大小</div>
            <div class="text-lg">{{ updateInfo.package_size }}</div>
          </div>
        </div>
        <div v-if="updateInfo.changelog">
          <div class="text-gray-500 text-sm mb-2">更新日志</div>
          <div
            class="changelog bg-gray-50 p-4 rounded whitespace-pre-wrap text-sm"
          >
            {{ updateInfo.changelog }}
          </div>
        </div>
      </div>
    </el-card>

    <!-- 升级进度 -->
    <el-card v-if="showUpgradeProgress" class="mb-4">
      <template #header>
        <div class="flex justify-between items-center">
          <span class="text-lg font-bold">升级进度</span>
          <el-button
            v-if="!upgrading"
            type="text"
            @click="closeUpgradeProgress"
          >
            关闭
          </el-button>
        </div>
      </template>
      <div class="upgrade-progress">
        <el-progress
          :percentage="upgradeProgress"
          :status="
            upgrading ? '' : upgradeProgress === 100 ? 'success' : 'exception'
          "
          class="mb-4"
        />
        <div
          v-if="upgradeStatus?.current_step && upgrading"
          class="text-blue-500 text-sm mb-4"
        >
          正在执行：{{
            stepNames[upgradeStatus.current_step] || upgradeStatus.current_step
          }}
        </div>
        <div class="steps">
          <div
            v-for="step in upgradeSteps"
            :key="step.step"
            class="step flex items-center gap-2 py-2"
          >
            <span v-if="step.status === 'completed'" class="text-green-500"
              >✓</span
            >
            <span v-else-if="step.status === 'failed'" class="text-red-500"
              >✗</span
            >
            <span v-else-if="step.status === 'running'" class="text-blue-500"
              >○</span
            >
            <span v-else class="text-gray-400">○</span>
            <span :class="{ 'text-red-500': step.status === 'failed' }">
              {{ stepNames[step.step] || step.step }}
            </span>
            <span v-if="step.error" class="text-red-500 text-sm">
              ({{ step.error }})
            </span>
          </div>
        </div>
      </div>
    </el-card>

    <!-- 数据库结构警告 -->
    <el-card
      v-if="structureWarning.show"
      class="mb-4"
      :body-style="{
        backgroundColor: structureWarning.canAutoFix ? '#fef9e7' : '#fdedec'
      }"
    >
      <template #header>
        <div class="flex justify-between items-center">
          <span
            class="font-bold"
            :class="
              structureWarning.canAutoFix ? 'text-yellow-700' : 'text-red-600'
            "
          >
            {{
              structureWarning.canAutoFix
                ? "数据库结构存在差异（可自动修复）"
                : "数据库结构存在差异（需手动处理）"
            }}
          </span>
          <el-button type="text" @click="structureWarning.show = false">
            关闭
          </el-button>
        </div>
      </template>
      <div class="structure-warning-content">
        <pre class="text-sm whitespace-pre-wrap mb-2">{{
          structureWarning.message
        }}</pre>
        <div
          v-if="structureWarning.manualActions.length"
          class="manual-actions mt-2"
        >
          <div class="font-bold text-sm mb-1">需手动执行的操作：</div>
          <ul class="list-disc pl-4 text-sm">
            <li
              v-for="(action, idx) in structureWarning.manualActions"
              :key="idx"
            >
              {{ action }}
            </li>
          </ul>
        </div>
        <div class="mt-3 text-sm text-gray-600 space-y-1">
          <div>
            <code class="bg-gray-100 px-2 py-1 rounded"
              >php artisan db:structure --check</code
            >
            查看详细差异
          </div>
          <div>
            <code class="bg-gray-100 px-2 py-1 rounded"
              >php artisan db:structure --fix</code
            >
            自动修复
          </div>
        </div>
      </div>
    </el-card>

    <!-- 历史版本 -->
    <el-card class="mb-4">
      <template #header>
        <div class="flex justify-between items-center">
          <span class="text-lg font-bold">历史版本</span>
          <el-button :loading="loadingReleases" @click="loadReleases">
            加载历史版本
          </el-button>
        </div>
      </template>
      <div v-if="releases.length" class="releases">
        <div
          v-for="release in releases"
          :key="release.tag_name"
          class="release py-3 border-b last:border-b-0"
        >
          <div class="flex justify-between items-start">
            <div>
              <div class="flex items-center gap-2">
                <span class="font-bold">{{ release.tag_name }}</span>
                <el-tag v-if="release.prerelease" type="warning" size="small">
                  预发布
                </el-tag>
                <el-tag
                  v-if="currentVersion?.version === release.version"
                  type="success"
                  size="small"
                >
                  当前版本
                </el-tag>
              </div>
              <div class="text-gray-500 text-sm mt-1">
                {{ formatDate(release.published_at) }}
              </div>
            </div>
            <el-tooltip
              v-if="isNewerVersion(release.version, currentVersion?.version)"
              content="升级到此版本"
              placement="top"
            >
              <el-button
                type="primary"
                size="small"
                :loading="upgrading"
                @click="handleUpgrade(release.version)"
              >
                升级
              </el-button>
            </el-tooltip>
          </div>
          <div
            v-if="release.body"
            class="text-sm text-gray-600 mt-2 whitespace-pre-wrap"
          >
            {{ release.body }}
          </div>
        </div>
      </div>
      <el-empty v-else description="点击按钮加载历史版本" />
    </el-card>

    <!-- 备份管理 -->
    <el-card>
      <template #header>
        <div class="flex justify-between items-center">
          <span class="text-lg font-bold">备份管理</span>
          <el-button :loading="loadingBackups" @click="loadBackups">
            刷新
          </el-button>
        </div>
      </template>
      <div v-if="backups.length" class="backups">
        <div
          v-for="backup in backups"
          :key="backup.id"
          class="backup py-3 border-b last:border-b-0"
        >
          <div class="flex justify-between items-start">
            <div>
              <div class="font-bold">{{ backup.id }}</div>
              <div class="text-gray-500 text-sm mt-1">
                版本: {{ backup.version }} | 创建时间:
                {{ formatDate(backup.created_at) }} | 大小:
                {{ formatBytes(backup.size) }}
              </div>
              <div class="text-gray-400 text-xs mt-1">
                包含:
                <span v-if="backup.includes?.backend">后端代码</span>
                <span v-if="backup.includes?.database">, 数据库</span>
                <span v-if="backup.includes?.frontend">, 前端</span>
              </div>
            </div>
            <div class="flex gap-2">
              <el-popconfirm
                title="确定要恢复到此备份吗？当前数据将被覆盖"
                confirm-button-text="确定"
                cancel-button-text="取消"
                @confirm="handleRollback(backup.id)"
              >
                <template #reference>
                  <el-button type="warning" size="small" :loading="rollingBack">
                    回滚
                  </el-button>
                </template>
              </el-popconfirm>
              <el-popconfirm
                title="确定要删除此备份吗？"
                confirm-button-text="确定"
                cancel-button-text="取消"
                @confirm="handleDeleteBackup(backup.id)"
              >
                <template #reference>
                  <el-button type="danger" size="small"> 删除 </el-button>
                </template>
              </el-popconfirm>
            </div>
          </div>
        </div>
      </div>
      <el-empty v-else description="暂无备份" />
    </el-card>

    <!-- PHP 环境检测失败弹窗 -->
    <el-dialog
      v-model="phpEnvError.visible"
      title="PHP 环境不满足新版本要求"
      width="640px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <div v-if="phpEnvError.details" class="php-env-error">
        <el-alert
          type="error"
          :closable="false"
          :title="phpEnvError.details.message || '环境校验失败'"
          show-icon
        />

        <div class="env-detail-block">
          <div class="env-detail-row">
            <span class="env-label">当前 PHP：</span>
            <el-tag type="info">{{
              phpEnvError.details.current_php || "未知"
            }}</el-tag>
          </div>
          <div v-if="phpEnvError.details.required_php" class="env-detail-row">
            <span class="env-label">需要至少：</span>
            <el-tag type="danger">{{
              phpEnvError.details.required_php
            }}</el-tag>
          </div>
          <div
            v-if="phpEnvError.details.recommended_php"
            class="env-detail-row"
          >
            <span class="env-label">推荐版本：</span>
            <el-tag type="success">{{
              phpEnvError.details.recommended_php
            }}</el-tag>
          </div>
        </div>

        <div
          v-if="phpEnvError.details.missing_extensions?.length"
          class="env-detail-block"
        >
          <div class="env-label">缺失必需扩展：</div>
          <div class="env-tag-row">
            <el-tag
              v-for="ext in phpEnvError.details.missing_extensions"
              :key="ext"
              type="danger"
              effect="plain"
            >
              {{ ext }}
            </el-tag>
          </div>
        </div>

        <div
          v-if="phpEnvError.details.recommended_missing_extensions?.length"
          class="env-detail-block"
        >
          <div class="env-label">建议安装的扩展（不阻断）：</div>
          <div class="env-tag-row">
            <el-tag
              v-for="ext in phpEnvError.details.recommended_missing_extensions"
              :key="ext"
              type="warning"
              effect="plain"
            >
              {{ ext }}
            </el-tag>
          </div>
        </div>

        <div
          v-if="phpEnvError.details.disabled_functions?.length"
          class="env-detail-block"
        >
          <div class="env-label">必需函数被禁用（disable_functions）：</div>
          <div class="env-tag-row">
            <el-tag
              v-for="fn in phpEnvError.details.disabled_functions"
              :key="fn"
              type="danger"
              effect="plain"
            >
              {{ fn }}
            </el-tag>
          </div>
        </div>

        <el-alert
          type="info"
          :closable="false"
          show-icon
          class="env-remediation"
        >
          <template #title>如何修复</template>
          <div>
            <p>
              后台升级<strong>仅检测</strong>不修复 PHP 环境。请 SSH
              登录服务器，执行：
            </p>
            <pre class="env-cmd">bash upgrade.sh &lt;版本号&gt;</pre>
            <p>
              upgrade.sh
              包含同样的环境检测，会输出每一项的修复指引（宝塔面板路径 /
              包管理器命令）。 或者参考下面的手动方式：
            </p>
            <ul class="env-remediation-list">
              <li v-if="phpEnvError.details.required_php">
                <strong>PHP 版本</strong>：宝塔 → 软件商店 → 安装 PHP
                {{
                  phpEnvError.details.recommended_php ||
                  phpEnvError.details.required_php
                }}， 再到网站设置切换 PHP 版本
              </li>
              <li v-if="phpEnvError.details.missing_extensions?.length">
                <strong>扩展</strong>：宝塔 → PHP 管理 → 安装扩展
              </li>
              <li v-if="phpEnvError.details.disabled_functions?.length">
                <strong>函数禁用</strong>：编辑对应 PHP 版本的
                <code>php.ini</code>，从
                <code>disable_functions</code>
                删除上述函数后重启 PHP-FPM
              </li>
            </ul>
          </div>
        </el-alert>
      </div>

      <template #footer>
        <el-button @click="phpEnvError.visible = false">我知道了</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.changelog {
  max-height: 200px;
  overflow-y: auto;
}

.structure-warning-content pre {
  padding: 8px 12px;
  margin: 0;
  background: rgb(0 0 0 / 3%);
  border-radius: 4px;
}

.structure-warning-content code {
  font-family: "SF Mono", Monaco, "Courier New", monospace;
}

.php-env-error {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.env-detail-block {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.env-detail-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.env-label {
  font-weight: 500;
  color: var(--el-text-color-regular);
}

.env-tag-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.env-remediation {
  margin-top: 4px;
}

.env-remediation-list {
  padding-left: 20px;
  margin: 8px 0 0;
  line-height: 1.8;
}

.env-cmd {
  padding: 8px 12px;
  margin: 8px 0;
  font-family: "SF Mono", Monaco, "Courier New", monospace;
  user-select: all;
  background: rgb(0 0 0 / 5%);
  border-radius: 4px;
}
</style>
