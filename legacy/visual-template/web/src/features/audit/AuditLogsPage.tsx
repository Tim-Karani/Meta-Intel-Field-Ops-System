import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ScrollText } from "lucide-react";
import { listAuditEntityOptions, listAuditLogs } from "@/services/audit.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { Pagination } from "@/components/ui/Pagination";

const ACTIONS = ["all", "insert", "update", "delete"] as const;

export function AuditLogsPage() {
  const [entity, setEntity] = useState<string | "all">("all");
  const [action, setAction] = useState<string | "all">("all");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const pageSize = 25;

  const entityOptions = useQuery({
    queryKey: ["audit", "entities"],
    queryFn: listAuditEntityOptions,
  });

  const query = useQuery({
    queryKey: ["audit", { entity, action, from, to, page }],
    queryFn: () =>
      listAuditLogs({
        entity,
        action,
        from: from || undefined,
        to: to || undefined,
        page,
        pageSize,
      }),
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Audit Logs"
        description="Every insert, update and delete made by authenticated users. Read-only — records cannot be edited or removed from this interface."
      />

      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-4">
        <Select value={entity} onChange={(e) => { setEntity(e.target.value); setPage(1); }}>
          <option value="all">All entities</option>
          {entityOptions.data?.map((name) => (
            <option key={name} value={name}>{name}</option>
          ))}
        </Select>

        <Select value={action} onChange={(e) => { setAction(e.target.value); setPage(1); }}>
          {ACTIONS.map((a) => (
            <option key={a} value={a}>
              {a === "all" ? "All actions" : a}
            </option>
          ))}
        </Select>

        <Input
          type="date"
          value={from}
          onChange={(e) => { setFrom(e.target.value); setPage(1); }}
          placeholder="From date"
        />
        <Input
          type="date"
          value={to}
          onChange={(e) => { setTo(e.target.value); setPage(1); }}
          placeholder="To date"
        />
      </div>

      <div className="rounded-lg border border-slate-200 bg-white">
        {query.isLoading && <div className="p-4"><TableSkeleton rows={10} cols={5} /></div>}

        {query.isError && (
          <div className="p-6 text-sm text-red-600">
            Failed to load audit logs: {(query.error as Error).message}
          </div>
        )}

        {query.data && query.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState
              icon={<ScrollText size={28} />}
              title="No audit records match your filters"
              description="Adjust the filters above, or perform some actions in the app to generate audit entries."
            />
          </div>
        )}

        {query.data && query.data.rows.length > 0 && (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">When</th>
                    <th className="px-4 py-3">Action</th>
                    <th className="px-4 py-3">Entity</th>
                    <th className="px-4 py-3">Entity ID</th>
                    <th className="px-4 py-3">User</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.data.rows.map((row) => (
                    <tr key={row.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3 text-slate-700 whitespace-nowrap">
                        {new Date(row.created_at).toLocaleString()}
                      </td>
                      <td className="px-4 py-3">
                        <span
                          className={
                            "inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium capitalize " +
                            (row.action === "insert"
                              ? "bg-teal-50 text-teal-700 border-teal-200"
                              : row.action === "update"
                              ? "bg-amber-50 text-amber-700 border-amber-200"
                              : row.action === "delete"
                              ? "bg-red-50 text-red-700 border-red-200"
                              : "bg-slate-100 text-slate-600 border-slate-200")
                          }
                        >
                          {row.action}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-slate-700">{row.entity}</td>
                      <td className="px-4 py-3 text-slate-500 font-mono text-xs">
                        {row.entity_id ? row.entity_id.slice(0, 8) + "…" : "—"}
                      </td>
                      <td className="px-4 py-3 text-slate-600 font-mono text-xs">
                        {row.user_id ? row.user_id.slice(0, 8) + "…" : "system"}
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
    </div>
  );
}