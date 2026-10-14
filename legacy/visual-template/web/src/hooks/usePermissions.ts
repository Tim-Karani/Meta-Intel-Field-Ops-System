import type { UserRole } from "@/types/domain";
import { useProfile } from "./useProfile";

const WRITE_ROLES: UserRole[] = [
  "SUPER_ADMIN","ADMIN","OPERATIONS_MANAGER","SUPERVISOR","FIELD_COORDINATOR",
];
const ADMIN_ROLES: UserRole[] = ["SUPER_ADMIN","ADMIN"];

export function usePermissions() {
  const { data: profile, isLoading } = useProfile();
  const role = profile?.role;

  return {
    isLoading,
    role,
    canWrite: !!role && WRITE_ROLES.includes(role),
    isAdmin: !!role && ADMIN_ROLES.includes(role),
    isStaff:
      !!role &&
      role !== "CLIENT_USER" &&
      role !== "FIELD_PERSONNEL",
  };
}