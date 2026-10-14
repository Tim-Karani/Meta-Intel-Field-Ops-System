import { supabase } from "@/lib/supabase";
import type { Issue, IssuePriority, IssueStatus } from "@/types/domain";

export interface ListIssuesParams {
  search?: string;
  status?: IssueStatus | "all";
  priority?: IssuePriority | "all";
  campaignId?: string | "all";
  assignedTo?: string | "all";
  page?: number;
  pageSize?: number;
}

export async function listIssues(params: ListIssuesParams = {}) {
  const {
    search = "",
    status = "all",
    priority = "all",
    campaignId = "all",
    assignedTo = "all",
    page = 1,
    pageSize = 15,
  } = params;

  const from = (page - 1) * pageSize;
  const to = from + pageSize - 1;

  let q = supabase
    .from("issues")
    .select(
      `*,
       campaign:campaigns(id,name),
       location:locations(id,name),
       assignee:personnel!issues_assigned_to_fkey(id,full_name),
       reporter:personnel!issues_reported_by_fkey(id,full_name)`,
      { count: "exact" }
    )
    .order("created_at", { ascending: false })
    .range(from, to);

  if (search) q = q.or(`title.ilike.%${search}%,description.ilike.%${search}%,category.ilike.%${search}%`);
  if (status !== "all") q = q.eq("status", status);
  if (priority !== "all") q = q.eq("priority", priority);
  if (campaignId !== "all") q = q.eq("campaign_id", campaignId);
  if (assignedTo !== "all") q = q.eq("assigned_to", assignedTo);

  const { data, error, count } = await q;
  if (error) throw error;
  return { rows: data ?? [], total: count ?? 0 };
}

export async function createIssue(input: Partial<Issue>) {
  const { data, error } = await supabase.from("issues").insert(input).select("*").single();
  if (error) throw error;
  return data as Issue;
}

export async function updateIssue(id: string, patch: Partial<Issue>) {
  const { data, error } = await supabase
    .from("issues")
    .update(patch)
    .eq("id", id)
    .select("*")
    .single();
  if (error) throw error;
  return data as Issue;
}

export async function changeIssueStatus(id: string, status: IssueStatus) {
  const patch: Partial<Issue> = { status };
  if (status === "resolved") {
    patch.resolved_at = new Date().toISOString();
  }
  return updateIssue(id, patch);
}