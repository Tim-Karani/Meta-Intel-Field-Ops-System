import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { createIssue, updateIssue } from "@/services/issues.service";
import { listCampaigns } from "@/services/campaigns.service";
import { listLocations } from "@/services/locations.service";
import { listPersonnel } from "@/services/personnel.service";
import { useToast } from "@/hooks/useToast";
import type { Issue } from "@/types/domain";

const schema = z.object({
  title: z.string().min(3, "Title is required"),
  description: z.string().optional().or(z.literal("")),
  category: z.string().optional().or(z.literal("")),
  priority: z.enum(["low", "medium", "high", "critical"]),
  status: z.enum(["open", "in_progress", "escalated", "resolved", "closed"]),
  campaign_id: z.string().uuid().nullable().optional().or(z.literal("")),
  location_id: z.string().uuid().nullable().optional().or(z.literal("")),
  reported_by: z.string().uuid().nullable().optional().or(z.literal("")),
  assigned_to: z.string().uuid().nullable().optional().or(z.literal("")),
  resolution: z.string().optional().or(z.literal("")),
});
type FormValues = z.infer<typeof schema>;

export function IssueFormModal({
  open, onClose, initial,
}: { open: boolean; onClose: () => void; initial?: Issue | null }) {
  const qc = useQueryClient();
  const toast = useToast();

  const campaigns = useQuery({
    queryKey: ["campaigns", "options"],
    queryFn: () => listCampaigns({ pageSize: 500 }),
  });
  const locations = useQuery({
    queryKey: ["locations", "options"],
    queryFn: () => listLocations({ pageSize: 500 }),
  });
  const personnel = useQuery({
    queryKey: ["personnel", "options"],
    queryFn: () => listPersonnel({ pageSize: 500 }),
  });

  const { register, handleSubmit, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? {
          title: initial.title,
          description: initial.description ?? "",
          category: initial.category ?? "",
          priority: initial.priority,
          status: initial.status,
          campaign_id: initial.campaign_id ?? "",
          location_id: initial.location_id ?? "",
          reported_by: initial.reported_by ?? "",
          assigned_to: initial.assigned_to ?? "",
          resolution: initial.resolution ?? "",
        }
      : {
          title: "",
          description: "",
          category: "",
          priority: "medium",
          status: "open",
          campaign_id: "",
          location_id: "",
          reported_by: "",
          assigned_to: "",
          resolution: "",
        },
  });

  const mutation = useMutation({
    mutationFn: async (v: FormValues) => {
      const payload: any = {
        ...v,
        campaign_id: v.campaign_id || null,
        location_id: v.location_id || null,
        reported_by: v.reported_by || null,
        assigned_to: v.assigned_to || null,
      };
      return initial ? updateIssue(initial.id, payload) : createIssue(payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["issues"] });
      toast.push("success", initial ? "Issue updated" : "Issue created");
      onClose();
    },
    onError: (e: Error) =>
      toast.push("error", e.message || "Failed to save issue"),
  });

  return (
    <Modal open={open} onClose={onClose} title={initial ? "Edit issue" : "New issue"} size="lg">
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Input label="Title *" {...register("title")} error={errors.title?.message} className="md:col-span-2" />

        <Input label="Category" placeholder="Stock, POSM, Competitor, Location…" {...register("category")} />

        <Select label="Priority" {...register("priority")}>
          {["low", "medium", "high", "critical"].map((p) => (
            <option key={p} value={p}>{p}</option>
          ))}
        </Select>

        <Select label="Status" {...register("status")}>
          {["open", "in_progress", "escalated", "resolved", "closed"].map((s) => (
            <option key={s} value={s}>{s.replace("_", " ")}</option>
          ))}
        </Select>

        <Select label="Campaign" {...register("campaign_id")}>
          <option value="">None</option>
          {campaigns.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </Select>

        <Select label="Location" {...register("location_id")}>
          <option value="">None</option>
          {locations.data?.rows.map((l: any) => (
            <option key={l.id} value={l.id}>{l.name}</option>
          ))}
        </Select>

        <Select label="Reported by" {...register("reported_by")}>
          <option value="">None</option>
          {personnel.data?.rows.map((p) => (
            <option key={p.id} value={p.id}>{p.full_name}</option>
          ))}
        </Select>

        <Select label="Assigned to" {...register("assigned_to")}>
          <option value="">Unassigned</option>
          {personnel.data?.rows.map((p) => (
            <option key={p.id} value={p.id}>{p.full_name}</option>
          ))}
        </Select>

        <div className="md:col-span-2">
          <Textarea label="Description" {...register("description")} />
        </div>

        {initial && (
          <div className="md:col-span-2">
            <Textarea label="Resolution" {...register("resolution")} placeholder="How the issue was resolved…" />
          </div>
        )}

        <div className="md:col-span-2 flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Save</Button>
        </div>
      </form>
    </Modal>
  );
}