import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { createLocation, updateLocation } from "@/services/locations.service";
import { listClients } from "@/services/clients.service";
import { useToast } from "@/hooks/useToast";
import type { Location } from "@/types/domain";

const schema = z.object({
  name: z.string().min(2, "Location name is required"),
  code: z.string().optional().or(z.literal("")),
  client_id: z.string().uuid().nullable().optional().or(z.literal("")),
  address: z.string().optional().or(z.literal("")),
  county: z.string().optional().or(z.literal("")),
  town: z.string().optional().or(z.literal("")),
  latitude: z.coerce.number().min(-90).max(90).optional().or(z.nan()),
  longitude: z.coerce.number().min(-180).max(180).optional().or(z.nan()),
  outlet_type: z.string().optional().or(z.literal("")),
  contact_name: z.string().optional().or(z.literal("")),
  contact_phone: z.string().optional().or(z.literal("")),
  status: z.enum(["active", "inactive", "archived"]),
});
type FormValues = z.infer<typeof schema>;

export function LocationFormModal({
  open, onClose, initial,
}: { open: boolean; onClose: () => void; initial?: Location | null }) {
  const qc = useQueryClient();
  const toast = useToast();

  const clients = useQuery({
    queryKey: ["clients", "options"],
    queryFn: () => listClients({ pageSize: 500 }),
  });

  const { register, handleSubmit, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? {
          name: initial.name,
          code: initial.code ?? "",
          client_id: initial.client_id ?? "",
          address: initial.address ?? "",
          county: initial.county ?? "",
          town: initial.town ?? "",
          latitude: initial.latitude ?? undefined,
          longitude: initial.longitude ?? undefined,
          outlet_type: initial.outlet_type ?? "",
          contact_name: initial.contact_name ?? "",
          contact_phone: initial.contact_phone ?? "",
          status: initial.status,
        }
      : {
          name: "", code: "", client_id: "", address: "", county: "", town: "",
          outlet_type: "", contact_name: "", contact_phone: "", status: "active",
        },
  });

  const mutation = useMutation({
    mutationFn: async (v: FormValues) => {
      const payload: any = {
        ...v,
        client_id: v.client_id || null,
        latitude: Number.isFinite(v.latitude) ? v.latitude : null,
        longitude: Number.isFinite(v.longitude) ? v.longitude : null,
      };
      return initial ? updateLocation(initial.id, payload) : createLocation(payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["locations"] });
      toast.push("success", initial ? "Location updated" : "Location created");
      onClose();
    },
    onError: (e: Error) =>
      toast.push("error", e.message || "Failed to save location"),
  });

  return (
    <Modal open={open} onClose={onClose} title={initial ? "Edit location" : "New location"} size="lg">
      <form onSubmit={handleSubmit((v) => mutation.mutate(v))} className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Input label="Location name *" {...register("name")} error={errors.name?.message} />
        <Input label="Code" {...register("code")} />

        <Select label="Client" {...register("client_id")}>
          <option value="">None</option>
          {clients.data?.rows.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </Select>

        <Input label="Outlet type" placeholder="Supermarket, Kiosk, Event venue…" {...register("outlet_type")} />

        <Input label="County" {...register("county")} />
        <Input label="Town" {...register("town")} />

        <Input label="Address" {...register("address")} className="md:col-span-2" />

        <Input label="Latitude" type="number" step="any" {...register("latitude")} error={errors.latitude?.message} />
        <Input label="Longitude" type="number" step="any" {...register("longitude")} error={errors.longitude?.message} />

        <Input label="Contact name" {...register("contact_name")} />
        <Input label="Contact phone" {...register("contact_phone")} />

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