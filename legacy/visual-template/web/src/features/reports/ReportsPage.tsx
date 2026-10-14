import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { BarChart3, Download, FileSpreadsheet } from "lucide-react";
import { runReport, exportToCsv } from "@/services/reports.service";
import type { ReportType, ReportFilters } from "@/services/reports.service";
import { listCampaigns } from "@/services/campaigns.service";
import { listPersonnel } from "@/services/personnel.service";
import { listLocations } from "@/services/locations.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { EmptyState } from "@/components/ui/EmptyState";
import { TableSkeleton } from "@/components/ui/Skeleton";
import { useToast } from "@/hooks/useToast";

const REPORTS: { value: ReportType; label: string; available: boolean }[] = [
  { value: "assignments", label: "Assignments", available: true },
  { value: "issues", label: "Issues", available: true },
  { value: "attendance", label: "Attendance", available: false },
  { value: "visits", label: "Visits", available: false },
  { value: "sales", label: "Sales", available: false },
  { value: "osa", label: "OSA / Retail Execution", available: false },
];

export function ReportsPage() {
  const [type, setType] = useState<ReportType>("assignments");
  const [filters, setFilters] = useState<ReportFilters>({});
  const [runKey, setRunKey] = useState(0); // increment to trigger a rerun

  const toast = useToast();

  const campaigns = useQuery({
    queryKey: ["campaigns", "options"],
    queryFn: () => listCampaigns({ pageSize: 500 }),
  });
  const personnel = useQuery({
    queryKey: ["personnel", "options"],
    queryFn: () => listPersonnel({ pageSize: 500 }),
  });
  const locations = useQuery({
    queryKey: ["locations", "options"],
    queryFn: () => listLocations({ pageSize: 500 }),
  });

  const reportQuery = useQuery({
    queryKey: ["report", type, filters, runKey],
    queryFn: () => runReport(type, filters),
    enabled: runKey > 0, // only run when user clicks "Run report"
  });

  function handleRun() {
    setRunKey((k) => k + 1);
  }

  function handleExport() {
    if (!reportQuery.data) return;
    const filename = `${type}-report-${new Date().toISOString().slice(0, 10)}.csv`;
    exportToCsv(filename, reportQuery.data);
    toast.push("success", `Exported ${reportQuery.data.rows.length} rows`);
  }

  const selectedReport = REPORTS.find((r) => r.value === type);
  const isAvailable = selectedReport?.available ?? false;

  return (
    <div className="space-y-4">
      <PageHeader
        title="Reports"
        description="Filter and export operational data as CSV. Reports are read-only and pull directly from the live database."
      />

      {/* Report selector + filters */}
      <div className="rounded-lg border border-slate-200 bg-white p-4 space-y-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Select
            label="Report"
            value={type}
            onChange={(e) => {
              setType(e.target.value as ReportType);
              setFilters({});
              setRunKey(0);
            }}
          >
            {REPORTS.map((r) => (
              <option key={r.value} value={r.value} disabled={!r.available}>
                {r.label}{!r.available ? " (coming soon)" : ""}
              </option>
            ))}
          </Select>

          <Input
            label="From date"
            type="date"
            value={filters.from ?? ""}
            onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value || undefined }))}
          />
          <Input
            label="To date"
            type="date"
            value={filters.to ?? ""}
            onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value || undefined }))}
          />

          <Select
            label="Status"
            value={filters.status ?? ""}
            onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value || undefined }))}
          >
            <option value="">All</option>
            {type === "assignments" && (
              <>
                <option value="scheduled">scheduled</option>
                <option value="in_progress">in_progress</option>
                <option value="completed">completed</option>
                <option value="missed">missed</option>
                <option value="cancelled">cancelled</option>
              </>
            )}
            {type === "issues" && (
              <>
                <option value="open">open</option>
                <option value="in_progress">in_progress</option>
                <option value="escalated">escalated</option>
                <option value="resolved">resolved</option>
                <option value="closed">closed</option>
              </>
            )}
          </Select>

          <Select
            label="Campaign"
            value={filters.campaignId ?? ""}
            onChange={(e) => setFilters((f) => ({ ...f, campaignId: e.target.value || undefined }))}
          >
            <option value="">All campaigns</option>
            {campaigns.data?.rows.map((c) => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </Select>

          <Select
            label={type === "issues" ? "Assigned to" : "Personnel"}
            value={filters.personnelId ?? ""}
            onChange={(e) => setFilters((f) => ({ ...f, personnelId: e.target.value || undefined }))}
          >
            <option value="">All personnel</option>
            {personnel.data?.rows.map((p) => (
              <option key={p.id} value={p.id}>{p.full_name}</option>
            ))}
          </Select>

          <Select
            label="Location"
            value={filters.locationId ?? ""}
            onChange={(e) => setFilters((f) => ({ ...f, locationId: e.target.value || undefined }))}
          >
            <option value="">All locations</option>
            {locations.data?.rows.map((l: any) => (
              <option key={l.id} value={l.id}>{l.name}</option>
            ))}
          </Select>

          <div className="flex items-end gap-2">
            <Button onClick={handleRun} disabled={!isAvailable} className="flex-1">
              <BarChart3 size={16} /> Run report
            </Button>
          </div>
        </div>

        {!isAvailable && (
          <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
            This report is not yet available. It will be enabled once the underlying data module is built.
          </p>
        )}
      </div>

      {/* Results */}
      <div className="rounded-lg border border-slate-200 bg-white">
        {runKey === 0 && (
          <div className="p-6">
            <EmptyState
              icon={<FileSpreadsheet size={28} />}
              title="No report run yet"
              description="Choose a report, set your filters, then click Run report."
            />
          </div>
        )}

        {reportQuery.isLoading && <div className="p-4"><TableSkeleton rows={8} cols={6} /></div>}

        {reportQuery.isError && (
          <div className="p-6 text-sm text-red-600">
            Failed to run report: {(reportQuery.error as Error).message}
          </div>
        )}

        {reportQuery.data && reportQuery.data.rows.length === 0 && (
          <div className="p-6">
            <EmptyState
              title="No rows match your filters"
              description="Try widening the date range or removing a filter."
            />
          </div>
        )}

        {reportQuery.data && reportQuery.data.rows.length > 0 && (
          <>
            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
              <div className="text-sm text-slate-600">
                <span className="font-medium text-slate-800">{reportQuery.data.rows.length}</span> rows
                {reportQuery.data.rows.length >= 2000 && <span className="ml-2 text-amber-700">(capped at 2000)</span>}
              </div>
              <Button variant="secondary" size="sm" onClick={handleExport}>
                <Download size={14} /> Export CSV
              </Button>
            </div>

            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    {reportQuery.data.columns.map((c) => (
                      <th key={c.key} className="px-4 py-3 whitespace-nowrap">{c.label}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {reportQuery.data.rows.map((row, i) => (
                    <tr key={i} className="hover:bg-slate-50">
                      {reportQuery.data.columns.map((c) => (
                        <td key={c.key} className="px-4 py-2 text-slate-700 whitespace-nowrap">
                          {row[c.key] ?? ""}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </div>
    </div>
  );
}