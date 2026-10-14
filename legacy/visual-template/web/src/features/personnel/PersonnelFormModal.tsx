import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { createPersonnel, updatePersonnel } from "@/services/personnel.service";
import { supabase } from "@/lib/supabase";
import { useToast } from "@/hooks/useToast";
import type { Personnel, PersonnelType } from "@/types/domain";

const TYPES: PersonnelType[] = [
  "MERCHANDISER","BRAND_AMBASSADOR","PROMOTER","FIELD_REPRESENTATIVE","SUPERVISOR","COORDINATOR",
];

const schema = z.object({
  full_name: z.string().min(2),
  phone: z.string().optional().or(z.literal("")),
  email: z.string().email().optional().or(z.literal("")),
  personnel_type: z.enum(TYPES as [PersonnelType, ...PersonnelType[]]),
  agent_code: z.string().optional().or(z.literal("")),
  region: z.string().optional().or(z.literal("")),
  status: z.enum(["active","inactive","archived"]),
  supervisor_id: z.string().uuid().nullable().optional().or(z.literal("")),
});
type FormValues = z.infer<typeof schema>;

export function PersonnelFormModal({
  open, onClose, initial,
}: { open: boolean; onClose: () => void; initial?: Personnel | null }) {
  const qc = useQueryClient();
  const toast = useToast();

  const supervisors = useQuery({
    queryKey: ["personnel", "supervisors"],
    queryFn: async () => {
      const { data, error } = await supabase
        .from("personnel").select("id,full_name")
        .eq("personnel_type", "SUPERVISOR").eq("status", "active");
      if (error) throw error;
      return data ?? [];
    },
  });

  const { register, handleSubmit, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? { ...initial, phone: initial.phone ?? "", email: initial.email ?? "",
          agent_code: initial.agent_code ?? "", region: initial.region ?? "",
          supervisor_id: initial.supervisor_id ?? "" }
      : { full_name: "", phone: "", email: "", personnel_type: "MERCHANDISER",
          agent_code: "", region: "", status: "active", supervisor_id: "" },
  });

  const mutation = useMutation({
    mutationFn: async (v: FormValues) => {
      const payload: any = { ...v, supervisor_id: v.supervisor_id || null };
      return initial ? updatePersonnel(initial.id, payload) : createPersonnel(payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["personnel"] });
      toast.push("success", initial ? "Personnel updated" : "Personnel created");
      onClose();
    },
    onError: (e: Error) => toast.push("error", e.message || "Failed to save personnel"),
  });

  return (
    <Modal open={open} onClose={onClose} title={initial ? "Edit personnel" : "New personnel"} size="lg">
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Input label="Full name *" {...register("full_name")} error={errors.full_name?.message} />
        <Input label="Agent code" {...register("agent_code")} />
        <Input label="Phone" {...register("phone")} />
        <Input label="Email" type="email" {...register("email")} error={errors.email?.message} />
        <Select label="Type *" {...register("personnel_type")}>
          {TYPES.map((t) => <option key={t} value={t}>{t.replace("_", " ")}</option>)}
        </Select>
        <Input label="Region" {...register("region")} />
        <Select label="Supervisor" {...register("supervisor_id")}>
          <option value="">None</option>
          {supervisors.data?.map((s: any) => <option key={s.id} value={s.id}>{s.full_name}</option>)}
        </Select>
        <Select label="Status" {...register("status")}>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="archived">Archived</option>
        </Select>
        <div className="md:col-span-2 flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Save</Button>
        </div>
      </form>
    </Modal>
  );
}