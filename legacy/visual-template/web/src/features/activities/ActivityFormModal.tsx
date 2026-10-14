import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { createActivity, updateActivity } from "@/services/activities.service";
import { listCampaigns } from "@/services/campaigns.service";
import { listPersonnel } from "@/services/personnel.service";
import { useToast } from "@/hooks/useToast";
import type { Activity } from "@/types/domain";

const schema = z.object({
  campaign_id: z.string().uuid("Select a campaign"),
  name: z.string().min(2, "Name is required"),
  activity_type: z.string().optional().or(z.literal("")),
  description: z.string().optional().or(z.literal("")),
  target: z.coerce.number().int().nonnegative().optional().or(z.nan()),
  start_date: z.string().optional().or(z.literal("")),
  end_date: z.string().optional().or(z.literal("")),
  status: z.enum(["draft", "planned", "active", "paused", "completed", "cancelled"]),
  supervisor_id: z.string().uuid().nullable().optional().or(z.literal("")),
});
type FormValues = z.infer<typeof schema>;

export function ActivityFormModal({
  open,
  onClose,
  initial,
}: {
  open: boolean;
  onClose: () => void;
  initial?: Activity | null;
}) {
  const qc = useQueryClient();
  const toast = useToast();

  const campaigns = useQuery({
    queryKey: ["campaigns", "options"],
    queryFn: () => listCampaigns({ pageSize: 500 }),
  });

  const supervisors = useQuery({
    queryKey: ["personnel", "supervisors"],
    queryFn: async () => {
      const { rows } = await listPersonnel({ pageSize: 500 });
      return rows.filter((p) => p.personnel_type === "SUPERVISOR");
    },
  });

  const { register, handleSubmit, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? {
          campaign_id: initial.campaign_id,
          name: initial.name,
          activity_type: initial.activity_type ?? "",
          description: initial.description ?? "",
          target: initial.target ?? undefined,
          start_date: initial.start_date ?? "",
          end_date: initial.end_date ?? "",
          status: initial.status,
          supervisor_id: initial.supervisor_id ?? "",
        }
      : {
          campaign_id: "",
          name: "",
          activity_type: "",
          description: "",
          start_date: "",
          end_date: "",
          status: "draft",
          supervisor_id: "",
        },
  });

  const mutation = useMutation({
    mutationFn: async (v: FormValues) => {
      const payload: any = {
        ...v,
        target: Number.isFinite(v.target) ? v.target : null,
        supervisor_id: v.supervisor_id || null,
      };
      return initial
        ? updateActivity(initial.id, payload)
        : createActivity(payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["activities"] });
      qc.invalidateQueries({ queryKey: ["campaign-activities"] });
      toast.push("success", initial ? "Activity updated" : "Activity created");
      onClose();
    },
    onError: (e: Error) =>
      toast.push("error", e.message || "Failed to save activity"),
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={initial ? "Edit activity" : "New activity"}
      size="lg"
    >
      <form
        onSubmit={handleSubmit((v) => mutation.mutate(v))}
        className="grid grid-cols-1 gap-4 md:grid-cols-2"
      >
        <Select
          label="Campaign *"
          {...register("campaign_id")}
          error={errors.campaign_id?.message}
        >
          <option value="">Select campaign…</option>
          {campaigns.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </Select>

        <Input
          label="Activity name *"
          {...register("name")}
          error={errors.name?.message}
        />

        <Input
          label="Type"
          placeholder="Merchandising, Activation, Audit…"
          {...register("activity_type")}
        />

        <Input
          label="Target"
          type="number"
          step="1"
          {...register("target")}
        />

        <Input label="Start date" type="date" {...register("start_date")} />
        <Input label="End date" type="date" {...register("end_date")} />

        <Select label="Status" {...register("status")}>
          {["draft", "planned", "active", "paused", "completed", "cancelled"].map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </Select>

        <Select label="Supervisor" {...register("supervisor_id")}>
          <option value="">None</option>
          {supervisors.data?.map((s) => (
            <option key={s.id} value={s.id}>
              {s.full_name}
            </option>
          ))}
        </Select>

        <div className="md:col-span-2">
          <Textarea label="Description" {...register("description")} />
        </div>

        <div className="md:col-span-2 flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={mutation.isPending}>
            Save
          </Button>
        </div>
      </form>
    </Modal>
  );
}