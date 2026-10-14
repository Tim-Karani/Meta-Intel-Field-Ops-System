import { supabase } from "@/lib/supabase";
import type { Personnel, PersonnelType, RecordStatus } from "@/types/domain";

export async function listPersonnel(params: {
  search?: string; type?: PersonnelType | "all"; status?: RecordStatus | "all";
  page?: number; pageSize?: number;
} = {}) {
  const { search = "", type = "all", status = "all", page = 1, pageSize = 10 } = params;
  const from = (page - 1) * pageSize;
  const to = from + pageSize - 1;

  let q = supabase
    .from("personnel")
    .select("*", { count: "exact" })
    .order("full_name", { ascending: true })
    .range(from, to);

  if (search) q = q.or(`full_name.ilike.%${search}%,agent_code.ilike.%${search}%,phone.ilike.%${search}%`);
  if (type !== "all") q = q.eq("personnel_type", type);
  if (status !== "all") q = q.eq("status", status);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: (data ?? []) as Personnel[], total: count ?? 0 };
}

export async function createPersonnel(input: Partial<Personnel>) {
  const { data, error } = await supabase.from("personnel").insert(input).select("*").single();
  if (error) throw error;
  return data as Personnel;
}

export async function updatePersonnel(id: string, patch: Partial<Personnel>) {
  const { data, error } = await supabase.from("personnel").update(patch).eq("id", id).select("*").single();
  if (error) throw error;
  return data as Personnel;
}