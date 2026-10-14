import { useState } from "react";
import { useScope } from "@/hooks/useScope";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, FileWarning } from "lucide-react";
import { listIssues, changeIssueStatus } from "@/services/issues.service";
import { listCampaigns } from "@/services/campaigns.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";
import { Badge } from "@/components/ui/Badge";
import { IssueFormModal } from "./IssueFormModal";
import { useToast } from "@/hooks/useToast";
import type { Issue, IssueStatus } from "@/types/domain";

const PRIORITY_COLORS: Record<string, string> = {
  low: "bg-slate-100 text-slate-600 border-slate-200",
  medium: "bg-blue-50 text-blue-700 border-blue-200",
  high: "bg-amber-50 text-amber-700 border-amber-200",
  critical: "bg-red-50 text-red-700 border-red-200",
};

function nextStatus(current: IssueStatus): IssueStatus | null {
  switch (current) {
    case "open": return "in_progress";
    case "in_progress": return "escalated";
    case "escalated": return "resolved";
    case "resolved": return "closed";
    default: return null;
  }
}

export function IssuesListPage() {
  const [search, setSearch] = useState("");
  const { scope } = useScope();
  const [status, setStatus] = useState<IssueStatus | "all">("all");
  const [priority, setPriority] = useState<string | "all">("all");
  const [campaignId, setCampaignId] = useState<string | "all">("all");
  const [page, setPage] = useState(1);
  const pageSize = 15;
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Issue | null>(null);

  const qc = useQueryClient();
  const toast = useToast();

  const campaigns = useQuery({
    queryKey: ["campaigns", "options"],
    queryFn: () => listCampaigns({ pageSize: 500 }),
  });

  const query = useQuery({
  queryKey: ["issues", { search, status, priority, campaignId, page, scopeCampaignId: scope.campaignId }],
  queryFn: () =>
    listIssues({
      search,
      status,
      priority: priority as any,
      campaignId: campaignId === "all" ? scope.campaignId ?? "all" : campaignId,
      page,
      pageSize,
    }),
  });

  const statusMutation = useMutation({
    mutationFn: ({ id, next }: { id: string; next: IssueStatus }) =>
      changeIssueStatus(id, next),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["issues"] });
      toast.push("success", "Issue status updated");
    },
    onError: (e: Error) => toast.push("error", e.message || "Failed to update status"),
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Issues & Escalations"
        description="Field issues, escalations and their resolution trail. Open → In Progress → Escalated → Resolved → Closed."
        actions={
          <Button onClick={() => { setEditing(null); setModalOpen(true); }}>
            <Plus size={16} /> New issue
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-4">
        <Input
          placeholder="Search title, description, category"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
        />
        <Select value={status} onChange={(e) => { setStatus(e.target.value as any); setPage(1); }}>
          <option value="all">All statuses</option>
          {["open", "in_progress", "escalated", "resolved", "closed"].map((s) => (
            <option key={s} value={s}>{s.replace("_", " ")}</option>
          ))}
        </Select>
        <Select value={priority} onChange={(e) => { setPriority(e.target.value); setPage(1); }}>
          <option value="all">All priorities</option>
          {["low", "medium", "high", "critical"].map((p) => (
            <option key={p} value={p}>{p}</option>
          ))}
        </Select>
        <Select value={campaignId} onChange={(e) => { setCampaignId(e.target.value); setPage(1); }}>
          <option value="all">All campaigns</option>
          {campaigns.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </Select>
      </div>

      <div className="rounded-lg border border-slate-200 bg-white">
        {query.isLoading && <div className="p-4"><TableSkeleton rows={8} cols={5} /></div>}

        {query.isError && (
          <div className="p-6 text-sm text-red-600">
            Failed to load issues: {(query.error as Error).message}
          </div>
        )}

        {query.data && query.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState
              icon={<FileWarning size={28} />}
              title="No issues match your filters"
              description="Adjust the filters above, or log a new issue."
              action={
                <Button onClick={() => { setEditing(null); setModalOpen(true); }}>
                  <Plus size={16} /> New issue
                </Button>
              }
            />
          </div>
        )}

        {query.data && query.data.rows.length > 0 && (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Issue</th>
                    <th className="px-4 py-3">Priority</th>
                    <th className="px-4 py-3">Campaign</th>
                    <th className="px-4 py-3">Assigned to</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.data.rows.map((issue: any) => {
                    const next = nextStatus(issue.status);
                    return (
                      <tr key={issue.id} className="hover:bg-slate-50">
                        <td className="px-4 py-3">
                          <div className="font-medium text-slate-800">{issue.title}</div>
                          {issue.category && (
                            <div className="text-xs text-slate-500">{issue.category}</div>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium capitalize ${PRIORITY_COLORS[issue.priority]}`}>
                            {issue.priority}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-slate-600">
                          {issue.campaign?.name ?? "—"}
                        </td>
                        <td className="px-4 py-3 text-slate-600">
                          {issue.assignee?.full_name ?? "—"}
                        </td>
                        <td className="px-4 py-3">
                          <Badge value={issue.status} />
                        </td>
                        <td className="px-4 py-3 text-right whitespace-nowrap">
                          {next && (
                            <Button
                              size="sm"
                              variant="secondary"
                              onClick={() => statusMutation.mutate({ id: issue.id, next })}
                              loading={statusMutation.isPending}
                            >
                              → {next.replace("_", " ")}
                            </Button>
                          )}
                          <Button
                            size="sm"
                            variant="ghost"
                            className="ml-2"
                            onClick={() => { setEditing(issue); setModalOpen(true); }}
                          >
                            Edit
                          </Button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <Pagination
              page={page}
              pageSize={pageSize}
              total={query.data.total}
              onPageChange={setPage}
            />
          </>
        )}
      </div>

      <IssueFormModal open={modalOpen} onClose={() => setModalOpen(false)} initial={editing} />
    </div>
  );
}