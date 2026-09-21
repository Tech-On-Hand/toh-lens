# Lists the top-level windows owned by the spike's processes (and their WebView2 children).
Add-Type @"
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Text;
public class Win {
  public delegate bool EnumProc(IntPtr h, IntPtr l);
  [DllImport("user32.dll")] public static extern bool EnumWindows(EnumProc p, IntPtr l);
  [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
  [DllImport("user32.dll")] public static extern int GetWindowText(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll")] public static extern int GetClassName(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr h);
  public static List<string> List(HashSet<uint> pids) {
    var rows = new List<string>();
    EnumWindows((h, l) => {
      uint pid; GetWindowThreadProcessId(h, out pid);
      if (!pids.Contains(pid)) return true;
      var t = new StringBuilder(256); GetWindowText(h, t, 256);
      var c = new StringBuilder(256); GetClassName(h, c, 256);
      rows.Add(pid + " visible=" + IsWindowVisible(h) + " class=" + c + " title=\"" + t + "\"");
      return true;
    }, IntPtr.Zero);
    return rows;
  }
}
"@
$ids = New-Object 'System.Collections.Generic.HashSet[uint32]'
Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'screen-capture-spike.exe' -or $_.CommandLine -like '*com.techonhand.spike*' } | ForEach-Object { [void]$ids.Add([uint32]$_.ProcessId) }
[Win]::List($ids) | Where-Object { $_ -notmatch 'class=(Chrome_SystemMessageWindow|MSCTFIME UI|IME|GDI\+ Hook Window Class|Chrome_WidgetWin_0)' }
