import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Plus, Users } from "lucide-react";
import { listClients } from "@/services/clients.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";
import { Badge } from "@/components/ui/Badge";
import { ClientFormModal } from "./ClientFormModal";
import { Link } from "react-router-dom";
import type { Client } from "@/types/domain";

export function ClientsListPage() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<"all" | "active" | "inactive" | "archived">("all");
  const [page, setPage] = useState(1);
  const pageSize = 10;
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Client | null>(null);

  const query = useQuery({
    queryKey: ["clients", { search, status, page }],
    queryFn: () => listClients({ search, status, page, pageSize }),
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Clients"
        description="Manage the agency's clients and their associated campaigns."
        actions={
          <Button onClick={() => { setEditing(null); setModalOpen(true); }}>
            <Plus size={16} /> New client
          </Button>
        }
      />

      <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:flex-row">
        <Input
          placeholder="Search by name, company or contact"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
        />
        <Select
          value={status}
          onChange={(e) => { setStatus(e.target.value as typeof status); setPage(1); }}
          className="sm:max-w-[180px]"
        >
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
            Failed to load clients: {(query.error as Error).message}
          </div>
        )}
        {query.data && query.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState
              icon={<Users size={28} />}
              title="No clients yet"
              description="Create your first client to start building campaigns."
              action={<Button onClick={() => setModalOpen(true)}><Plus size={16} /> New client</Button>}
            />
          </div>
        )}
        {query.data && query.data.rows.length > 0 && (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Client</th>
                    <th className="px-4 py-3">Contact</th>
                    <th className="px-4 py-3">Industry</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.data.rows.map((c) => (
                    <tr key={c.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3">
                        <Link to={`/clients/${c.id}`} className="font-medium text-navy-800 hover:underline">
                          {c.name}
                        </Link>
                        <div className="text-xs text-slate-500">{c.company_name}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div>{c.contact_person ?? "—"}</div>
                        <div className="text-xs text-slate-500">{c.email ?? c.phone ?? ""}</div>
                      </td>
                      <td className="px-4 py-3">{c.industry ?? "—"}</td>
                      <td className="px-4 py-3"><Badge value={c.status} /></td>
                      <td className="px-4 py-3 text-right">
                        <Button size="sm" variant="secondary" onClick={() => { setEditing(c); setModalOpen(true); }}>
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

      <ClientFormModal open={modalOpen} onClose={() => setModalOpen(false)} initial={editing} />
    </div>
  );
}