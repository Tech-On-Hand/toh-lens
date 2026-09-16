/**
 * App-level shortcut/UI blocking for the kiosk webview. This is a deterrent
 * for casual bypass attempts by students, not a security boundary — it
 * cannot intercept Ctrl+Alt+Del or Task Manager (those require the real
 * Winlogon/GPO hardening described in provisioning/windows-kiosk-hardening.md,
 * which is applied at deployment time, not here).
 */
export function installKioskGuards(): () => void {
  const blockedKeydown = (event: KeyboardEvent) => {
    const key = event.key.toLowerCase();
    const ctrlOrCmd = event.ctrlKey || event.metaKey;

    const isBlocked =
      key === "f11" ||
      key === "f12" ||
      key === "f5" ||
      (event.altKey && key === "f4") ||
      (ctrlOrCmd && event.shiftKey && key === "i") ||
      (ctrlOrCmd && (key === "w" || key === "r" || key === "n" || key === "p" || key === "u"));

    if (isBlocked) {
      event.preventDefault();
      event.stopPropagation();
    }
  };

  const blockContextMenu = (event: MouseEvent) => {
    event.preventDefault();
  };

  window.addEventListener("keydown", blockedKeydown, { capture: true });
  window.addEventListener("contextmenu", blockContextMenu, { capture: true });

  return () => {
    window.removeEventListener("keydown", blockedKeydown, { capture: true });
    window.removeEventListener("contextmenu", blockContextMenu, { capture: true });
  };
}
