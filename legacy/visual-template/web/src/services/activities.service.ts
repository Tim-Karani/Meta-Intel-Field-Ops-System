import { supabase } from "@/lib/supabase";
import type { Activity, CampaignStatus } from "@/types/domain";

export async function listActivities(params: {
  campaignId?: string | "all"; status?: CampaignStatus | "all";
  search?: string; page?: number; pageSize?: number;
} = {}) {
  const { campaignId = "all", status = "all", search = "", page = 1, pageSize = 10 } = params;
  const from = (page - 1) * pageSize;
  const to = from + pageSize - 1;

  let q = supabase
    .from("activities")
    .select("*, campaign:campaigns(id,name,client:clients(id,name))", { count: "exact" })
    .order("created_at", { ascending: false })
    .range(from, to);

  if (campaignId !== "all") q = q.eq("campaign_id", campaignId);
  if (status !== "all") q = q.eq("status", status);
  if (search) q = q.ilike("name", `%${search}%`);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: data ?? [], total: count ?? 0 };
}

export async function createActivity(input: Partial<Activity>) {
  const { data, error } = await supabase.from("activities").insert(input).select("*").single();
  if (error) throw error;
  return data as Activity;
}

export async function updateActivity(id: string, patch: Partial<Activity>) {
  const { data, error } = await supabase.from("activities").update(patch).eq("id", id).select("*").single();
  if (error) throw error;
  return data as Activity;
}