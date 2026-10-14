import { useState } from "react";
import { useScope } from "@/hooks/useScope";
import { useQuery } from "@tanstack/react-query";
import { Plus, MapPin } from "lucide-react";
import { listLocations } from "@/services/locations.service";
import { listClients } from "@/services/clients.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";
import { Badge } from "@/components/ui/Badge";
import { LocationFormModal } from "./LocationFormModal";
import type { Location } from "@/types/domain";

export function LocationsListPage() {
  const [search, setSearch] = useState("");
  const { scope } = useScope();
  const [clientId, setClientId] = useState<string | "all">("all");
  const [status, setStatus] = useState<string | "all">("all");
  const [page, setPage] = useState(1);
  const pageSize = 10;
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Location | null>(null);

  const clients = useQuery({
    queryKey: ["clients", "options"],
    queryFn: () => listClients({ pageSize: 500 }),
  });

  const query = useQuery({
  queryKey: ["locations", { search, clientId: clientId === "all" ? scope.clientId ?? "all" : clientId, status, page }],
  queryFn: () =>
    listLocations({
      search,
      clientId: clientId === "all" ? scope.clientId ?? "all" : clientId,
      status,
      page,
      pageSize,
    }),
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Locations & Outlets"
        description="Retail stores, supermarkets, wholesalers, event venues and other field execution points."
        actions={
          <Button onClick={() => { setEditing(null); setModalOpen(true); }}>
            <Plus size={16} /> New location
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3">
        <Input
          placeholder="Search by name, code, town or county"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
        />
        <Select value={clientId} onChange={(e) => { setClientId(e.target.value); setPage(1); }}>
          <option value="all">All clients</option>
          {clients.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </Select>
        <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
          <option value="all">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="archived">Archived</option>
        </Select>
      </div>

      <div className="rounded-lg border border-slate-200 bg-white">
        {query.isLoading && <div className="p-4"><TableSkeleton rows={6} cols={5} /></div>}

        {query.isError && (
          <div className="p-6 text-sm text-red-600">
            Failed to load locations: {(query.error as Error).message}
          </div>
        )}

        {query.data && query.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState
              icon={<MapPin size={28} />}
              title="No locations yet"
              description="Add retail outlets, supermarkets, activation sites or event venues."
              action={
                <Button onClick={() => { setEditing(null); setModalOpen(true); }}>
                  <Plus size={16} /> New location
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
                    <th className="px-4 py-3">Location</th>
                    <th className="px-4 py-3">Client</th>
                    <th className="px-4 py-3">Type</th>
                    <th className="px-4 py-3">Town / County</th>
                    <th className="px-4 py-3">Coordinates</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.data.rows.map((l: any) => (
                    <tr key={l.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-800">{l.name}</div>
                        {l.code && <div className="text-xs text-slate-500">{l.code}</div>}
                      </td>
                      <td className="px-4 py-3 text-slate-600">{l.client?.name ?? "—"}</td>
                      <td className="px-4 py-3 text-slate-600">{l.outlet_type ?? "—"}</td>
                      <td className="px-4 py-3 text-slate-600">
                        <div>{l.town ?? "—"}</div>
                        <div className="text-xs text-slate-500">{l.county ?? ""}</div>
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-600">
                        {l.latitude != null && l.longitude != null
                          ? `${l.latitude.toFixed(4)}, ${l.longitude.toFixed(4)}`
                          : "—"}
                      </td>
                      <td className="px-4 py-3"><Badge value={l.status} /></td>
                      <td className="px-4 py-3 text-right">
                        <Button size="sm" variant="secondary" onClick={() => { setEditing(l); setModalOpen(true); }}>
                          Edit
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} pageSize={pageSize} total={query.data.total} onPageChange={setPage} />
          </>
        )}
      </div>

      <LocationFormModal open={modalOpen} onClose={() => setModalOpen(false)} initial={editing} />
    </div>
  );
}