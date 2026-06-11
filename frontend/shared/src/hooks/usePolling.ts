import {
  onMounted,
  onActivated,
  onDeactivated,
  onBeforeUnmount
} from "vue";

/**
 * usePolling — 统一的"定时刷新 + 切后台标签页跳过 + 切回前台立即刷新 + 卸载清理"轮询样板。
 *
 * 把列表页/详情页各自手写的同一套逻辑抽成共享 composable，运行行为与原手写样板逐字等价：
 * - 每次 tick：`if (document.hidden) return; if (shouldSkip?.()) return; callback();`
 * - 标签页重新可见（visibilitychange）：`if (!document.hidden && refreshOnVisible && !shouldSkip?.()) callback();`
 * - onMounted 注册 interval + visibilitychange 监听，onBeforeUnmount 清理。
 * - immediate=true 时 onMounted 先同步 callback() 一次（详情页用）。
 * - keepAlive=true 时额外在 onActivated 重新装载、onDeactivated 暂停（keep-alive 列表页用），
 *   startPolling/stopPolling 幂等，与原样板一致。
 *
 * 注意：内部使用 onMounted/onActivated/onDeactivated/onBeforeUnmount，必须在组件 setup 同步调用。
 */
export interface UsePollingOptions {
  /** 轮询间隔，毫秒。默认 3 分钟。 */
  interval?: number;
  /** 返回 true 时跳过本次刷新（tick 与 visibilitychange 都生效）。如列表页"已勾选则跳过"。 */
  shouldSkip?: () => boolean;
  /** true 时 onMounted 先立即 callback() 一次。默认 false（页面保留自己的初始加载语义）。 */
  immediate?: boolean;
  /** 标签页重新可见时是否立即刷新。默认 true。 */
  refreshOnVisible?: boolean;
  /**
   * true 时启用 keep-alive 生命周期：onActivated 重新装载轮询 + 监听、onDeactivated 暂停。
   * keep-alive 缓存下离开页面不触发卸载，需在 deactivated 暂停轮询，避免后台并发刷新。默认 false。
   */
  keepAlive?: boolean;
}

export function usePolling(
  callback: () => void,
  options: UsePollingOptions = {}
): void {
  const {
    interval = 3 * 60 * 1000,
    shouldSkip,
    immediate = false,
    refreshOnVisible = true,
    keepAlive = false
  } = options;

  type TimerRef = ReturnType<typeof setInterval>;
  let timer: TimerRef | null = null;

  // 启动轮询：幂等，重复调用不会产生多个定时器
  const startPolling = () => {
    if (timer !== null) return;
    timer = setInterval(() => {
      // 页面被切到后台标签页时跳过本次刷新，回到前台再恢复
      if (document.hidden) return;
      // 满足跳过条件时（如已勾选批量操作目标行）跳过本次自动刷新
      if (shouldSkip?.()) return;
      callback();
    }, interval);
  };

  const stopPolling = () => {
    if (timer !== null) {
      clearInterval(timer);
      timer = null;
    }
  };

  // 标签页重新可见时立即刷新一次（满足跳过条件则跳过），避免等待整个轮询周期
  const handleVisibilityChange = () => {
    if (document.hidden) return;
    if (refreshOnVisible !== true) return;
    if (shouldSkip?.()) return;
    callback();
  };

  onMounted(() => {
    if (immediate) callback();
    document.addEventListener("visibilitychange", handleVisibilityChange);
    startPolling();
  });

  if (keepAlive) {
    // keepAlive 缓存下离开页面不会触发卸载，需在 deactivated 暂停轮询，
    // 避免多个列表页同时在后台并发刷新
    onActivated(() => {
      document.addEventListener("visibilitychange", handleVisibilityChange);
      startPolling();
    });

    onDeactivated(() => {
      document.removeEventListener("visibilitychange", handleVisibilityChange);
      stopPolling();
    });
  }

  // 组件卸载前清理定时器与监听
  onBeforeUnmount(() => {
    document.removeEventListener("visibilitychange", handleVisibilityChange);
    stopPolling();
  });
}
