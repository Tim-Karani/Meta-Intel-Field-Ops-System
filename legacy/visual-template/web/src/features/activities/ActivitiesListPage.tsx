import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useScope } from "@/hooks/useScope";
import { Plus, ListChecks } from "lucide-react";
import { listActivities } from "@/services/activities.service";
import { listCampaigns } from "@/services/campaigns.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";
import { Badge } from "@/components/ui/Badge";
import { ActivityFormModal } from "./ActivityFormModal";
import type { Activity } from "@/types/domain";

export function ActivitiesListPage() {
  const [search, setSearch] = useState("");
  const { scope } = useScope();
  const [campaignId, setCampaignId] = useState<string | "all">("all");
  const [status, setStatus] = useState<string | "all">("all");
  const [page, setPage] = useState(1);
  const pageSize = 10;
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Activity | null>(null);

  const campaigns = useQuery({
    queryKey: ["campaigns", "options"],
    queryFn: () => listCampaigns({ pageSize: 500 }),
  });

  const query = useQuery({
  queryKey: ["activities", { search, campaignId, status, page, scopeCampaignId: scope.campaignId }],
  queryFn: () =>
    listActivities({
      search,
      campaignId: campaignId === "all" ? scope.campaignId ?? "all" : campaignId,
      status: status as any,
      page,
      pageSize,
    }),
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Activities"
        description="Field activities within campaigns — merchandising, activation, audits, sales drives."
        actions={
          <Button
            onClick={() => {
              setEditing(null);
              setModalOpen(true);
            }}
          >
            <Plus size={16} /> New activity
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3">
        <Input
          placeholder="Search by activity name"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
        <Select
          value={campaignId}
          onChange={(e) => {
            setCampaignId(e.target.value);
            setPage(1);
          }}
        >
          <option value="all">All campaigns</option>
          {campaigns.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </Select>
        <Select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
        >
          <option value="all">All statuses</option>
          {["draft", "planned", "active", "paused", "completed", "cancelled"].map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </Select>
      </div>

      <div className="rounded-lg border border-slate-200 bg-white">
        {query.isLoading && (
          <div className="p-4">
            <TableSkeleton rows={6} cols={5} />
          </div>
        )}

        {query.isError && (
          <div className="p-6 text-sm text-red-600">
            Failed to load activities: {(query.error as Error).message}
          </div>
        )}

        {query.data && query.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState
              icon={<ListChecks size={28} />}
              title="No activities yet"
              description="Create an activity inside a campaign — merchandising, activation, audit or sales drive."
              action={
                <Button
                  onClick={() => {
                    setEditing(null);
                    setModalOpen(true);
                  }}
                >
                  <Plus size={16} /> New activity
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
                    <th className="px-4 py-3">Activity</th>
                    <th className="px-4 py-3">Campaign</th>
                    <th className="px-4 py-3">Type</th>
                    <th className="px-4 py-3">Dates</th>
                    <th className="px-4 py-3">Target</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.data.rows.map((a: any) => (
                    <tr key={a.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3 font-medium text-slate-800">{a.name}</td>
                      <td className="px-4 py-3 text-slate-600">
                        {a.campaign?.name ?? "—"}
                        {a.campaign?.client?.name && (
                          <div className="text-xs text-slate-500">{a.campaign.client.name}</div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-slate-600">{a.activity_type ?? "—"}</td>
                      <td className="px-4 py-3 text-xs text-slate-600">
                        {a.start_date ?? "—"} → {a.end_date ?? "—"}
                      </td>
                      <td className="px-4 py-3 text-slate-600">{a.target ?? "—"}</td>
                      <td className="px-4 py-3">
                        <Badge value={a.status} />
                      </td>
                      <td className="px-4 py-3 text-right">
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            setEditing(a);
                            setModalOpen(true);
                          }}
                        >
                          Edit
                        </Button>
                      </td>
                    </tr>
                  ))}
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

      <ActivityFormModal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        initial={editing}
      />
    </div>
  );
}