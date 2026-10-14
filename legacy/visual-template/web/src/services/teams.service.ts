import { supabase } from "@/lib/supabase";
import type { Team } from "@/types/domain";

export async function listTeams(params: { search?: string; page?: number; pageSize?: number } = {}) {
  const { search = "", page = 1, pageSize = 10 } = params;
  const from = (page - 1) * pageSize;
  const to = from + pageSize - 1;

  let q = supabase
    .from("teams")
    .select("*, supervisor:personnel!teams_supervisor_id_fkey(id,full_name)", { count: "exact" })
    .order("name")
    .range(from, to);

  if (search) q = q.ilike("name", `%${search}%`);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: data ?? [], total: count ?? 0 };
}

export async function createTeam(input: Partial<Team>) {
  const { data, error } = await supabase.from("teams").insert(input).select("*").single();
  if (error) throw error;
  return data as Team;
}

export async function updateTeam(id: string, patch: Partial<Team>) {
  const { data, error } = await supabase.from("teams").update(patch).eq("id", id).select("*").single();
  if (error) throw error;
  return data as Team;
}