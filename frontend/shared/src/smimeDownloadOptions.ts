export type SmimeDownloadType = "pem" | "pfx";

const smimeDownloadOptions: ReadonlyArray<{
  label: string;
  value: SmimeDownloadType;
}> = [
  { label: "PEM", value: "pem" },
  { label: "PFX", value: "pfx" }
];

export function getSmimeDownloadOptions() {
  return smimeDownloadOptions.map(option => ({ ...option }));
}
