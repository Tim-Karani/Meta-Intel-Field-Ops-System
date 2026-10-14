import { PageHeader } from "@/components/shared/PageHeader";
import { EmptyState } from "@/components/ui/EmptyState";

export function PlaceholderPage({ title, note }: { title: string; note: string }) {
  return (
    <div className="space-y-4">
      <PageHeader title={title} />
      <EmptyState title={`${title} module`} description={note} />
    </div>
  );
}