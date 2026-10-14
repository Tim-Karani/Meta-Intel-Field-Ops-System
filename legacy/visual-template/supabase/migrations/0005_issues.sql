-- =========================================================
-- Issues & Escalations
-- =========================================================
create type issue_status as enum (
  'open', 'in_progress', 'escalated', 'resolved', 'closed'
);
create type issue_priority as enum ('low', 'medium', 'high', 'critical');

create table public.issues (
  id uuid primary key default gen_random_uuid(),
  title text not null,
  description text,
  category text,
  priority issue_priority not null default 'medium',
  status issue_status not null default 'open',

  campaign_id uuid references public.campaigns(id) on delete set null,
  activity_id uuid references public.activities(id) on delete set null,
  location_id uuid references public.locations(id) on delete set null,

  reported_by uuid references public.personnel(id) on delete set null,
  assigned_to uuid references public.personnel(id) on delete set null,

  resolution text,
  resolved_at timestamptz,

  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index idx_issues_status on public.issues(status);
create index idx_issues_priority on public.issues(priority);
create index idx_issues_campaign on public.issues(campaign_id);
create index idx_issues_assigned_to on public.issues(assigned_to);

-- updated_at trigger (reuse the helper function)
create trigger trg_issues_updated
  before update on public.issues
  for each row execute function public.set_updated_at();

-- audit trigger (reuse the helper function)
create trigger trg_issues_audit
  after insert or update or delete on public.issues
  for each row execute function public.fn_audit();

-- RLS
alter table public.issues enable row level security;

create policy "issues read" on public.issues
  for select using (public.is_staff());

create policy "issues write" on public.issues
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'));