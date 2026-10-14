import { useParams } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { supabase } from "@/lib/supabase";
import { getCampaign } from "@/services/campaigns.service";
import { PageHeader } from "@/components/shared/PageHeader";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";

export function CampaignDetailPage() {
  const { id = "" } = useParams();

  const campaign = useQuery({
    queryKey: ["campaign", id], enabled: !!id,
    queryFn: () => getCampaign(id),
  });

  const activities = useQuery({
    queryKey: ["campaign-activities", id], enabled: !!id,
    queryFn: async () => {
      const { data, error } = await supabase
        .from("activities").select("id,name,status,start_date,end_date,target")
        .eq("campaign_id", id).order("created_at", { ascending: false });
      if (error) throw error;
      return data ?? [];
    },
  });

  if (campaign.isLoading) return <Skeleton className="h-40 w-full" />;
  if (campaign.isError || !campaign.data) return <p className="text-sm text-red-600">Campaign not found.</p>;

  const c = campaign.data as any;

  return (
    <div className="space-y-4">
      <PageHeader title={c.name} description={c.client?.name} />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Campaign overview" />
          <CardBody className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
            <Field label="Code" value={c.code} />
            <Field label="Type" value={c.campaign_type} />
            <Field label="Start" value={c.start_date} />
            <Field label="End" value={c.end_date} />
            <Field label="Budget" value={c.budget} />
            <Field label="Status" value={<Badge value={c.status} />} />
            {c.description && <div className="sm:col-span-2"><Field label="Description" value={c.description} /></div>}
            {c.objectives && <div className="sm:col-span-2"><Field label="Objectives" value={c.objectives} /></div>}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Activities" subtitle={`${activities.data?.length ?? 0} total`} />
          <CardBody>
            {activities.isLoading && <Skeleton className="h-24 w-full" />}
            {activities.data && activities.data.length === 0 && (
              <EmptyState title="No activities yet" description="Add activities to this campaign to schedule field work." />
            )}
            {activities.data && activities.data.length > 0 && (
              <ul className="divide-y divide-slate-100 text-sm">
                {activities.data.map((a: any) => (
                  <li key={a.id} className="flex items-center justify-between py-2">
                    <div>
                      <div className="font-medium text-slate-800">{a.name}</div>
                      <div className="text-xs text-slate-500">Target: {a.target ?? "—"}</div>
                    </div>
                    <Badge value={a.status} />
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

function Field({ label, value }: { label: string; value: any }) {
  return (
    <div>
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className="text-sm text-slate-800">{value || "—"}</div>
    </div>
  );
}