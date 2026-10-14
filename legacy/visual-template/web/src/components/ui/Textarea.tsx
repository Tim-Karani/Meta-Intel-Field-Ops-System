// Textarea.tsx
import { TextareaHTMLAttributes, forwardRef } from "react";
import clsx from "clsx";

interface Props extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
}

export const Textarea = forwardRef<HTMLTextAreaElement, Props>(function Textarea(
  { label, error, className, id, ...rest }, ref
) {
  const inputId = id ?? rest.name;
  return (
    <div className="space-y-1">
      {label && (
        <label htmlFor={inputId} className="block text-sm font-medium text-slate-700">
          {label}
        </label>
      )}
      <textarea
        id={inputId}
        ref={ref}
        className={clsx(
          "w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 min-h-[80px]",
          "focus:outline-none focus:ring-2 focus:ring-teal-500/40 focus:border-teal-500",
          error ? "border-red-400" : "border-slate-300",
          className
        )}
        {...rest}
      />
      {error && <p className="text-xs text-red-600">{error}</p>}
    </div>
  );
});