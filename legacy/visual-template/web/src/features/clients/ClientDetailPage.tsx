import { useParams, Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { getClient } from "@/services/clients.service";
import { supabase } from "@/lib/supabase";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/shared/PageHeader";

export function ClientDetailPage() {
  const { id = "" } = useParams();

  const client = useQuery({
    queryKey: ["client", id],
    enabled: !!id,
    queryFn: () => getClient(id),
  });

  const campaigns = useQuery({
    queryKey: ["client-campaigns", id],
    enabled: !!id,
    queryFn: async () => {
      const { data, error } = await supabase
        .from("campaigns")
        .select("id,name,status,start_date,end_date")
        .eq("client_id", id)
        .order("created_at", { ascending: false });
      if (error) throw error;
      return data ?? [];
    },
  });

  if (client.isLoading) return <Skeleton className="h-40 w-full" />;
  if (client.isError || !client.data) return <p className="text-sm text-red-600">Client not found.</p>;

  const c = client.data;

  return (
    <div className="space-y-4">
      <PageHeader title={c.name} description={c.company_name ?? undefined} />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Client details" />
          <CardBody className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
            <Field label="Contact person" value={c.contact_person} />
            <Field label="Email" value={c.email} />
            <Field label="Phone" value={c.phone} />
            <Field label="Industry" value={c.industry} />
            <Field label="Address" value={c.address} />
            <Field label="Status" value={<Badge value={c.status} />} />
            {c.notes && <div className="sm:col-span-2"><Field label="Notes" value={c.notes} /></div>}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Campaigns" subtitle={`${campaigns.data?.length ?? 0} total`} />
          <CardBody>
            {campaigns.isLoading && <Skeleton className="h-24 w-full" />}
            {campaigns.data && campaigns.data.length === 0 && (
              <EmptyState title="No campaigns yet" description="Campaigns assigned to this client will appear here." />
            )}
            {campaigns.data && campaigns.data.length > 0 && (
              <ul className="divide-y divide-slate-100 text-sm">
                {campaigns.data.map((cmp) => (
                  <li key={cmp.id} className="flex items-center justify-between py-2">
                    <Link to={`/campaigns/${cmp.id}`} className="font-medium text-navy-800 hover:underline">
                      {cmp.name}
                    </Link>
                    <Badge value={cmp.status} />
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