import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Plus, Users2 } from "lucide-react";
import { listTeams } from "@/services/teams.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";
import { Badge } from "@/components/ui/Badge";
import { TeamFormModal } from "./TeamFormModal";
import type { Team } from "@/types/domain";

export function TeamsListPage() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<string | "all">("all");
  const [page, setPage] = useState(1);
  const pageSize = 10;
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Team | null>(null);

  const query = useQuery({
    queryKey: ["teams", { search, status, page }],
    queryFn: () => listTeams({ search, page, pageSize }),
  });

  // Filter by status client-side for now (listTeams doesn't yet support status filter)
  const rows = (query.data?.rows ?? []).filter((t: any) =>
    status === "all" ? true : t.status === status
  );

  return (
    <div className="space-y-4">
      <PageHeader
        title="Field Teams"
        description="Groups of field personnel led by a supervisor, working regions and campaigns."
        actions={
          <Button onClick={() => { setEditing(null); setModalOpen(true); }}>
            <Plus size={16} /> New team
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3">
        <Input
          placeholder="Search by team name"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
        />
        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="all">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="archived">Archived</option>
        </Select>
      </div>

      <div className="rounded-lg border border-slate-200 bg-white">
        {query.isLoading && (
          <div className="p-4"><TableSkeleton rows={6} cols={4} /></div>
        )}

        {query.isError && (
          <div className="p-6 text-sm text-red-600">
            Failed to load teams: {(query.error as Error).message}
          </div>
        )}

        {query.data && rows.length === 0 && (
          <div className="p-6">
            <EmptyState
              icon={<Users2 size={28} />}
              title="No teams yet"
              description="Create a team, assign a supervisor, and later add field personnel to it."
              action={
                <Button onClick={() => { setEditing(null); setModalOpen(true); }}>
                  <Plus size={16} /> New team
                </Button>
              }
            />
          </div>
        )}

        {query.data && rows.length > 0 && (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Team</th>
                    <th className="px-4 py-3">Supervisor</th>
                    <th className="px-4 py-3">Region</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((t: any) => (
                    <tr key={t.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-800">{t.name}</div>
                        {t.description && (
                          <div className="text-xs text-slate-500">{t.description}</div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {t.supervisor?.full_name ?? "—"}
                      </td>
                      <td className="px-4 py-3 text-slate-600">{t.region ?? "—"}</td>
                      <td className="px-4 py-3"><Badge value={t.status} /></td>
                      <td className="px-4 py-3 text-right">
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => { setEditing(t); setModalOpen(true); }}
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

      <TeamFormModal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        initial={editing}
      />
    </div>
  );
}