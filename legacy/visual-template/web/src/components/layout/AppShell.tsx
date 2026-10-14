import { Outlet, useLocation } from "react-router-dom";
import { useState } from "react";
import { Sidebar } from "./Sidebar";
import { Topbar } from "./Topbar";

const TITLES: Array<{ match: RegExp; title: string }> = [
  { match: /^\/$/, title: "Dashboard" },
  { match: /^\/clients/, title: "Clients" },
  { match: /^\/campaigns/, title: "Campaigns" },
  { match: /^\/activities/, title: "Activities" },
  { match: /^\/personnel/, title: "Field Personnel" },
  { match: /^\/teams/, title: "Teams" },
  { match: /^\/locations/, title: "Locations" },
  { match: /^\/assignments/, title: "Assignments" },
  { match: /^\/reports/, title: "Reports" },
  { match: /^\/issues/, title: "Issues" },
  { match: /^\/audit-logs/, title: "Audit Logs" },
  { match: /^\/settings/, title: "Settings" },
];

export function AppShell() {
  const [collapsed, setCollapsed] = useState(false);
  const { pathname } = useLocation();
  const title = TITLES.find((t) => t.match.test(pathname))?.title ?? "Meta Intel";

  return (
    <div className="flex min-h-screen bg-slate-50 text-slate-800">
      <Sidebar collapsed={collapsed} onToggle={() => setCollapsed((v) => !v)} />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar title={title} />
        <main className="flex-1 overflow-y-auto p-4 md:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}