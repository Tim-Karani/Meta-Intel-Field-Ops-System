import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Plus, UserCog } from "lucide-react";
import { listPersonnel } from "@/services/personnel.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";
import { Badge } from "@/components/ui/Badge";
import { PersonnelFormModal } from "./PersonnelFormModal";
import type { Personnel } from "@/types/domain";

export function PersonnelListPage() {
  const [search, setSearch] = useState("");
  const [type, setType] = useState<any>("all");
  const [status, setStatus] = useState<any>("all");
  const [page, setPage] = useState(1);
  const pageSize = 10;
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Personnel | null>(null);

  const query = useQuery({
    queryKey: ["personnel", { search, type, status, page }],
    queryFn: () => listPersonnel({ search, type, status, page, pageSize }),
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Field Personnel"
        description="Central registry of merchandisers, brand ambassadors, promoters and supervisors."
        actions={<Button onClick={() => { setEditing(null); setModalOpen(true); }}><Plus size={16} /> New personnel</Button>}
      />

      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3">
        <Input placeholder="Search by name, code, phone" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
        <Select value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
          <option value="all">All types</option>
          {["MERCHANDISER","BRAND_AMBASSADOR","PROMOTER","FIELD_REPRESENTATIVE","SUPERVISOR","COORDINATOR"].map((t) => (
            <option key={t} value={t}>{t.replace("_", " ")}</option>
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
        {query.isError && <div className="p-6 text-sm text-red-600">Failed to load personnel: {(query.error as Error).message}</div>}
        {query.data && query.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState icon={<UserCog size={28} />} title="No personnel yet" description="Add field personnel to assign them to campaigns and locations." />
          </div>
        )}
        {query.data && query.data.rows.length > 0 && (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Name</th>
                    <th className="px-4 py-3">Code</th>
                    <th className="px-4 py-3">Type</th>
                    <th className="px-4 py-3">Region</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.data.rows.map((p) => (
                    <tr key={p.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3 font-medium text-slate-800">{p.full_name}</td>
                      <td className="px-4 py-3 text-slate-600">{p.agent_code ?? "—"}</td>
                      <td className="px-4 py-3 text-slate-600">{p.personnel_type.replace("_", " ")}</td>
                      <td className="px-4 py-3 text-slate-600">{p.region ?? "—"}</td>
                      <td className="px-4 py-3"><Badge value={p.status} /></td>
                      <td className="px-4 py-3 text-right">
                        <Button size="sm" variant="secondary" onClick={() => { setEditing(p); setModalOpen(true); }}>Edit</Button>
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

      <PersonnelFormModal open={modalOpen} onClose={() => setModalOpen(false)} initial={editing} />
    </div>
  );
}