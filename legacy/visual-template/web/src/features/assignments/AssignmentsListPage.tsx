import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useScope } from "@/hooks/useScope";
import { Plus, CalendarCheck } from "lucide-react";
import { listAssignments } from "@/services/assignments.service";
import { listCampaigns } from "@/services/campaigns.service";
import { listPersonnel } from "@/services/personnel.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";
import { Badge } from "@/components/ui/Badge";
import { AssignmentFormModal } from "./AssignmentFormModal";

export function AssignmentsListPage() {
  const [campaignId, setCampaignId] = useState<any>("all");
  const { scope } = useScope();
  const [personnelId, setPersonnelId] = useState<any>("all");
  const [status, setStatus] = useState<any>("all");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const pageSize = 20;
  const [modalOpen, setModalOpen] = useState(false);

  const campaigns = useQuery({ queryKey: ["campaigns", "options"], queryFn: () => listCampaigns({ pageSize: 500 }) });
  const personnel = useQuery({ queryKey: ["personnel", "options"], queryFn: () => listPersonnel({ pageSize: 500 }) });

  const query = useQuery({
  queryKey: ["assignments", { campaignId, personnelId, status, from, to, page, scopeCampaignId: scope.campaignId }],
  queryFn: () =>
    listAssignments({
      campaignId: campaignId === "all" ? scope.campaignId ?? "all" : campaignId,
      personnelId,
      status,
      from: from || undefined,
      to: to || undefined,
      page,
      pageSize,
    }),
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Assignments"
        description="Planned field work connecting personnel, campaigns, activities and locations."
        actions={<Button onClick={() => setModalOpen(true)}><Plus size={16} /> New assignment</Button>}
      />

      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-5">
        <Select value={campaignId} onChange={(e) => { setCampaignId(e.target.value); setPage(1); }}>
          <option value="all">All campaigns</option>
          {campaigns.data?.rows.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </Select>
        <Select value={personnelId} onChange={(e) => { setPersonnelId(e.target.value); setPage(1); }}>
          <option value="all">All personnel</option>
          {personnel.data?.rows.map((p) => <option key={p.id} value={p.id}>{p.full_name}</option>)}
        </Select>
        <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
          <option value="all">All statuses</option>
          {["scheduled","in_progress","completed","missed","cancelled"].map((s) => <option key={s} value={s}>{s}</option>)}
        </Select>
        <Input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} />
        <Input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} />
      </div>

      <div className="rounded-lg border border-slate-200 bg-white">
        {query.isLoading && <div className="p-4"><TableSkeleton rows={8} cols={6} /></div>}
        {query.isError && <div className="p-6 text-sm text-red-600">Failed to load assignments: {(query.error as Error).message}</div>}
        {query.data && query.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState icon={<CalendarCheck size={28} />} title="No assignments found" description="Create an assignment to schedule field work." />
          </div>
        )}
        {query.data && query.data.rows.length > 0 && (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Date</th>
                    <th className="px-4 py-3">Personnel</th>
                    <th className="px-4 py-3">Campaign</th>
                    <th className="px-4 py-3">Activity</th>
                    <th className="px-4 py-3">Location</th>
                    <th className="px-4 py-3">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.data.rows.map((a: any) => (
                    <tr key={a.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3 text-slate-700">
                        {a.scheduled_date}
                        <div className="text-xs text-slate-500">
                          {a.start_time ?? ""} {a.end_time ? `– ${a.end_time}` : ""}
                        </div>
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-800">{a.personnel?.full_name ?? "—"}</td>
                      <td className="px-4 py-3 text-slate-700">{a.campaign?.name ?? "—"}</td>
                      <td className="px-4 py-3 text-slate-700">{a.activity?.name ?? "—"}</td>
                      <td className="px-4 py-3 text-slate-700">
                        {a.location?.name ?? "—"}
                        {a.location?.town && <div className="text-xs text-slate-500">{a.location.town}</div>}
                      </td>
                      <td className="px-4 py-3"><Badge value={a.status} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} pageSize={pageSize} total={query.data.total} onPageChange={setPage} />
          </>
        )}
      </div>

      <AssignmentFormModal open={modalOpen} onClose={() => setModalOpen(false)} />
    </div>
  );
}