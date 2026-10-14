import { supabase } from "@/lib/supabase";

export async function getDashboardStats() {
  const [clients, campaigns, personnel, assignments] = await Promise.all([
    supabase.from("clients").select("*", { count: "exact", head: true }).eq("status", "active"),
    supabase.from("campaigns").select("*", { count: "exact", head: true }).eq("status", "active"),
    supabase.from("personnel").select("*", { count: "exact", head: true }).eq("status", "active"),
    supabase.from("assignments").select("*", { count: "exact", head: true })
      .gte("scheduled_date", new Date().toISOString().slice(0, 10)),
  ]);

  const anyError = clients.error || campaigns.error || personnel.error || assignments.error;
  if (anyError) throw anyError;

  return {
    activeClients: clients.count ?? 0,
    activeCampaigns: campaigns.count ?? 0,
    activePersonnel: personnel.count ?? 0,
    upcomingAssignments: assignments.count ?? 0,
  };
}

export async function getAssignmentsOverTime(days = 30) {
  const since = new Date();
  since.setDate(since.getDate() - days);
  const { data, error } = await supabase
    .from("assignments")
    .select("scheduled_date, status")
    .gte("scheduled_date", since.toISOString().slice(0, 10));
  if (error) throw error;

  // bucket by date
  const buckets: Record<string, { date: string; total: number; completed: number }> = {};
  for (const row of data ?? []) {
    const key = row.scheduled_date as string;
    buckets[key] ??= { date: key, total: 0, completed: 0 };
    buckets[key].total++;
    if (row.status === "completed") buckets[key].completed++;
  }
  return Object.values(buckets).sort((a, b) => a.date.localeCompare(b.date));
}