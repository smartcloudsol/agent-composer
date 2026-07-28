import { __ } from "@wordpress/i18n";

const TEXT_DOMAIN = "smartcloud-agent-composer";

export function formatTimestamp(value: string): string {
  const normalized = value.trim();
  if (!normalized || /^-?0{3,4}-|^0000-00-00|^-0001/.test(normalized)) return __("N/A", TEXT_DOMAIN);
  const date = new Date(normalized.endsWith("Z") ? normalized : `${normalized.replace(" ", "T")}Z`);
  return Number.isNaN(date.getTime()) ? normalized : date.toLocaleString();
}

export function downloadJson(filename: string, value: unknown) {
  const url = URL.createObjectURL(new Blob([JSON.stringify(value, null, 2)], { type: "application/json" }));
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}
