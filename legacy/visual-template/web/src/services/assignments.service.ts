import { supabase } from "@/lib/supabase";
import type { Assignment, AssignmentStatus } from "@/types/domain";

export async function listAssignments(params: {
  personnelId?: string | "all"; campaignId?: string | "all";
  status?: AssignmentStatus | "all";
  from?: string; to?: string;
  page?: number; pageSize?: number;
} = {}) {
  const { personnelId = "all", campaignId = "all", status = "all",
          from: fromDate, to: toDate, page = 1, pageSize = 20 } = params;
  const from = (page - 1) * pageSize;
  const to = from + pageSize - 1;

  let q = supabase
    .from("assignments")
    .select(`
      *,
      personnel:personnel(id,full_name,personnel_type),
      campaign:campaigns(id,name),
      activity:activities(id,name),
      location:locations(id,name,town,county)
    `, { count: "exact" })
    .order("scheduled_date", { ascending: false })
    .range(from, to);

  if (personnelId !== "all") q = q.eq("personnel_id", personnelId);
  if (campaignId !== "all") q = q.eq("campaign_id", campaignId);
  if (status !== "all") q = q.eq("status", status);
  if (fromDate) q = q.gte("scheduled_date", fromDate);
  if (toDate) q = q.lte("scheduled_date", toDate);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: data ?? [], total: count ?? 0 };
}

export async function createAssignment(input: Partial<Assignment>) {
  const { data, error } = await supabase.from("assignments").insert(input).select("*").single();
  if (error) throw error;
  return data as Assignment;
}

export async function updateAssignment(id: string, patch: Partial<Assignment>) {
  const { data, error } = await supabase.from("assignments").update(patch).eq("id", id).select("*").single();
  if (error) throw error;
  return data as Assignment;
}