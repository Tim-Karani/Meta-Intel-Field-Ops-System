import { supabase } from "@/lib/supabase";
import type { Campaign, CampaignStatus } from "@/types/domain";

export interface CampaignListParams {
  search?: string;
  status?: CampaignStatus | "all";
  clientId?: string | "all";
  page?: number;
  pageSize?: number;
}

export async function listCampaigns(p: CampaignListParams = {}) {
  const { search = "", status = "all", clientId = "all", page = 1, pageSize = 10 } = p;
  const from = (page - 1) * pageSize;
  const to = from + pageSize - 1;

  let q = supabase
    .from("campaigns")
    .select("*, client:clients(id,name)", { count: "exact" })
    .order("created_at", { ascending: false })
    .range(from, to);

  if (search) q = q.or(`name.ilike.%${search}%,code.ilike.%${search}%`);
  if (status !== "all") q = q.eq("status", status);
  if (clientId !== "all") q = q.eq("client_id", clientId);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: (data ?? []) as (Campaign & { client?: { id: string; name: string } })[], total: count ?? 0 };
}

export async function getCampaign(id: string) {
  const { data, error } = await supabase
    .from("campaigns")
    .select("*, client:clients(id,name)")
    .eq("id", id)
    .single();
  if (error) throw error;
  return data;
}

export type CampaignInput = Omit<Campaign, "id" | "created_at" | "updated_at">;

export async function createCampaign(input: Partial<CampaignInput>) {
  const { data, error } = await supabase.from("campaigns").insert(input).select("*").single();
  if (error) throw error;
  return data as Campaign;
}

export async function updateCampaign(id: string, patch: Partial<CampaignInput>) {
  const { data, error } = await supabase.from("campaigns").update(patch).eq("id", id).select("*").single();
  if (error) throw error;
  return data as Campaign;
}