-- =========================================================
-- Field data tables: attendance, visits, sales, osa_records
-- Populated by the future Flutter mobile app.
-- Read by the web app for the Intelligence Center.
-- =========================================================

-- Enums
create type attendance_status as enum ('present','late','absent','excused');
create type check_in_method as enum ('gps','manual','geofence');
create type visit_status as enum ('planned','in_progress','completed','incomplete','missed');
create type payment_method as enum ('cash','mpesa','card','credit','other');

-- ---------- Attendance ----------
create table public.attendance (
  id uuid primary key default gen_random_uuid(),
  personnel_id uuid not null references public.personnel(id) on delete cascade,
  assignment_id uuid references public.assignments(id) on delete set null,
  attendance_date date not null,

  shift_start_time time,
  check_in_time timestamptz,
  check_out_time timestamptz,

  check_in_lat double precision,
  check_in_lng double precision,
  check_in_gps_accuracy_m numeric(8,2),
  check_in_method check_in_method,

  check_out_lat double precision,
  check_out_lng double precision,

  status attendance_status not null default 'present',
  notes text,

  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),

  constraint attendance_unique_per_day unique (personnel_id, attendance_date)
);
create index idx_attendance_date on public.attendance(attendance_date);
create index idx_attendance_personnel on public.attendance(personnel_id);
create index idx_attendance_status on public.attendance(status);

-- ---------- Visits ----------
create table public.visits (
  id uuid primary key default gen_random_uuid(),
  assignment_id uuid references public.assignments(id) on delete set null,
  personnel_id uuid not null references public.personnel(id) on delete cascade,
  campaign_id uuid not null references public.campaigns(id) on delete cascade,
  activity_id uuid references public.activities(id) on delete set null,
  location_id uuid not null references public.locations(id) on delete cascade,

  visit_date date not null,
  check_in_time timestamptz,
  check_out_time timestamptz,
  duration_minutes integer,

  check_in_lat double precision,
  check_in_lng double precision,
  check_in_gps_accuracy_m numeric(8,2),
  check_in_method check_in_method,
  distance_from_outlet_m numeric(8,2),

  check_out_lat double precision,
  check_out_lng double precision,

  consumer_count integer,
  samples_given integer,
  leads_captured integer,

  status visit_status not null default 'planned',
  notes text,

  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index idx_visits_date on public.visits(visit_date);
create index idx_visits_personnel on public.visits(personnel_id);
create index idx_visits_campaign on public.visits(campaign_id);
create index idx_visits_location on public.visits(location_id);
create index idx_visits_status on public.visits(status);

-- ---------- Sales ----------
create table public.sales (
  id uuid primary key default gen_random_uuid(),
  visit_id uuid references public.visits(id) on delete set null,
  personnel_id uuid not null references public.personnel(id) on delete cascade,
  campaign_id uuid not null references public.campaigns(id) on delete cascade,
  location_id uuid references public.locations(id) on delete set null,

  product_name text not null,
  sku text,
  category text,

  quantity integer not null check (quantity >= 0),
  unit_price numeric(12,2) not null check (unit_price >= 0),
  total_value numeric(14,2) not null check (total_value >= 0),

  payment_method payment_method,
  is_promo boolean not null default false,

  captured_at timestamptz not null default now(),
  notes text,

  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index idx_sales_captured_at on public.sales(captured_at);
create index idx_sales_personnel on public.sales(personnel_id);
create index idx_sales_campaign on public.sales(campaign_id);
create index idx_sales_location on public.sales(location_id);
create index idx_sales_product on public.sales(product_name);
create index idx_sales_visit on public.sales(visit_id);

-- ---------- OSA (On-Shelf Availability) ----------
create table public.osa_records (
  id uuid primary key default gen_random_uuid(),
  visit_id uuid references public.visits(id) on delete set null,
  personnel_id uuid not null references public.personnel(id) on delete cascade,
  campaign_id uuid not null references public.campaigns(id) on delete cascade,
  location_id uuid not null references public.locations(id) on delete cascade,

  product_name text not null,
  sku text,
  category text,

  available boolean not null default true,
  out_of_stock boolean not null default false,
  shelf_quantity integer,
  backroom_quantity integer,
  share_of_shelf numeric(5,2),       -- percentage 0-100
  competitor_share numeric(5,2),      -- percentage 0-100
  visibility_ok boolean,
  planogram_ok boolean,
  posm_present boolean,
  price_on_shelf numeric(12,2),

  captured_at timestamptz not null default now(),
  notes text,

  created_by uuid references auth.users(id),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index idx_osa_captured_at on public.osa_records(captured_at);
create index idx_osa_personnel on public.osa_records(personnel_id);
create index idx_osa_campaign on public.osa_records(campaign_id);
create index idx_osa_location on public.osa_records(location_id);
create index idx_osa_product on public.osa_records(product_name);
create index idx_osa_oos on public.osa_records(out_of_stock);

-- ---------- Evidence (photos) ----------
-- Attach photos to any of the four entities above.
create table public.evidence (
  id uuid primary key default gen_random_uuid(),
  visit_id uuid references public.visits(id) on delete cascade,
  attendance_id uuid references public.attendance(id) on delete cascade,
  osa_record_id uuid references public.osa_records(id) on delete cascade,
  sales_id uuid references public.sales(id) on delete cascade,
  issue_id uuid references public.issues(id) on delete cascade,

  storage_path text not null,        -- Supabase Storage path
  file_name text,
  mime_type text,
  size_bytes bigint,
  category text,                     -- storefront, shelf, display, posm, product, other

  captured_at timestamptz not null default now(),
  uploaded_by uuid references auth.users(id),
  created_at timestamptz not null default now()
);
create index idx_evidence_visit on public.evidence(visit_id);
create index idx_evidence_osa on public.evidence(osa_record_id);
create index idx_evidence_issue on public.evidence(issue_id);

-- ---------- updated_at triggers ----------
do $$
declare t text;
begin
  for t in select unnest(array['attendance','visits','sales','osa_records'])
  loop
    execute format(
      'create trigger trg_%s_updated before update on public.%I
       for each row execute function public.set_updated_at()', t, t);
  end loop;
end $$;

-- ---------- Audit triggers ----------
do $$
declare t text;
begin
  for t in select unnest(array['attendance','visits','sales','osa_records','evidence'])
  loop
    execute format(
      'create trigger trg_%s_audit after insert or update or delete on public.%I
       for each row execute function public.fn_audit()', t, t);
  end loop;
end $$;

-- ---------- RLS ----------
alter table public.attendance  enable row level security;
alter table public.visits      enable row level security;
alter table public.sales       enable row level security;
alter table public.osa_records enable row level security;
alter table public.evidence    enable row level security;

-- Attendance: staff read; supervisors & above write
create policy "attendance read" on public.attendance
  for select using (
    public.is_staff()
    or personnel_id in (select id from public.personnel where user_id = auth.uid())
  );
create policy "attendance write" on public.attendance
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'));

-- Visits: staff read; client users see their client's visits; field personnel see their own
create policy "visits read" on public.visits
  for select using (
    public.is_staff()
    or personnel_id in (select id from public.personnel where user_id = auth.uid())
    or (public.current_role() = 'CLIENT_USER' and exists (
      select 1 from public.campaigns c
      where c.id = visits.campaign_id and c.client_id = public.current_client_id()
    ))
  );
create policy "visits write" on public.visits
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'));

-- Sales: same scoping pattern
create policy "sales read" on public.sales
  for select using (
    public.is_staff()
    or personnel_id in (select id from public.personnel where user_id = auth.uid())
    or (public.current_role() = 'CLIENT_USER' and exists (
      select 1 from public.campaigns c
      where c.id = sales.campaign_id and c.client_id = public.current_client_id()
    ))
  );
create policy "sales write" on public.sales
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'));

-- OSA: same scoping pattern
create policy "osa read" on public.osa_records
  for select using (
    public.is_staff()
    or personnel_id in (select id from public.personnel where user_id = auth.uid())
    or (public.current_role() = 'CLIENT_USER' and exists (
      select 1 from public.campaigns c
      where c.id = osa_records.campaign_id and c.client_id = public.current_client_id()
    ))
  );
create policy "osa write" on public.osa_records
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'));

-- Evidence: readable if you can read the parent row (simplified: staff read)
create policy "evidence read" on public.evidence
  for select using (public.is_staff());
create policy "evidence write" on public.evidence
  for all using (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'))
  with check (public.current_role() in (
      'SUPER_ADMIN','ADMIN','OPERATIONS_MANAGER','SUPERVISOR','FIELD_COORDINATOR'));