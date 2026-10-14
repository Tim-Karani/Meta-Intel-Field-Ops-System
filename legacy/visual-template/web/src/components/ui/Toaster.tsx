import { CheckCircle2, AlertCircle, Info, X } from "lucide-react";
import { useToast } from "@/hooks/useToast";

export function Toaster() {
  const { toasts, dismiss } = useToast();
  return (
    <div className="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-80 flex-col gap-2">
      {toasts.map((t) => (
        <div
          key={t.id}
          className={`pointer-events-auto flex items-start gap-2 rounded-md border bg-white p-3 shadow-lg ${
            t.kind === "success" ? "border-teal-200" : t.kind === "error" ? "border-red-200" : "border-slate-200"
          }`}
        >
          {t.kind === "success" && <CheckCircle2 size={16} className="mt-0.5 text-teal-600" />}
          {t.kind === "error" && <AlertCircle size={16} className="mt-0.5 text-red-600" />}
          {t.kind === "info" && <Info size={16} className="mt-0.5 text-slate-500" />}
          <p className="flex-1 text-sm text-slate-700">{t.message}</p>
          <button onClick={() => dismiss(t.id)} className="text-slate-400 hover:text-slate-600">
            <X size={14} />
          </button>
        </div>
      ))}
    </div>
  );
}