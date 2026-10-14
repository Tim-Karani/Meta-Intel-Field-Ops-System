import clsx from "clsx";

const styles: Record<string, string> = {
  active: "bg-teal-50 text-teal-700 border-teal-200",
  inactive: "bg-slate-100 text-slate-600 border-slate-200",
  archived: "bg-slate-100 text-slate-500 border-slate-200",
  draft: "bg-slate-100 text-slate-600 border-slate-200",
  planned: "bg-blue-50 text-blue-700 border-blue-200",
  paused: "bg-amber-50 text-amber-700 border-amber-200",
  completed: "bg-teal-50 text-teal-700 border-teal-200",
  cancelled: "bg-red-50 text-red-700 border-red-200",
  scheduled: "bg-blue-50 text-blue-700 border-blue-200",
  in_progress: "bg-amber-50 text-amber-700 border-amber-200",
  missed: "bg-red-50 text-red-700 border-red-200",
  open: "bg-blue-50 text-blue-700 border-blue-200",
  escalated: "bg-red-50 text-red-700 border-red-200",
  resolved: "bg-teal-50 text-teal-700 border-teal-200",
  closed: "bg-slate-100 text-slate-600 border-slate-200",
};

export function Badge({ value, className }: { value: string; className?: string }) {
  const cls = styles[value] ?? "bg-slate-100 text-slate-600 border-slate-200";
  return (
    <span className={clsx("inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium capitalize", cls, className)}>
      {value.replace("_", " ")}
    </span>
  );
}