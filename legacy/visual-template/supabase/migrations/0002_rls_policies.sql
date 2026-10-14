alter table public.profiles     enable row level security;
alter table public.clients      enable row level security;
alter table public.campaigns    enable row level security;
alter table public.activities   enable row level security;
alter table public.personnel    enable row level security;
alter table public.teams        enable row level security;
alter table public.team_members enable row level security;
alter table public.locations    enable row level security;
alter table public.assignments  enable row level security;
alter table public.audit_logs   enable row level security;

create or replace function public.current_role()
returns user_role language sql stable security definer as $$
  select role from public.profiles where id = auth.uid()
$$;

create or replace function public.current_client_id()
returns uuid language sql stable security definer as $$
  select client_id from public.profiles where id = auth.uid()
$$;

create or replace function public.is_staff()
returns boolean language sql stable as $$
  select public.current_role() in (
    'SUPER_ADMIN','ADMIN','MANAGEMENT','OPERATIONS_MANAGER',
    'SUPERVISOR','FIELD_COORDINATOR','ANALYST'
  )
$$;

create policy "profiles self read"  on public.profiles
  for select using (id = auth.uid() or public.is_staff());
create policy "profiles self update" on public.profiles
  for update using (id = auth.uid()) with check (id = auth.uid());
create policy "profiles admin all"  on public.profiles
  for all using (public.current_role() in ('SUPER_ADMIN','ADMIN'))
  with check (public.current_role() in ('SUPER_ADMIN','ADMIN'));

create policy "clients staff read" on public.clients
  for select using (
    public.is_staff()
    or (public.current_role() = 'CLIENT_USER' and id = public.current_client_id())
  );
create policy "clients admin write" on public.clients
  for all using (public.current_role() in ('SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER'))
  with check (public.current_role() in ('SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER'));

create policy "campaigns read" on public.campaigns
  for select using (
    public.is_staff()
    or (public.current_role() = 'CLIENT_USER' and client_id = public.current_client_id())
  );
create policy "campaigns write" on public.campaigns
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','MANAGEMENT','OPERATIONS_MANAGER','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','MANAGEMENT','OPERATIONS_MANAGER','FIELD_COORDINATOR'));

create policy "activities read" on public.activities
  for select using (
    public.is_staff()
    or exists (
      select 1 from public.campaigns c
      where c.id = activities.campaign_id
        and public.current_role() = 'CLIENT_USER'
        and c.client_id = public.current_client_id()
    )
  );
create policy "activities write" on public.activities
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','FIELD_COORDINATOR'));

create policy "personnel read" on public.personnel
  for select using (public.is_staff() or user_id = auth.uid());
create policy "personnel write" on public.personnel
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR'));

create policy "teams read" on public.teams
  for select using (public.is_staff());
create policy "teams write" on public.teams
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR'));

create policy "team_members read" on public.team_members
  for select using (public.is_staff());
create policy "team_members write" on public.team_members
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR'));

create policy "locations read" on public.locations
  for select using (
    public.is_staff()
    or (public.current_role() = 'CLIENT_USER' and client_id = public.current_client_id())
  );
create policy "locations write" on public.locations
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'));

create policy "assignments read" on public.assignments
  for select using (
    public.is_staff()
    or personnel_id in (select id from public.personnel where user_id = auth.uid())
  );
create policy "assignments write" on public.assignments
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'));

create policy "audit read" on public.audit_logs
  for select using (public.current_role() in (
    'SUPER_ADMIN','ADMIN','MANAGEMENT','ANALYST'));