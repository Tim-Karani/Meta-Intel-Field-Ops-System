import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { createAssignment } from "@/services/assignments.service";
import { listCampaigns } from "@/services/campaigns.service";
import { listPersonnel } from "@/services/personnel.service";
import { listLocations } from "@/services/locations.service";
import { supabase } from "@/lib/supabase";
import { useToast } from "@/hooks/useToast";

const schema = z.object({
  personnel_id: z.string().uuid(),
  campaign_id: z.string().uuid(),
  activity_id: z.string().uuid().nullable().optional(),
  location_id: z.string().uuid().nullable().optional(),
  supervisor_id: z.string().uuid().nullable().optional(),
  scheduled_date: z.string().min(1),
  start_time: z.string().optional(),
  end_time: z.string().optional(),
  notes: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export function AssignmentFormModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();

  const campaigns = useQuery({ queryKey: ["campaigns", "options"], queryFn: () => listCampaigns({ pageSize: 500 }) });
  const personnel = useQuery({ queryKey: ["personnel", "options"], queryFn: () => listPersonnel({ pageSize: 500 }) });
  const locations = useQuery({ queryKey: ["locations", "options"], queryFn: () => listLocations({ pageSize: 500 }) });

  const { register, handleSubmit, watch, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      personnel_id: "", campaign_id: "", activity_id: null, location_id: null,
      supervisor_id: null, scheduled_date: new Date().toISOString().slice(0, 10),
      start_time: "", end_time: "", notes: "",
    },
  });

  const selectedCampaign = watch("campaign_id");

  const activities = useQuery({
    queryKey: ["campaign-activities", selectedCampaign],
    enabled: !!selectedCampaign,
    queryFn: async () => {
      const { data, error } = await supabase
        .from("activities").select("id,name").eq("campaign_id", selectedCampaign);
      if (error) throw error;
      return data ?? [];
    },
  });

  const mutation = useMutation({
    mutationFn: (v: FormValues) => createAssignment(v as any),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["assignments"] });
      toast.push("success", "Assignment created");
      onClose();
    },
    onError: (e: Error) => toast.push("error", e.message || "Failed to create assignment"),
  });

  return (
    <Modal open={open} onClose={onClose} title="New assignment" size="lg">
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Select label="Personnel *" {...register("personnel_id")} error={errors.personnel_id?.message}>
          <option value="">Select personnel…</option>
          {personnel.data?.rows.map((p) => <option key={p.id} value={p.id}>{p.full_name} ({p.agent_code ?? p.personnel_type})</option>)}
        </Select>
        <Select label="Campaign *" {...register("campaign_id")} error={errors.campaign_id?.message}>
          <option value="">Select campaign…</option>
          {campaigns.data?.rows.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </Select>
        <Select label="Activity" {...register("activity_id")} disabled={!selectedCampaign}>
          <option value="">None</option>
          {activities.data?.map((a: any) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </Select>
        <Select label="Location" {...register("location_id")}>
          <option value="">None</option>
          {locations.data?.rows.map((l: any) => <option key={l.id} value={l.id}>{l.name}</option>)}
        </Select>
        <Input label="Scheduled date *" type="date" {...register("scheduled_date")} error={errors.scheduled_date?.message} />
        <Input label="Start time" type="time" {...register("start_time")} />
        <Input label="End time" type="time" {...register("end_time")} />
        <div className="md:col-span-2"><Textarea label="Notes" {...register("notes")} /></div>
        <div className="md:col-span-2 flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Save</Button>
        </div>
      </form>
    </Modal>
  );
}