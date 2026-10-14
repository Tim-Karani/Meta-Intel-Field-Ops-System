import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { createTeam, updateTeam } from "@/services/teams.service";
import { listPersonnel } from "@/services/personnel.service";
import { useToast } from "@/hooks/useToast";
import type { Team } from "@/types/domain";

const schema = z.object({
  name: z.string().min(2, "Team name is required"),
  supervisor_id: z.string().uuid().nullable().optional().or(z.literal("")),
  region: z.string().optional().or(z.literal("")),
  description: z.string().optional().or(z.literal("")),
  status: z.enum(["active", "inactive", "archived"]),
});
type FormValues = z.infer<typeof schema>;

export function TeamFormModal({
  open,
  onClose,
  initial,
}: {
  open: boolean;
  onClose: () => void;
  initial?: Team | null;
}) {
  const qc = useQueryClient();
  const toast = useToast();

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
          name: initial.name,
          supervisor_id: initial.supervisor_id ?? "",
          region: initial.region ?? "",
          description: initial.description ?? "",
          status: initial.status,
        }
      : {
          name: "",
          supervisor_id: "",
          region: "",
          description: "",
          status: "active",
        },
  });

  const mutation = useMutation({
    mutationFn: async (v: FormValues) => {
      const payload: any = { ...v, supervisor_id: v.supervisor_id || null };
      return initial ? updateTeam(initial.id, payload) : createTeam(payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["teams"] });
      toast.push("success", initial ? "Team updated" : "Team created");
      onClose();
    },
    onError: (e: Error) =>
      toast.push("error", e.message || "Failed to save team"),
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={initial ? "Edit team" : "New team"}
      size="lg"
    >
      <form
        onSubmit={handleSubmit((v) => mutation.mutate(v))}
        className="grid grid-cols-1 gap-4 md:grid-cols-2"
      >
        <Input
          label="Team name *"
          {...register("name")}
          error={errors.name?.message}
        />

        <Select label="Supervisor" {...register("supervisor_id")}>
          <option value="">None</option>
          {supervisors.data?.map((s) => (
            <option key={s.id} value={s.id}>{s.full_name}</option>
          ))}
        </Select>

        <Input label="Region" {...register("region")} />

        <Select label="Status" {...register("status")}>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="archived">Archived</option>
        </Select>

        <div className="md:col-span-2">
          <Textarea label="Description" {...register("description")} />
        </div>

        <div className="md:col-span-2 flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={mutation.isPending}>Save</Button>
        </div>
      </form>
    </Modal>
  );
}