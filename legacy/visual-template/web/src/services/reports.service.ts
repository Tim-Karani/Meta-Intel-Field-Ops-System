import { supabase } from "@/lib/supabase";

export type ReportType = "attendance" | "visits" | "sales" | "osa" | "assignments" | "issues";

export interface ReportFilters {
  from?: string;
  to?: string;
  campaignId?: string;
  personnelId?: string;
  locationId?: string;
  status?: string;
}

export interface ReportRow {
  [key: string]: string | number | null;
}

export interface ReportResult {
  columns: { key: string; label: string }[];
  rows: ReportRow[];
}

// ---- Assignments report ----
async function getAssignmentsReport(f: ReportFilters): Promise<ReportResult> {
  let q = supabase
    .from("assignments")
    .select(`
      scheduled_date, start_time, end_time, status,
      personnel:personnel(id,full_name,personnel_type),
      campaign:campaigns(id,name),
      activity:activities(id,name),
      location:locations(id,name,town,county)
    `)
    .order("scheduled_date", { ascending: false })
    .limit(2000);

  if (f.from) q = q.gte("scheduled_date", f.from);
  if (f.to) q = q.lte("scheduled_date", f.to);
  if (f.campaignId) q = q.eq("campaign_id", f.campaignId);
  if (f.personnelId) q = q.eq("personnel_id", f.personnelId);
  if (f.locationId) q = q.eq("location_id", f.locationId);
  if (f.status) q = q.eq("status", f.status as any);

  const { data, error } = await q;
  if (error) throw error;

  return {
    columns: [
      { key: "scheduled_date", label: "Date" },
      { key: "start_time", label: "Start" },
      { key: "end_time", label: "End" },
      { key: "personnel_name", label: "Personnel" },
      { key: "campaign_name", label: "Campaign" },
      { key: "activity_name", label: "Activity" },
      { key: "location_name", label: "Location" },
      { key: "location_town", label: "Town" },
      { key: "status", label: "Status" },
    ],
    rows: (data ?? []).map((r: any) => ({
      scheduled_date: r.scheduled_date,
      start_time: r.start_time ?? "",
      end_time: r.end_time ?? "",
      personnel_name: r.personnel?.full_name ?? "",
      campaign_name: r.campaign?.name ?? "",
      activity_name: r.activity?.name ?? "",
      location_name: r.location?.name ?? "",
      location_town: r.location?.town ?? "",
      status: r.status,
    })),
  };
}

// ---- Issues report ----
async function getIssuesReport(f: ReportFilters): Promise<ReportResult> {
  let q = supabase
    .from("issues")
    .select(`
      title, category, priority, status, resolution, resolved_at, created_at,
      campaign:campaigns(id,name),
      location:locations(id,name),
      assignee:personnel!issues_assigned_to_fkey(id,full_name),
      reporter:personnel!issues_reported_by_fkey(id,full_name)
    `)
    .order("created_at", { ascending: false })
    .limit(2000);

  if (f.from) q = q.gte("created_at", f.from);
  if (f.to) q = q.lte("created_at", `${f.to}T23:59:59`);
  if (f.campaignId) q = q.eq("campaign_id", f.campaignId);
  if (f.status) q = q.eq("status", f.status as any);
  if (f.personnelId) q = q.eq("assigned_to", f.personnelId);

  const { data, error } = await q;
  if (error) throw error;

  return {
    columns: [
      { key: "created_at", label: "Created" },
      { key: "title", label: "Title" },
      { key: "category", label: "Category" },
      { key: "priority", label: "Priority" },
      { key: "status", label: "Status" },
      { key: "campaign_name", label: "Campaign" },
      { key: "location_name", label: "Location" },
      { key: "reporter_name", label: "Reported by" },
      { key: "assignee_name", label: "Assigned to" },
      { key: "resolution", label: "Resolution" },
    ],
    rows: (data ?? []).map((r: any) => ({
      created_at: new Date(r.created_at).toLocaleDateString(),
      title: r.title,
      category: r.category ?? "",
      priority: r.priority,
      status: r.status,
      campaign_name: r.campaign?.name ?? "",
      location_name: r.location?.name ?? "",
      reporter_name: r.reporter?.full_name ?? "",
      assignee_name: r.assignee?.full_name ?? "",
      resolution: r.resolution ?? "",
    })),
  };
}

// ---- Placeholder reports for future modules ----
async function getPlaceholderReport(_f: ReportFilters): Promise<ReportResult> {
  return {
    columns: [{ key: "note", label: "Note" }],
    rows: [
      { note: "This report will be available once the corresponding module (Attendance / Visits / Sales / OSA) is built with the mobile app." },
    ],
  };
}

export async function runReport(type: ReportType, filters: ReportFilters): Promise<ReportResult> {
  switch (type) {
    case "assignments":
      return getAssignmentsReport(filters);
    case "issues":
      return getIssuesReport(filters);
    case "attendance":
    case "visits":
    case "sales":
    case "osa":
      return getPlaceholderReport(filters);
    default:
      return { columns: [], rows: [] };
  }
}

// ---- CSV export ----
export function exportToCsv(filename: string, result: ReportResult) {
  const header = result.columns.map((c) => csvEscape(c.label)).join(",");
  const body = result.rows
    .map((row) => result.columns.map((c) => csvEscape(row[c.key])).join(","))
    .join("\n");
  const csv = `${header}\n${body}`;

  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function csvEscape(value: unknown): string {
  if (value == null) return "";
  const s = String(value);
  if (s.includes(",") || s.includes('"') || s.includes("\n")) {
    return `"${s.replace(/"/g, '""')}"`;
  }
  return s;
}