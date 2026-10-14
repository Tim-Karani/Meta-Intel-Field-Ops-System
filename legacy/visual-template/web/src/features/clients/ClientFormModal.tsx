import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { Textarea } from "@/components/ui/Textarea";
import { createClient, updateClient } from "@/services/clients.service";
import { useToast } from "@/hooks/useToast";
import type { Client } from "@/types/domain";

const schema = z.object({
  name: z.string().min(2, "Name is required"),
  company_name: z.string().optional().or(z.literal("")),
  contact_person: z.string().optional().or(z.literal("")),
  email: z.string().email("Invalid email").optional().or(z.literal("")),
  phone: z.string().optional().or(z.literal("")),
  address: z.string().optional().or(z.literal("")),
  industry: z.string().optional().or(z.literal("")),
  status: z.enum(["active", "inactive", "archived"]),
  notes: z.string().optional().or(z.literal("")),
});
type FormValues = z.infer<typeof schema>;

export function ClientFormModal({
  open, onClose, initial,
}: { open: boolean; onClose: () => void; initial?: Client | null }) {
  const qc = useQueryClient();
  const toast = useToast();

  const { register, handleSubmit, formState: { errors, isSubmitting }, reset } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? { ...initial, company_name: initial.company_name ?? "", contact_person: initial.contact_person ?? "",
          email: initial.email ?? "", phone: initial.phone ?? "", address: initial.address ?? "",
          industry: initial.industry ?? "", notes: initial.notes ?? "" }
      : { status: "active", name: "", company_name: "", contact_person: "", email: "", phone: "",
          address: "", industry: "", notes: "" },
  });

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      const payload = { ...values };
      if (initial) return updateClient(initial.id, payload);
      return createClient(payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["clients"] });
      toast.push("success", initial ? "Client updated" : "Client created");
      reset();
      onClose();
    },
    onError: (e: Error) => toast.push("error", e.message || "Failed to save client"),
  });

  return (
    <Modal open={open} onClose={onClose} title={initial ? "Edit client" : "New client"} size="lg">
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Input label="Client name *" {...register("name")} error={errors.name?.message} />
        <Input label="Company name" {...register("company_name")} />
        <Input label="Contact person" {...register("contact_person")} />
        <Input label="Email" type="email" {...register("email")} error={errors.email?.message} />
        <Input label="Phone" {...register("phone")} />
        <Input label="Industry" {...register("industry")} />
        <Input label="Address" {...register("address")} />
        <Select label="Status" {...register("status")}>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="archived">Archived</option>
        </Select>
        <div className="md:col-span-2">
          <Textarea label="Notes" {...register("notes")} />
        </div>
        <div className="md:col-span-2 flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Save</Button>
        </div>
      </form>
    </Modal>
  );
}