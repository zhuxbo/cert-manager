<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import {
  getInstalledPlugins,
  checkPluginUpdates,
  installPlugin,
  installPluginFromFile,
  updatePlugin,
  uninstallPlugin,
  getPluginOperations,
  failStalePluginOperation,
  type PluginInfo,
  type PluginOperation,
  type PluginUpdateInfo
} from "@/api/plugin";
import { message } from "@shared/utils";
import {
  ElButton,
  ElCard,
  ElTag,
  ElDialog,
  ElInput,
  ElRadioGroup,
  ElRadioButton,
  ElUpload,
  ElPopconfirm,
  ElEmpty
} from "element-plus";

defineOptions({
  name: "Plugin"
});

// 插件列表
const plugins = ref<PluginInfo[]>([]);
const updates = ref<Record<string, PluginUpdateInfo>>({});

// 加载状态
const loading = ref(false);
const checkingUpdates = ref(false);
const operating = ref<string | null>(null);
const operations = ref<PluginOperation[]>([]);
const notifiedTerminals = new Set<string>();
let pollTimer: ReturnType<typeof setInterval> | null = null;

// 安装弹窗
const installDialogVisible = ref(false);
const installMode = ref<"remote" | "upload">("remote");
const installForm = ref({ name: "", release_url: "" });
const installLoading = ref(false);
const uploadFile = ref<File | null>(null);

// 卸载弹窗
const uninstallDialogVisible = ref(false);
const uninstallTarget = ref<string>("");
const removeData = ref(false);
const uninstallLoading = ref(false);

const activeOperationByPlugin = computed<Record<string, PluginOperation>>(
  () => {
    const map: Record<string, PluginOperation> = {};
    for (const op of operations.value) {
      if (op.status === "queued" || op.status === "running") {
        map[op.plugin_name] = op;
      }
    }
    return map;
  }
);

const activeOperations = computed(() =>
  operations.value.filter(
    op => op.status === "queued" || op.status === "running"
  )
);

const getActiveOperation = (name: string): PluginOperation | null => {
  return activeOperationByPlugin.value[name] || null;
};

const hasActiveOperation = (name: string): boolean => {
  return !!getActiveOperation(name);
};

const operationLabel = (op: PluginOperation): string => {
  if (op.type === "update") return "更新";
  return "安装";
};

const operationStatusType = (status: PluginOperation["status"]) => {
  if (status === "succeeded") return "success";
  if (status === "failed") return "danger";
  if (status === "running") return "warning";
  return "info";
};

// 加载已安装插件
const loadPlugins = async () => {
  loading.value = true;
  try {
    const { data } = await getInstalledPlugins();
    plugins.value = data.plugins || [];
  } finally {
    loading.value = false;
  }
};

const loadOperations = async (notifyTerminal = false) => {
  const { data } = await getPluginOperations();
  const list = data.operations || [];
  operations.value = list;
  for (const op of list) {
    if (op.status !== "succeeded" && op.status !== "failed") continue;
    if (!notifyTerminal) {
      notifiedTerminals.add(op.uuid);
      continue;
    }
    if (notifiedTerminals.has(op.uuid)) continue;
    notifiedTerminals.add(op.uuid);
    if (op.status === "succeeded") {
      message(op.result?.message || op.message || "插件任务已完成", {
        type: "success"
      });
      await loadPlugins();
      if (op.type === "update" && updates.value[op.plugin_name]) {
        updates.value[op.plugin_name].has_update = false;
        updates.value[op.plugin_name].latest_version =
          op.result?.version || op.version || null;
      }
    } else {
      message(op.error || op.message || "插件任务失败", { type: "error" });
    }
  }
  if (activeOperations.value.length === 0) {
    stopPolling();
  }
};

const startPolling = () => {
  if (pollTimer) return;
  pollTimer = setInterval(() => {
    loadOperations(true).catch(() => {
      // 状态轮询失败时不打断页面操作；下一轮继续尝试。
    });
  }, 2000);
};

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
};

// 检查更新
const handleCheckUpdates = async () => {
  checkingUpdates.value = true;
  try {
    const { data } = await checkPluginUpdates();
    const updateList = data.updates || [];
    const map: Record<string, PluginUpdateInfo> = {};
    let hasUpdate = false;
    for (const u of updateList) {
      map[u.name] = u;
      if (u.has_update) hasUpdate = true;
    }
    updates.value = map;
    if (hasUpdate) {
      message("发现可用更新", { type: "success" });
    } else {
      message("所有插件已是最新版本", { type: "info" });
    }
  } finally {
    checkingUpdates.value = false;
  }
};

// 获取插件更新信息
const getUpdate = (name: string): PluginUpdateInfo | null => {
  return updates.value[name] || null;
};

// 安装插件
const handleInstall = async () => {
  installLoading.value = true;
  try {
    if (installMode.value === "upload") {
      if (!uploadFile.value) {
        message("请选择 ZIP 文件", { type: "warning" });
        return;
      }
      const { data } = await installPluginFromFile(uploadFile.value);
      operations.value.unshift(data.operation);
      message(data.operation.message || "插件安装任务已创建", {
        type: "success"
      });
    } else {
      if (!installForm.value.name) {
        message("请输入插件名称", { type: "warning" });
        return;
      }
      if (hasActiveOperation(installForm.value.name)) {
        message("该插件已有安装或更新任务正在执行", { type: "warning" });
        return;
      }
      const { data } = await installPlugin({
        name: installForm.value.name,
        release_url: installForm.value.release_url || undefined
      });
      operations.value.unshift(data.operation);
      message(data.operation.message || "插件安装任务已创建", {
        type: "success"
      });
    }
    installDialogVisible.value = false;
    resetInstallForm();
    startPolling();
  } finally {
    installLoading.value = false;
  }
};

// 更新插件
const handleUpdate = async (name: string) => {
  if (hasActiveOperation(name)) {
    message("该插件已有安装或更新任务正在执行", { type: "warning" });
    return;
  }
  operating.value = name;
  try {
    const { data } = await updatePlugin(name);
    operations.value.unshift(data.operation);
    message(data.operation.message || "插件更新任务已创建", {
      type: "success"
    });
    startPolling();
  } finally {
    operating.value = null;
  }
};

// 打开卸载弹窗
const openUninstallDialog = (name: string) => {
  if (hasActiveOperation(name)) {
    message("该插件已有安装或更新任务正在执行，暂不能卸载", {
      type: "warning"
    });
    return;
  }
  uninstallTarget.value = name;
  removeData.value = false;
  uninstallDialogVisible.value = true;
};

// 执行卸载
const handleUninstall = async () => {
  uninstallLoading.value = true;
  try {
    const { data } = await uninstallPlugin(
      uninstallTarget.value,
      removeData.value
    );
    message(data.message, { type: "success" });
    uninstallDialogVisible.value = false;
    await loadPlugins();
  } finally {
    uninstallLoading.value = false;
  }
};

const handleFailStale = async (op: PluginOperation) => {
  const { data } = await failStalePluginOperation(op.uuid);
  const index = operations.value.findIndex(item => item.uuid === op.uuid);
  if (index >= 0) {
    operations.value.splice(index, 1, data.operation);
  }
  if (activeOperations.value.length === 0) {
    stopPolling();
  }
  message(data.operation.message || "插件任务已标记失败", { type: "warning" });
};

// 重置安装表单
const resetInstallForm = () => {
  installForm.value = { name: "", release_url: "" };
  uploadFile.value = null;
  installMode.value = "remote";
};

// 处理文件选择
const handleFileChange = (file: any) => {
  uploadFile.value = file.raw;
  return false; // 阻止自动上传
};

const handleFileRemove = () => {
  uploadFile.value = null;
};

onMounted(() => {
  loadPlugins();
  loadOperations(false).then(() => {
    if (activeOperations.value.length > 0) {
      startPolling();
    }
  });
});

onBeforeUnmount(() => {
  stopPolling();
});
</script>

<template>
  <div class="main p-4">
    <!-- 操作栏 -->
    <el-card class="mb-4">
      <div class="flex justify-between items-center">
        <span class="text-lg font-bold">插件管理</span>
        <div class="flex gap-2">
          <el-button :loading="checkingUpdates" @click="handleCheckUpdates">
            检查更新
          </el-button>
          <el-button type="primary" @click="installDialogVisible = true">
            安装插件
          </el-button>
        </div>
      </div>
    </el-card>

    <el-card v-if="operations.length" class="mb-4">
      <div class="space-y-3">
        <div
          v-for="op in operations"
          :key="op.uuid"
          class="flex items-center justify-between gap-4 border-b last:border-b-0 pb-3 last:pb-0"
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="font-medium">{{ op.plugin_name }}</span>
              <el-tag size="small">{{ operationLabel(op) }}</el-tag>
              <el-tag size="small" :type="operationStatusType(op.status)">
                {{ op.status }}
              </el-tag>
              <el-tag v-if="op.is_stale" size="small" type="danger">
                超时
              </el-tag>
            </div>
            <div class="text-sm text-gray-500 truncate">
              {{ op.message || op.error || op.stage }}
            </div>
            <div class="text-xs text-gray-400 mt-1">
              {{ op.updated_at || op.created_at }}
            </div>
          </div>
          <el-button
            v-if="
              (op.status === 'queued' || op.status === 'running') && op.is_stale
            "
            size="small"
            type="danger"
            plain
            @click="handleFailStale(op)"
          >
            标记失败
          </el-button>
        </div>
      </div>
    </el-card>

    <!-- 插件列表 -->
    <div v-if="plugins.length" class="grid gap-4">
      <el-card v-for="plugin in plugins" :key="plugin.name">
        <div class="flex justify-between items-start">
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-lg font-bold">{{ plugin.name }}</span>
              <el-tag size="small">v{{ plugin.version }}</el-tag>
              <el-tag
                v-if="getUpdate(plugin.name)?.has_update"
                type="success"
                size="small"
              >
                可更新到 v{{ getUpdate(plugin.name)?.latest_version }}
              </el-tag>
              <el-tag
                v-if="getUpdate(plugin.name)?.error"
                type="danger"
                size="small"
              >
                检查更新失败
              </el-tag>
              <el-tag
                v-if="getActiveOperation(plugin.name)"
                type="warning"
                size="small"
              >
                {{ getActiveOperation(plugin.name)?.message || "任务执行中" }}
              </el-tag>
            </div>
            <div v-if="plugin.description" class="text-gray-500 text-sm mb-2">
              {{ plugin.description }}
            </div>
            <div class="text-gray-400 text-xs space-x-4">
              <span v-if="plugin.provider"
                >Provider: {{ plugin.provider }}</span
              >
              <span v-if="plugin.release_url">
                更新地址: {{ plugin.release_url }}
              </span>
              <span v-else>更新地址: 主系统子目录</span>
            </div>
          </div>
          <div class="flex gap-2 ml-4">
            <el-button
              v-if="getUpdate(plugin.name)?.has_update"
              type="success"
              size="small"
              :loading="
                operating === plugin.name ||
                getActiveOperation(plugin.name)?.status === 'running'
              "
              :disabled="hasActiveOperation(plugin.name)"
              @click="handleUpdate(plugin.name)"
            >
              更新
            </el-button>
            <el-button
              type="danger"
              size="small"
              plain
              :disabled="hasActiveOperation(plugin.name)"
              @click="openUninstallDialog(plugin.name)"
            >
              卸载
            </el-button>
          </div>
        </div>
      </el-card>
    </div>
    <el-card v-else>
      <el-empty description="暂无已安装插件" />
    </el-card>

    <!-- 安装弹窗 -->
    <el-dialog
      v-model="installDialogVisible"
      title="安装插件"
      width="520px"
      @close="resetInstallForm"
    >
      <div class="mb-4">
        <el-radio-group v-model="installMode" class="mb-4">
          <el-radio-button value="remote">从服务器安装</el-radio-button>
          <el-radio-button value="upload">上传安装</el-radio-button>
        </el-radio-group>
      </div>

      <div v-if="installMode === 'remote'">
        <div class="mb-4">
          <label class="block text-sm font-medium mb-1">插件名称 *</label>
          <el-input v-model="installForm.name" placeholder="输入插件名称" />
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium mb-1">更新地址（可选）</label>
          <el-input
            v-model="installForm.release_url"
            placeholder="留空使用主系统子目录"
          />
          <div class="text-gray-400 text-xs mt-1">
            第三方插件需要填写完整地址，如：https://example.com/my-plugin
          </div>
        </div>
      </div>

      <div v-else>
        <el-upload
          drag
          :auto-upload="false"
          :limit="1"
          accept=".zip"
          :on-change="handleFileChange"
          :on-remove="handleFileRemove"
        >
          <div class="py-4">
            <p class="text-gray-500">将插件 ZIP 文件拖到此处，或点击选择文件</p>
            <p class="text-gray-400 text-xs mt-2">仅支持 .zip 格式</p>
          </div>
        </el-upload>
      </div>

      <template #footer>
        <el-button @click="installDialogVisible = false">取消</el-button>
        <el-button
          type="primary"
          :loading="installLoading"
          @click="handleInstall"
        >
          安装
        </el-button>
      </template>
    </el-dialog>

    <!-- 卸载确认弹窗 -->
    <el-dialog v-model="uninstallDialogVisible" title="卸载插件" width="420px">
      <p class="mb-4">
        确定要卸载插件 <strong>{{ uninstallTarget }}</strong> 吗？
      </p>
      <div class="space-y-2">
        <label
          class="flex items-center gap-2 cursor-pointer p-3 rounded border"
          :class="{ 'border-blue-400 bg-blue-50': !removeData }"
          @click="removeData = false"
        >
          <input type="radio" :checked="!removeData" class="accent-blue-500" />
          <div>
            <div class="font-medium">仅移除文件（推荐）</div>
            <div class="text-gray-400 text-xs">
              保留数据库表，重新安装时自动跳过已有表
            </div>
          </div>
        </label>
        <label
          class="flex items-center gap-2 cursor-pointer p-3 rounded border"
          :class="{ 'border-red-400 bg-red-50': removeData }"
          @click="removeData = true"
        >
          <input type="radio" :checked="removeData" class="accent-red-500" />
          <div>
            <div class="font-medium text-red-600">完全清除</div>
            <div class="text-gray-400 text-xs">
              删除文件和数据库表，数据不可恢复
            </div>
          </div>
        </label>
      </div>

      <template #footer>
        <el-button @click="uninstallDialogVisible = false">取消</el-button>
        <el-button
          type="danger"
          :loading="uninstallLoading"
          @click="handleUninstall"
        >
          确认卸载
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>
