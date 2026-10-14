create extension if not exists "pgcrypto";

create type user_role as enum (
  'SUPER_ADMIN','ADMIN','MANAGEMENT','OPERATIONS_MANAGER',
  'SUPERVISOR','FIELD_COORDINATOR','ANALYST','CLIENT_USER','FIELD_PERSONNEL'
);
create type record_status as enum ('active','inactive','archived');
create type campaign_status as enum ('draft','planned','active','paused','completed','cancelled');
create type assignment_status as enum ('scheduled','in_progress','completed','missed','cancelled');
create type personnel_type as enum (
  'MERCHANDISER','BRAND_AMBASSADOR','PROMOTER','FIELD_REPRESENTATIVE','SUPERVISOR','COORDINATOR'
);

create table public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  email text not null,
  full_name text,
  phone text,
  role user_role not null default 'FIELD_PERSONNEL',
  client_id uuid,
  status record_status not null default 'active',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table public.clients (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  company_name text,
  contact_person text,
  email text,
  phone text,
  address text,
  industry text,
  status record_status not null default 'active',
  notes text,
  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index idx_clients_status on public.clients(status);
create index idx_clients_name on public.clients(name);

alter table public.profiles
  add constraint profiles_client_id_fkey
  foreign key (client_id) references public.clients(id) on delete set null;

create table public.campaigns (
  id uuid primary key default gen_random_uuid(),
  client_id uuid not null references public.clients(id) on delete restrict,
  name text not null,
  code text unique,
  description text,
  campaign_type text,
  objectives text,
  budget numeric(14,2),
  start_date date,
  end_date date,
  status campaign_status not null default 'draft',
  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint campaigns_dates_ck check (end_date is null or start_date is null or end_date >= start_date)
);
create index idx_campaigns_client on public.campaigns(client_id);
create index idx_campaigns_status on public.campaigns(status);

create table public.activities (
  id uuid primary key default gen_random_uuid(),
  campaign_id uuid not null references public.campaigns(id) on delete cascade,
  name text not null,
  activity_type text,
  description text,
  target integer,
  start_date date,
  end_date date,
  status campaign_status not null default 'draft',
  supervisor_id uuid,
  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index idx_activities_campaign on public.activities(campaign_id);

create table public.personnel (
  id uuid primary key default gen_random_uuid(),
  full_name text not null,
  phone text,
  email text,
  personnel_type personnel_type not null,
  agent_code text unique,
  region text,
  status record_status not null default 'active',
  supervisor_id uuid references public.personnel(id) on delete set null,
  user_id uuid references auth.users(id) on delete set null,
  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index idx_personnel_status on public.personnel(status);
create index idx_personnel_type on public.personnel(personnel_type);

alter table public.activities
  add constraint activities_supervisor_fkey
  foreign key (supervisor_id) references public.personnel(id) on delete set null;

create table public.teams (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  supervisor_id uuid references public.personnel(id) on delete set null,
  region text,
  description text,
  status record_status not null default 'active',
  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table public.team_members (
  team_id uuid not null references public.teams(id) on delete cascade,
  personnel_id uuid not null references public.personnel(id) on delete cascade,
  joined_at timestamptz not null default now(),
  primary key (team_id, personnel_id)
);

create table public.locations (
  id uuid primary key default gen_random_uuid(),
  client_id uuid references public.clients(id) on delete set null,
  name text not null,
  code text,
  address text,
  county text,
  town text,
  latitude double precision,
  longitude double precision,
  outlet_type text,
  contact_name text,
  contact_phone text,
  status record_status not null default 'active',
  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index idx_locations_client on public.locations(client_id);
create index idx_locations_county on public.locations(county);

create table public.assignments (
  id uuid primary key default gen_random_uuid(),
  personnel_id uuid not null references public.personnel(id) on delete restrict,
  campaign_id uuid not null references public.campaigns(id) on delete cascade,
  activity_id uuid references public.activities(id) on delete set null,
  location_id uuid references public.locations(id) on delete set null,
  supervisor_id uuid references public.personnel(id) on delete set null,
  scheduled_date date not null,
  start_time time,
  end_time time,
  status assignment_status not null default 'scheduled',
  notes text,
  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint assignments_unique unique (personnel_id, scheduled_date, start_time, location_id)
);
create index idx_assignments_date on public.assignments(scheduled_date);
create index idx_assignments_personnel on public.assignments(personnel_id);
create index idx_assignments_campaign on public.assignments(campaign_id);

create table public.audit_logs (
  id bigserial primary key,
  user_id uuid references auth.users(id) on delete set null,
  action text not null,
  entity text not null,
  entity_id text,
  metadata jsonb,
  created_at timestamptz not null default now()
);
create index idx_audit_entity on public.audit_logs(entity, entity_id);
create index idx_audit_created on public.audit_logs(created_at desc);

create or replace function public.set_updated_at()
returns trigger language plpgsql as $$
begin new.updated_at = now(); return new; end $$;

do $$
declare t text;
begin
  for t in select unnest(array[
    'profiles','clients','campaigns','activities','personnel',
    'teams','locations','assignments'
  ])
  loop
    execute format(
      'create trigger trg_%s_updated before update on public.%I
       for each row execute function public.set_updated_at()', t, t);
  end loop;
end $$;

create or replace function public.fn_audit()
returns trigger language plpgsql security definer as $$
declare
  v_user uuid := auth.uid();
  v_id text;
begin
  if (tg_op = 'DELETE') then v_id := old.id::text;
  else v_id := new.id::text;
  end if;
  insert into public.audit_logs(user_id, action, entity, entity_id, metadata)
  values (v_user, lower(tg_op), tg_table_name, v_id,
          jsonb_build_object('at', now()));
  return coalesce(new, old);
end $$;

do $$
declare t text;
begin
  for t in select unnest(array[
    'clients','campaigns','activities','personnel',
    'teams','locations','assignments'
  ])
  loop
    execute format(
      'create trigger trg_%s_audit after insert or update or delete on public.%I
       for each row execute function public.fn_audit()', t, t);
  end loop;
end $$;