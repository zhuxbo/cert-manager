export interface DelegationCopyItem {
  zone: string;
  prefix: string;
  cname_to?: {
    host?: string | null;
    value?: string | null;
  } | null;
  target_fqdn?: string | null;
  proxy_domain?: string | null;
}

export function formatDelegationCopyText(item: DelegationCopyItem): string {
  const subdomain =
    item.cname_to?.host?.replace(`.${item.zone}`, "") || item.prefix;

  return `域名: ${item.zone}\n主机记录: ${subdomain}\n记录类型: CNAME\n记录值: ${item.target_fqdn || item.cname_to?.value || ""}\n代理域: ${item.proxy_domain || "-"}`;
}
