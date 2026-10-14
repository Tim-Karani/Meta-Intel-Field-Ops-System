import { supabase } from "@/lib/supabase";
import type { Location } from "@/types/domain";

export async function listLocations(params: {
  search?: string; clientId?: string | "all"; status?: string | "all";
  page?: number; pageSize?: number;
} = {}) {
  const { search = "", clientId = "all", status = "all", page = 1, pageSize = 10 } = params;
  const from = (page - 1) * pageSize;
  const to = from + pageSize - 1;

  let q = supabase
    .from("locations")
    .select("*, client:clients(id,name)", { count: "exact" })
    .order("name")
    .range(from, to);

  if (search) q = q.or(`name.ilike.%${search}%,code.ilike.%${search}%,town.ilike.%${search}%,county.ilike.%${search}%`);
  if (clientId !== "all") q = q.eq("client_id", clientId);
  if (status !== "all") q = q.eq("status", status);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: data ?? [], total: count ?? 0 };
}

export async function createLocation(input: Partial<Location>) {
  const { data, error } = await supabase.from("locations").insert(input).select("*").single();
  if (error) throw error;
  return data as Location;
}

export async function updateLocation(id: string, patch: Partial<Location>) {
  const { data, error } = await supabase.from("locations").update(patch).eq("id", id).select("*").single();
  if (error) throw error;
  return data as Location;
}