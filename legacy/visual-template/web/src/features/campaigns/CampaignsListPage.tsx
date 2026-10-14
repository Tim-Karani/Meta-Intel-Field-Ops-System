import { useState } from "react";
import { useScope } from "@/hooks/useScope";
import { useQuery } from "@tanstack/react-query";
import { Plus, Megaphone } from "lucide-react";
import { Link } from "react-router-dom";
import { listCampaigns } from "@/services/campaigns.service";
import { listClients } from "@/services/clients.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";
import { Badge } from "@/components/ui/Badge";
import { CampaignFormModal } from "./CampaignFormModal";
import type { Campaign } from "@/types/domain";

export function CampaignsListPage() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<any>("all");
  const [clientId, setClientId] = useState<any>("all");
  const [page, setPage] = useState(1);
  const pageSize = 10;
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Campaign | null>(null);

  const { scope } = useScope();

  const clients = useQuery({
    queryKey: ["clients", "options"],
    queryFn: () => listClients({ pageSize: 500 }),
  });

  // If the page's own client filter is "all", inherit from the global scope.
  // Otherwise the page filter wins.
  const effectiveClientId =
    clientId === "all" ? scope.clientId ?? "all" : clientId;

  const query = useQuery({
    queryKey: ["campaigns", { search, status, clientId: effectiveClientId, page }],
    queryFn: () =>
      listCampaigns({
        search,
        status,
        clientId: effectiveClientId,
        page,
        pageSize,
      }),
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Campaigns"
        description="Campaigns belong to clients and contain activities, teams, locations and assignments."
        actions={
          <Button
            onClick={() => {
              setEditing(null);
              setModalOpen(true);
            }}
          >
            <Plus size={16} /> New campaign
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3">
        <Input
          placeholder="Search by name or code"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
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
        <Select
          value={clientId}
          onChange={(e) => {
            setClientId(e.target.value);
            setPage(1);
          }}
        >
          <option value="all">All clients</option>
          {clients.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
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
            Failed to load campaigns: {(query.error as Error).message}
          </div>
        )}
        {query.data && query.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState
              icon={<Megaphone size={28} />}
              title="No campaigns"
              description="Create a campaign to get started."
            />
          </div>
        )}
        {query.data && query.data.rows.length > 0 && (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Campaign</th>
                    <th className="px-4 py-3">Client</th>
                    <th className="px-4 py-3">Dates</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.data.rows.map((cmp) => (
                    <tr key={cmp.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3">
                        <Link
                          to={`/campaigns/${cmp.id}`}
                          className="font-medium text-navy-800 hover:underline"
                        >
                          {cmp.name}
                        </Link>
                        <div className="text-xs text-slate-500">{cmp.code}</div>
                      </td>
                      <td className="px-4 py-3">
                        {(cmp as any).client?.name ?? "—"}
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-600">
                        {cmp.start_date ?? "—"} → {cmp.end_date ?? "—"}
                      </td>
                      <td className="px-4 py-3">
                        <Badge value={cmp.status} />
                      </td>
                      <td className="px-4 py-3 text-right">
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            setEditing(cmp);
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

      <CampaignFormModal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        initial={editing}
      />
    </div>
  );
}