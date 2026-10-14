import { supabase } from "@/lib/supabase";
import type { Client, RecordStatus } from "@/types/domain";

export interface ListParams {
  search?: string;
  status?: RecordStatus | "all";
  page?: number;
  pageSize?: number;
}

export async function listClients(params: ListParams = {}) {
  const { search = "", status = "all", page = 1, pageSize = 10 } = params;
  const from = (page - 1) * pageSize;
  const to = from + pageSize - 1;

  let q = supabase
    .from("clients")
    .select("*", { count: "exact" })
    .order("created_at", { ascending: false })
    .range(from, to);

  if (search) q = q.or(`name.ilike.%${search}%,company_name.ilike.%${search}%,contact_person.ilike.%${search}%`);
  if (status !== "all") q = q.eq("status", status);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: (data ?? []) as Client[], total: count ?? 0 };
}

export async function getClient(id: string) {
  const { data, error } = await supabase.from("clients").select("*").eq("id", id).single();
  if (error) throw error;
  return data as Client;
}

export type ClientInput = Omit<Client, "id" | "created_at" | "updated_at">;

export async function createClient(input: Partial<ClientInput>) {
  const { data, error } = await supabase.from("clients").insert(input).select("*").single();
  if (error) throw error;
  return data as Client;
}

export async function updateClient(id: string, patch: Partial<ClientInput>) {
  const { data, error } = await supabase.from("clients").update(patch).eq("id", id).select("*").single();
  if (error) throw error;
  return data as Client;
}

export async function archiveClient(id: string) {
  return updateClient(id, { status: "archived" });
}