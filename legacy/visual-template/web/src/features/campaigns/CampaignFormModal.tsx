import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { createCampaign, updateCampaign } from "@/services/campaigns.service";
import { listClients } from "@/services/clients.service";
import { useToast } from "@/hooks/useToast";
import type { Campaign } from "@/types/domain";

const schema = z.object({
  client_id: z.string().uuid("Select a client"),
  name: z.string().min(2),
  code: z.string().optional().or(z.literal("")),
  campaign_type: z.string().optional().or(z.literal("")),
  description: z.string().optional().or(z.literal("")),
  objectives: z.string().optional().or(z.literal("")),
  budget: z.coerce.number().nonnegative().optional().or(z.nan()),
  start_date: z.string().optional().or(z.literal("")),
  end_date: z.string().optional().or(z.literal("")),
  status: z.enum(["draft","planned","active","paused","completed","cancelled"]),
});
type FormValues = z.infer<typeof schema>;

export function CampaignFormModal({
  open, onClose, initial,
}: { open: boolean; onClose: () => void; initial?: Campaign | null }) {
  const qc = useQueryClient();
  const toast = useToast();

  const clients = useQuery({ queryKey: ["clients", "options"], queryFn: () => listClients({ pageSize: 500 }) });

  const { register, handleSubmit, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? {
          client_id: initial.client_id, name: initial.name, code: initial.code ?? "",
          campaign_type: initial.campaign_type ?? "", description: initial.description ?? "",
          objectives: initial.objectives ?? "", budget: initial.budget ?? undefined,
          start_date: initial.start_date ?? "", end_date: initial.end_date ?? "",
          status: initial.status,
        }
      : {
          client_id: "", name: "", code: "", campaign_type: "", description: "",
          objectives: "", start_date: "", end_date: "", status: "draft",
        },
  });

  const mutation = useMutation({
    mutationFn: async (v: FormValues) => {
      const payload = { ...v, budget: Number.isFinite(v.budget) ? v.budget : null };
      return initial ? updateCampaign(initial.id, payload as any) : createCampaign(payload as any);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["campaigns"] });
      toast.push("success", initial ? "Campaign updated" : "Campaign created");
      onClose();
    },
    onError: (e: Error) => toast.push("error", e.message || "Failed to save campaign"),
  });

  return (
    <Modal open={open} onClose={onClose} title={initial ? "Edit campaign" : "New campaign"} size="lg">
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Select label="Client *" {...register("client_id")} error={errors.client_id?.message}>
          <option value="">Select client…</option>
          {clients.data?.rows.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </Select>
        <Input label="Campaign name *" {...register("name")} error={errors.name?.message} />
        <Input label="Code" {...register("code")} />
        <Input label="Type" placeholder="e.g. Visibility, Activation" {...register("campaign_type")} />
        <Input label="Start date" type="date" {...register("start_date")} />
        <Input label="End date" type="date" {...register("end_date")} />
        <Input label="Budget" type="number" step="0.01" {...register("budget")} />
        <Select label="Status" {...register("status")}>
          {["draft","planned","active","paused","completed","cancelled"].map((s) => <option key={s} value={s}>{s}</option>)}
        </Select>
        <div className="md:col-span-2"><Textarea label="Description" {...register("description")} /></div>
        <div className="md:col-span-2"><Textarea label="Objectives" {...register("objectives")} /></div>
        <div className="md:col-span-2 flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Save</Button>
        </div>
      </form>
    </Modal>
  );
}