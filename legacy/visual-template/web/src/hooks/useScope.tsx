import { createContext, useContext, useEffect, useMemo, useState, ReactNode } from "react";

export interface Scope {
  clientId: string | null;
  campaignId: string | null;
}

interface ScopeContextValue {
  scope: Scope;
  setScope: (next: Partial<Scope>) => void;
  reset: () => void;
}

const ScopeContext = createContext<ScopeContextValue | null>(null);

const STORAGE_KEY = "meta-intel.scope";

export function ScopeProvider({ children }: { children: ReactNode }) {
  const [scope, setScopeState] = useState<Scope>(() => {
    // Restore from localStorage so the scope survives page reloads
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === "object") {
          return {
            clientId: parsed.clientId ?? null,
            campaignId: parsed.campaignId ?? null,
          };
        }
      }
    } catch {
      // ignore — fall through to default
    }
    return { clientId: null, campaignId: null };
  });

  // Persist to localStorage on every change
  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(scope));
    } catch {
      // localStorage might be disabled (private mode, quota) — ignore
    }
  }, [scope]);

  const value = useMemo<ScopeContextValue>(
    () => ({
      scope,
      setScope: (next) =>
        setScopeState((prev) => ({
          // If client changes, clear the campaign (it may not belong to the new client)
          campaignId:
            next.clientId !== undefined && next.clientId !== prev.clientId
              ? null
              : next.campaignId !== undefined
              ? next.campaignId
              : prev.campaignId,
          clientId: next.clientId !== undefined ? next.clientId : prev.clientId,
        })),
      reset: () => setScopeState({ clientId: null, campaignId: null }),
    }),
    [scope]
  );

  return <ScopeContext.Provider value={value}>{children}</ScopeContext.Provider>;
}

export function useScope() {
  const ctx = useContext(ScopeContext);
  if (!ctx) throw new Error("useScope must be used inside <ScopeProvider>");
  return ctx;
}