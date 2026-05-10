/**
 * Manager 元信息（前端编排）
 *
 * 前端启动期调 GET /api/meta 拉取：
 * - channels：4 类路由通道开关（admin/user/api/deploy）
 * - plugins：已装插件清单（name + version）
 * - version：主系统版本
 *
 * /api/meta 是匿名公开端点，不需要鉴权 / token / cookie；
 * 不走 shared http（避免 setupSharedModules 之前就被调用），
 * 直接用原生 fetch。
 */

export interface ManagerChannels {
  admin: boolean;
  user: boolean;
  api: boolean;
  deploy: boolean;
}

export interface ManagerPluginInfo {
  name: string;
  version?: string;
}

export interface ManagerMeta {
  channels: ManagerChannels;
  plugins: ManagerPluginInfo[];
  version: string;
}

let _cache: ManagerMeta | null = null;

/** 启动期 fetchMeta 超时（毫秒）。后端 / 反代挂起时不无限等待 → 立即按 channels=true 默认放行 */
const FETCH_META_TIMEOUT_MS = 5000;

/**
 * 拉取 /api/meta，缓存到模块内变量。
 * 失败（网络错 / 超时 / 后端老版本无此端点）→ 返回 null，调用方决定如何降级。
 *
 * 超时保护：5 秒 AbortController 中止；防止后端启动中 / 反代挂起导致前端启动期永久白屏。
 */
export async function fetchMeta(): Promise<ManagerMeta | null> {
  if (_cache) return _cache;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), FETCH_META_TIMEOUT_MS);

  try {
    const res = await fetch("/api/meta", {
      method: "GET",
      headers: { Accept: "application/json" },
      credentials: "same-origin",
      signal: controller.signal
    });
    if (!res.ok) return null;
    const json = await res.json();
    // 后端 ApiResponse 结构 { code, message, data: { channels, plugins, version } }
    const data = (json && (json.data ?? json)) as ManagerMeta | undefined;
    if (!data || !data.channels) return null;
    _cache = data;
    return _cache;
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

/**
 * 仅供测试 / HMR 调试使用：清除模块级 meta 缓存
 *
 * 生产路径不要调；fetchMeta 缓存命中即返回，是设计预期。
 */
export function clearMetaCache(): void {
  _cache = null;
}

/**
 * 同步取已缓存的 meta（fetchMeta 之后调用才有值）
 */
export function getCachedMeta(): ManagerMeta | null {
  return _cache;
}

/**
 * 在 #app 容器内渲染 channel 关闭提示页（不走 Vue / Router，避免依赖未初始化）
 *
 * @param channel 当前应用的 channel 名（admin / user）
 */
export function renderChannelDisabled(channel: "admin" | "user"): void {
  const label = channel === "admin" ? "管理后台" : "用户后台";
  const envKey = channel === "admin" ? "CHANNELS_ADMIN" : "CHANNELS_USER";
  const target = document.querySelector("#app");
  if (!target) return;

  target.innerHTML = `
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'PingFang SC','Microsoft YaHei',sans-serif;background:#f5f7fa;">
      <div style="text-align:center;padding:3rem 2rem;max-width:480px;background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
        <div style="font-size:48px;line-height:1;margin-bottom:1rem;">⚠️</div>
        <h1 style="font-size:1.5rem;margin:0 0 1rem;color:#303133;">${label}已禁用</h1>
        <p style="color:#606266;line-height:1.6;margin:0;">
          当前 SSL Manager 部署关闭了 <code style="background:#f4f4f5;padding:2px 6px;border-radius:3px;">${channel}</code> 通道。<br>
          如需启用，请联系运维人员在服务端 <code style="background:#f4f4f5;padding:2px 6px;border-radius:3px;">.env</code> 设置 <code style="background:#f4f4f5;padding:2px 6px;border-radius:3px;">${envKey}=true</code> 并重启服务。
        </p>
      </div>
    </div>
  `;
}
