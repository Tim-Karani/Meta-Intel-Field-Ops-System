import { supabase } from "@/lib/supabase";

export interface AuditLogRow {
  id: number;
  user_id: string | null;
  action: string;
  entity: string;
  entity_id: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface ListAuditParams {
  entity?: string | "all";
  action?: string | "all";
  userId?: string | "all";
  from?: string;
  to?: string;
  page?: number;
  pageSize?: number;
}

export async function listAuditLogs(params: ListAuditParams = {}) {
  const {
    entity = "all",
    action = "all",
    userId = "all",
    from,
    to,
    page = 1,
    pageSize = 25,
  } = params;

  const start = (page - 1) * pageSize;
  const end = start + pageSize - 1;

  let q = supabase
    .from("audit_logs")
    .select("*", { count: "exact" })
    .order("created_at", { ascending: false })
    .range(start, end);

  if (entity !== "all") q = q.eq("entity", entity);
  if (action !== "all") q = q.eq("action", action);
  if (userId !== "all") q = q.eq("user_id", userId);
  if (from) q = q.gte("created_at", from);
  if (to) q = q.lte("created_at", `${to}T23:59:59`);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: (data ?? []) as AuditLogRow[], total: count ?? 0 };
}

export async function listAuditEntityOptions() {
  // Return the distinct entity names that have appeared in the log.
  const { data, error } = await supabase
    .from("audit_logs")
    .select("entity")
    .limit(1000);
  if (error) throw error;
  const unique = Array.from(new Set((data ?? []).map((r) => r.entity))).sort();
  return unique;
}