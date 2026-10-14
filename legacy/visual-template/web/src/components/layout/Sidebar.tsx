import { NavLink } from "react-router-dom";
import {
  LayoutDashboard, Users, Megaphone, ListChecks, UserCog, Users2,
  MapPin, CalendarCheck, BarChart3, FileWarning, ScrollText, Settings,
  ChevronLeft, ChevronRight,
} from "lucide-react";
import clsx from "clsx";
import { usePermissions } from "@/hooks/usePermissions";

const NAV = [
  { to: "/", label: "Dashboard", icon: LayoutDashboard, roles: "*" },
  { to: "/clients", label: "Clients", icon: Users, roles: "*" },
  { to: "/campaigns", label: "Campaigns", icon: Megaphone, roles: "*" },
  { to: "/activities", label: "Activities", icon: ListChecks, roles: "*" },
  { to: "/personnel", label: "Field Personnel", icon: UserCog, roles: "*" },
  { to: "/teams", label: "Teams", icon: Users2, roles: "*" },
  { to: "/locations", label: "Locations", icon: MapPin, roles: "*" },
  { to: "/assignments", label: "Assignments", icon: CalendarCheck, roles: "*" },
  { to: "/reports", label: "Reports", icon: BarChart3, roles: "*" },
  { to: "/issues", label: "Issues", icon: FileWarning, roles: "*" },
  { to: "/audit-logs", label: "Audit Logs", icon: ScrollText, roles: ["SUPER_ADMIN","ADMIN","MANAGEMENT"] },
  { to: "/settings", label: "Settings", icon: Settings, roles: ["SUPER_ADMIN","ADMIN"] },
] as const;

export function Sidebar({
  collapsed, onToggle,
}: { collapsed: boolean; onToggle: () => void }) {
  const { role } = usePermissions();

  return (
    <aside
      className={clsx(
        "flex h-screen flex-col border-r border-slate-200 bg-navy-900 text-slate-200 transition-all",
        collapsed ? "w-16" : "w-60"
      )}
    >
      <div className="flex items-center justify-between border-b border-navy-800 px-3 py-3">
        {!collapsed && (
          <div>
            <div className="text-sm font-semibold text-white">Meta Intel</div>
            <div className="text-[11px] text-slate-400">Field Operations</div>
          </div>
        )}
        <button onClick={onToggle} className="rounded p-1 hover:bg-navy-800" aria-label="Toggle sidebar">
          {collapsed ? <ChevronRight size={16} /> : <ChevronLeft size={16} />}
        </button>
      </div>

      <nav className="flex-1 overflow-y-auto py-2">
        {NAV.filter((item) => item.roles === "*" || (role && item.roles.includes(role))).map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === "/"}
            className={({ isActive }) =>
              clsx(
                "mx-2 my-0.5 flex items-center gap-3 rounded-md px-3 py-2 text-sm transition",
                isActive ? "bg-navy-800 text-white" : "text-slate-300 hover:bg-navy-800/70 hover:text-white"
              )
            }
          >
            <item.icon size={16} />
            {!collapsed && <span>{item.label}</span>}
          </NavLink>
        ))}
      </nav>
    </aside>
  );
}