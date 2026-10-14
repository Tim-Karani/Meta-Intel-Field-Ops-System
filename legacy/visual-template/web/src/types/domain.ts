export type UserRole =
  | "SUPER_ADMIN" | "ADMIN" | "MANAGEMENT" | "OPERATIONS_MANAGER"
  | "SUPERVISOR" | "FIELD_COORDINATOR" | "ANALYST"
  | "CLIENT_USER" | "FIELD_PERSONNEL";

export type RecordStatus = "active" | "inactive" | "archived";
export type CampaignStatus =
  | "draft" | "planned" | "active" | "paused" | "completed" | "cancelled";
export type AssignmentStatus =
  | "scheduled" | "in_progress" | "completed" | "missed" | "cancelled";
export type PersonnelType =
  | "MERCHANDISER" | "BRAND_AMBASSADOR" | "PROMOTER"
  | "FIELD_REPRESENTATIVE" | "SUPERVISOR" | "COORDINATOR";
export type IssueStatus = "open" | "in_progress" | "escalated" | "resolved" | "closed";
export type IssuePriority = "low" | "medium" | "high" | "critical";

export interface Profile {
  id: string;
  email: string;
  full_name: string | null;
  phone: string | null;
  role: UserRole;
  client_id: string | null;
  status: RecordStatus;
}

export interface Client {
  id: string;
  name: string;
  company_name: string | null;
  contact_person: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  industry: string | null;
  status: RecordStatus;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface Campaign {
  id: string;
  client_id: string;
  name: string;
  code: string | null;
  description: string | null;
  campaign_type: string | null;
  objectives: string | null;
  budget: number | null;
  start_date: string | null;
  end_date: string | null;
  status: CampaignStatus;
  created_at: string;
  updated_at: string;
}

export interface Activity {
  id: string;
  campaign_id: string;
  name: string;
  activity_type: string | null;
  description: string | null;
  target: number | null;
  start_date: string | null;
  end_date: string | null;
  status: CampaignStatus;
  supervisor_id: string | null;
}

export interface Personnel {
  id: string;
  full_name: string;
  phone: string | null;
  email: string | null;
  personnel_type: PersonnelType;
  agent_code: string | null;
  region: string | null;
  status: RecordStatus;
  supervisor_id: string | null;
}

export interface Team {
  id: string;
  name: string;
  supervisor_id: string | null;
  region: string | null;
  description: string | null;
  status: RecordStatus;
}

export interface Location {
  id: string;
  client_id: string | null;
  name: string;
  code: string | null;
  address: string | null;
  county: string | null;
  town: string | null;
  latitude: number | null;
  longitude: number | null;
  outlet_type: string | null;
  contact_name: string | null;
  contact_phone: string | null;
  status: RecordStatus;
}

export interface Assignment {
  id: string;
  personnel_id: string;
  campaign_id: string;
  activity_id: string | null;
  location_id: string | null;
  supervisor_id: string | null;
  scheduled_date: string;
  start_time: string | null;
  end_time: string | null;
  status: AssignmentStatus;
  notes: string | null;
}

export interface Issue {
  id: string;
  title: string;
  description: string | null;
  category: string | null;
  priority: IssuePriority;
  status: IssueStatus;
  campaign_id: string | null;
  activity_id: string | null;
  location_id: string | null;
  reported_by: string | null;
  assigned_to: string | null;
  resolution: string | null;
  resolved_at: string | null;
  created_at: string;
  updated_at: string;
}