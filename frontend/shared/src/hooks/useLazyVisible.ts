import { onMounted, onBeforeUnmount, watch, type Ref } from "vue";

/**
 * useLazyVisible — 统一的"哨兵元素进入视口才触发一次加载 + 不支持兜底直接加载 + 卸载清理"样板。
 *
 * 把 Dashboard 等页面各自手写的同一套"图表区进入视口才加载次批数据"逻辑抽成共享 composable，
 * 运行行为与原手写样板逐字等价：
 * - 环境不支持 IntersectionObserver（`typeof IntersectionObserver === "undefined"`）时直接 onVisible()，保证降级可用。
 * - 进入视口（entries.some(entry => entry.isIntersecting)）时调 onVisible()；once（默认 true）则触发后 disconnect。
 * - onMounted / watch 哨兵就绪后注册 observer 并 observe(sentinel.value)；onBeforeUnmount disconnect 清理。
 * - 内部 once 守卫保证只触发一次（等价于原样板的 chartsRequested 守卫）。
 *
 * 时序说明：原样板在 onMounted 内 await 首批数据 → loading=false → nextTick 后才 setupObserver，
 * 此时哨兵元素（位于 v-else 分支）方才挂载。本 composable 用 watch(sentinel, { flush: "post" })
 * 等哨兵 ref 变为非空（无论首批 await 耗时多久）后再 setup observer，挂载时若已就绪则立即 setup，
 * 保持"哨兵进入视口才加载"的原始懒加载语义，绝不在哨兵渲染前提前 eager 加载。
 * 原样板 setupObserver 内对 `!el` 的兜底分支在 nextTick 之后实际从不触发（哨兵此时必在 DOM），
 * 此处由 watch 等真实元素出现来等价覆盖，故不另设定时兜底（避免数据加载期误判 eager 触发）。
 *
 * 注意：内部使用 onMounted/onBeforeUnmount/watch，必须在组件 setup 同步调用；
 * onVisible 业务回调（各自的 fetch 接口）由调用方注入，不进 composable。
 */
export interface UseLazyVisibleOptions {
  /** IntersectionObserver threshold，原样板未传则保持不传。 */
  threshold?: number;
  /** IntersectionObserver rootMargin。默认 "200px 0px"（提前 200px 预加载）。 */
  rootMargin?: string;
  /** true 时触发后 disconnect，仅触发一次。默认 true。 */
  once?: boolean;
}

export function useLazyVisible(
  sentinel: Ref<HTMLElement | null | undefined>,
  onVisible: () => void,
  options: UseLazyVisibleOptions = {}
): void {
  const { threshold, rootMargin = "200px 0px", once = true } = options;

  let observer: IntersectionObserver | null = null;
  // 防止 observer 多次触发重复发起请求（等价于原样板的 chartsRequested 守卫）
  let triggered = false;

  const disconnectObserver = () => {
    if (observer) {
      observer.disconnect();
      observer = null;
    }
  };

  // 触发加载（仅首次有效）：observer 命中或降级调用
  const trigger = () => {
    if (once && triggered) return;
    triggered = true;
    disconnectObserver();
    onVisible();
  };

  // 哨兵已挂载，对其建立 IntersectionObserver；环境不支持则降级直接加载
  const observeElement = (el: HTMLElement) => {
    if (once && triggered) return;

    // 环境不支持 IntersectionObserver 时直接加载，保证降级可用
    if (typeof IntersectionObserver === "undefined") {
      trigger();
      return;
    }

    const observerOptions: IntersectionObserverInit = { rootMargin };
    if (threshold !== undefined) observerOptions.threshold = threshold;

    observer = new IntersectionObserver(entries => {
      if (entries.some(entry => entry.isIntersecting)) {
        trigger();
      }
    }, observerOptions);
    observer.observe(el);
  };

  // 哨兵 ref 一旦变为非空即 setup（覆盖首批 await + loading=false + nextTick 后才挂载的时序）
  const stopWatch = watch(
    sentinel,
    el => {
      if (el) {
        stopWatch();
        observeElement(el);
      }
    },
    { flush: "post" }
  );

  onMounted(() => {
    // 若挂载时哨兵已就绪，立即观察（首屏无 await/无 v-if 门控的场景）
    if (sentinel.value) {
      stopWatch();
      observeElement(sentinel.value);
    }
    // 否则交由上面的 watch 在哨兵真正挂载后 setup
  });

  // 组件卸载前停止 watch 并断开 observer
  onBeforeUnmount(() => {
    stopWatch();
    disconnectObserver();
  });
}
