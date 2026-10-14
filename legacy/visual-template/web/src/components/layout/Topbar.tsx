import { LogOut, Bell, UserCircle2, X } from "lucide-react";
import { useProfile } from "@/hooks/useProfile";
import { useScope } from "@/hooks/useScope";
import { supabase } from "@/lib/supabase";
import { useNavigate } from "react-router-dom";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { listClients } from "@/services/clients.service";
import { listCampaigns } from "@/services/campaigns.service";

export function Topbar({ title }: { title: string }) {
  const { data: profile } = useProfile();
  const [menuOpen, setMenuOpen] = useState(false);
  const navigate = useNavigate();
  const { scope, setScope, reset } = useScope();

  const clients = useQuery({
    queryKey: ["clients", "options"],
    queryFn: () => listClients({ pageSize: 500 }),
  });

  // Only fetch campaigns once a client is selected. If no client, we show all.
  const campaigns = useQuery({
    queryKey: ["campaigns", "options", { clientId: scope.clientId }],
    queryFn: () =>
      listCampaigns({
        pageSize: 500,
        clientId: scope.clientId ?? "all",
      }),
  });

  async function handleLogout() {
    await supabase.auth.signOut();
    navigate("/login");
  }

  const hasScope = scope.clientId !== null || scope.campaignId !== null;
  const scopedClient = clients.data?.rows.find((c) => c.id === scope.clientId);
  const scopedCampaign = campaigns.data?.rows.find((c) => c.id === scope.campaignId);

  return (
    <header className="flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4 gap-4">
      <h1 className="text-sm font-semibold text-slate-800 truncate">
        {title}
        {hasScope && (
          <span className="ml-2 text-xs font-normal text-slate-500 truncate">
            — {scopedClient?.name ?? "All clients"}
            {scopedCampaign && ` · ${scopedCampaign.name}`}
          </span>
        )}
      </h1>

      {/* Scope selector */}
      <div className="flex items-center gap-2 flex-1 max-w-2xl">
        <select
          value={scope.clientId ?? ""}
          onChange={(e) =>
            setScope({ clientId: e.target.value || null, campaignId: null })
          }
          className="flex-1 min-w-0 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/40 focus:border-teal-500"
          aria-label="Client scope"
        >
          <option value="">All clients</option>
          {clients.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>

        <select
          value={scope.campaignId ?? ""}
          onChange={(e) => setScope({ campaignId: e.target.value || null })}
          className="flex-1 min-w-0 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/40 focus:border-teal-500"
          aria-label="Campaign scope"
          disabled={!scope.clientId}
          title={!scope.clientId ? "Select a client first" : ""}
        >
          <option value="">All campaigns</option>
          {campaigns.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>

        {hasScope && (
          <button
            onClick={reset}
            className="rounded-md border border-slate-300 bg-white p-1.5 text-slate-500 hover:bg-slate-50"
            aria-label="Clear scope"
            title="Clear scope"
          >
            <X size={14} />
          </button>
        )}
      </div>

      {/* Right side: notifications + user menu */}
      <div className="flex items-center gap-3">
        <button className="rounded-full p-2 hover:bg-slate-100" aria-label="Notifications">
          <Bell size={18} />
        </button>
        <div className="relative">
          <button
            onClick={() => setMenuOpen((v) => !v)}
            className="flex items-center gap-2 rounded-md px-2 py-1 hover:bg-slate-100"
          >
            <UserCircle2 size={22} className="text-slate-500" />
            <div className="hidden text-left sm:block">
              <div className="text-xs font-medium text-slate-800">
                {profile?.full_name ?? profile?.email ?? "User"}
              </div>
              <div className="text-[11px] text-slate-500">{profile?.role ?? "—"}</div>
            </div>
          </button>
          {menuOpen && (
            <div className="absolute right-0 z-10 mt-2 w-44 rounded-md border border-slate-200 bg-white py-1 shadow-lg">
              <button
                onClick={handleLogout}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
              >
                <LogOut size={14} /> Sign out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}