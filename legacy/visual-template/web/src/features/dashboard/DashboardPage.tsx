import { useQuery } from "@tanstack/react-query";
import {
  LineChart, Line, XAxis, YAxis, Tooltip, CartesianGrid, ResponsiveContainer,
} from "recharts";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { supabase } from "@/lib/supabase";
import { useScope } from "@/hooks/useScope";

async function getStats(scope: { clientId: string | null; campaignId: string | null }) {
  // Build the campaign filter once. If a campaign is selected, all counts
  // are scoped to it. Otherwise if a client is selected, scope to all that
  // client's campaigns.
  let campaignIds: string[] | null = null;

  if (scope.campaignId) {
    campaignIds = [scope.campaignId];
  } else if (scope.clientId) {
    const { data, error } = await supabase
      .from("campaigns")
      .select("id")
      .eq("client_id", scope.clientId);
    if (error) throw error;
    campaignIds = (data ?? []).map((c) => c.id);
  }

  // Base queries — filtered by campaignIds if scope is set
  const filterByCampaigns = <T,>(q: any) =>
    campaignIds ? q.in("campaign_id", campaignIds.length ? campaignIds : ["__none__"]) : q;

  const clientsQ = scope.clientId
    ? supabase.from("clients").select("*", { count: "exact", head: true }).eq("id", scope.clientId)
    : supabase.from("clients").select("*", { count: "exact", head: true }).eq("status", "active");

  const campaignsQ = campaignIds
    ? supabase.from("campaigns").select("*", { count: "exact", head: true }).in("id", campaignIds.length ? campaignIds : ["__none__"])
    : supabase.from("campaigns").select("*", { count: "exact", head: true }).eq("status", "active");

  const assignmentsQ = filterByCampaigns(
    supabase.from("assignments").select("*", { count: "exact", head: true })
      .gte("scheduled_date", new Date().toISOString().slice(0, 10))
  );

  const issuesQ = filterByCampaigns(
    supabase.from("issues").select("*", { count: "exact", head: true })
      .in("status", ["open", "in_progress", "escalated"])
  );

  const [clients, campaigns, assignments, issues] = await Promise.all([
    clientsQ, campaignsQ, assignmentsQ, issuesQ,
  ]);

  const err = clients.error || campaigns.error || assignments.error || issues.error;
  if (err) throw err;

  return {
    activeClients: clients.count ?? 0,
    activeCampaigns: campaigns.count ?? 0,
    upcomingAssignments: assignments.count ?? 0,
    openIssues: issues.count ?? 0,
  };
}

async function getTrend(
  scope: { clientId: string | null; campaignId: string | null },
  days = 30
) {
  const since = new Date();
  since.setDate(since.getDate() - days);

  let campaignIds: string[] | null = null;
  if (scope.campaignId) {
    campaignIds = [scope.campaignId];
  } else if (scope.clientId) {
    const { data, error } = await supabase
      .from("campaigns")
      .select("id")
      .eq("client_id", scope.clientId);
    if (error) throw error;
    campaignIds = (data ?? []).map((c) => c.id);
  }

  let q = supabase
    .from("assignments")
    .select("scheduled_date, status")
    .gte("scheduled_date", since.toISOString().slice(0, 10));

  if (campaignIds) {
    q = q.in("campaign_id", campaignIds.length ? campaignIds : ["__none__"]);
  }

  const { data, error } = await q;
  if (error) throw error;

  const buckets: Record<string, { date: string; total: number; completed: number }> = {};
  for (const row of data ?? []) {
    const key = row.scheduled_date as string;
    buckets[key] ??= { date: key, total: 0, completed: 0 };
    buckets[key].total++;
    if (row.status === "completed") buckets[key].completed++;
  }
  return Object.values(buckets).sort((a, b) => a.date.localeCompare(b.date));
}

export function DashboardPage() {
  const { scope } = useScope();

  const stats = useQuery({
    queryKey: ["dashboard", "stats", scope],
    queryFn: () => getStats(scope),
  });
  const trend = useQuery({
    queryKey: ["dashboard", "trend", scope],
    queryFn: () => getTrend(scope, 30),
  });

  const kpis = [
    { label: scope.clientId ? "Client" : "Active Clients", value: stats.data?.activeClients },
    { label: scope.clientId || scope.campaignId ? "Campaigns in scope" : "Active Campaigns", value: stats.data?.activeCampaigns },
    { label: "Upcoming Assignments", value: stats.data?.upcomingAssignments },
    { label: "Open Issues", value: stats.data?.openIssues },
  ];

  const hasScope = scope.clientId !== null || scope.campaignId !== null;

  return (
    <div className="space-y-6">
      {hasScope && (
        <div className="rounded-lg border border-teal-200 bg-teal-50 px-4 py-2 text-sm text-teal-800">
          Dashboard is scoped to the current selection. Change the client/campaign in the header, or click × to clear.
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {kpis.map((k) => (
          <Card key={k.label}>
            <CardBody>
              <div className="text-xs uppercase tracking-wide text-slate-500">{k.label}</div>
              {stats.isLoading ? (
                <Skeleton className="mt-2 h-7 w-16" />
              ) : (
                <div className="mt-1 text-2xl font-semibold text-slate-900">{k.value ?? 0}</div>
              )}
            </CardBody>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader
          title="Assignments — last 30 days"
          subtitle="Planned vs completed"
        />
        <CardBody>
          {trend.isLoading && <Skeleton className="h-64 w-full" />}
          {trend.isError && (
            <p className="text-sm text-red-600">Failed to load activity.</p>
          )}
          {trend.data && trend.data.length === 0 && (
            <EmptyState
              title="No assignments in this period"
              description={
                hasScope
                  ? "No assignments were scheduled for this client/campaign in the last 30 days. Try clearing the scope."
                  : "Once assignments are scheduled, they will appear here."
              }
            />
          )}
          {trend.data && trend.data.length > 0 && (
            <div className="h-64 w-full">
              <ResponsiveContainer>
                <LineChart data={trend.data}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                  <Tooltip />
                  <Line type="monotone" dataKey="total" stroke="#1ba4a8" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey="completed" stroke="#213660" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}