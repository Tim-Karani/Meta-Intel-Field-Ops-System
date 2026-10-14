import { useProfile } from "@/hooks/useProfile";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { PageHeader } from "@/components/shared/PageHeader";

export function SettingsPage() {
  const { data: profile } = useProfile();

  return (
    <div className="space-y-4">
      <PageHeader title="Settings" description="Account and system information." />
      <Card>
        <CardHeader title="Profile" subtitle="Your account details" />
        <CardBody className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
          <div>
            <div className="text-xs uppercase tracking-wide text-slate-500">Email</div>
            <div className="text-slate-800">{profile?.email ?? "—"}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-slate-500">Full name</div>
            <div className="text-slate-800">{profile?.full_name ?? "—"}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-slate-500">Role</div>
            <div className="text-slate-800">{profile?.role ?? "—"}</div>
          </div>
          <div>
            <div className="text-xs uppercase tracking-wide text-slate-500">Status</div>
            <div className="text-slate-800">{profile?.status ?? "—"}</div>
          </div>
        </CardBody>
      </Card>
    </div>
  );
}