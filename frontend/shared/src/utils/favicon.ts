export const applyFavicon = (value?: string): void => {
  document.head
    .querySelectorAll<HTMLLinkElement>('link[rel~="icon"]')
    .forEach(link => link.remove());

  const link = document.createElement("link");
  link.rel = "icon";
  const href = value?.trim();
  if (href) {
    link.type = "image/x-icon";
    link.href = href;
  } else {
    // 明确使用空 data URL，避免浏览器自动回退请求不存在的 /favicon.ico。
    link.href = "data:,";
  }
  document.head.appendChild(link);
};
