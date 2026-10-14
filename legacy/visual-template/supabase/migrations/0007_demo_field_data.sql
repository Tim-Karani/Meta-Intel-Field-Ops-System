-- =========================================================
-- DEMO FIELD DATA — 30 days of synthetic field activity
-- Safe to re-run: clears only rows flagged as demo.
-- Delete this file's data by running the cleanup at the
-- bottom of this file, or truncating the tables manually.
-- =========================================================

-- Mark demo rows: everything we insert here has a notes
-- field starting with '[DEMO]' — makes them easy to bulk delete.

-- =========================================================
-- 0. Cleanup previous demo data
-- =========================================================
delete from public.evidence where file_name like '[DEMO]%';
delete from public.osa_records where notes like '[DEMO]%' or notes is null;
delete from public.sales where notes like '[DEMO]%' or notes is null;
delete from public.visits where notes like '[DEMO]%' or notes is null;
delete from public.attendance where notes like '[DEMO]%' or notes is null;

-- =========================================================
-- 1. Attendance — 30 days per active personnel
-- =========================================================
with dates as (
  select generate_series(
    (current_date - interval '29 days')::date,
    current_date,
    interval '1 day'
  )::date as d
),
people as (
  select id, personnel_type from public.personnel where status = 'active'
),
combos as (
  select p.id as personnel_id, d.d as day, p.personnel_type from people p cross join dates d
)
insert into public.attendance
  (personnel_id, attendance_date, shift_start_time, check_in_time, check_out_time,
   check_in_lat, check_in_lng, check_in_gps_accuracy_m, check_in_method,
   check_out_lat, check_out_lng, status, notes)
select
  c.personnel_id,
  c.day,
  '08:30'::time,
  -- check-in time: mostly 08:15-08:45, some late (08:50-09:30)
  (c.day + (
    case
      when (extract(dow from c.day) = 6) then interval '0'  -- Sunday: skip
      when (random() < 0.15) then (interval '8 hours 50 minutes' + (random() * interval '40 minutes'))
      else (interval '8 hours 10 minutes' + (random() * interval '35 minutes'))
    end
  ))::timestamptz,
  (c.day + (
    case
      when (extract(dow from c.day) = 6) then interval '0'
      else interval '17 hours' + (random() * interval '60 minutes')
    end
  ))::timestamptz,
  -1.286389 + (random() - 0.5) * 0.1,
  36.817223 + (random() - 0.5) * 0.1,
  round((5 + random() * 20)::numeric, 2),
  'gps'::check_in_method,
  -1.286389 + (random() - 0.5) * 0.1,
  36.817223 + (random() - 0.5) * 0.1,
  case
    when extract(dow from c.day) = 6 then 'excused'::attendance_status
    when random() < 0.08 then 'absent'::attendance_status
    when random() < 0.15 then 'late'::attendance_status
    else 'present'::attendance_status
  end,
  '[DEMO] synthetic attendance'
from combos c
where extract(dow from c.day) <> 6  -- no Sunday records
on conflict (personnel_id, attendance_date) do nothing;

-- =========================================================
-- 2. Visits — visits per assignment per day
-- =========================================================
with dates as (
  select generate_series(
    (current_date - interval '29 days')::date,
    current_date,
    interval '1 day'
  )::date as d
),
active_assignments as (
  select a.id, a.personnel_id, a.campaign_id, a.activity_id, a.location_id
  from public.assignments a
  where a.scheduled_date between (current_date - interval '30 days') and current_date
),
-- Fallback: if you have no assignments, use the demo ones
fallback as (
  select
    p.id as personnel_id,
    c.id as campaign_id,
    act.id as activity_id,
    l.id as location_id
  from public.personnel p
  cross join public.campaigns c
  cross join public.activities act
  cross join public.locations l
  limit 1
),
-- Build a synthetic assignment-per-day-per-person grid so we have
-- data even if the real assignments table is thin.
grid as (
  select
    p.id as personnel_id,
    c.id as campaign_id,
    act.id as activity_id,
    l.id as location_id,
    d.d as visit_date
  from public.personnel p
  cross join public.campaigns c
  cross join public.activities act
  cross join public.locations l
  cross join dates d
  where p.status = 'active'
    and c.status in ('active','planned')
    and d.d >= c.start_date::date
    and (c.end_date is null or d.d <= c.end_date::date)
    and extract(dow from d.d) <> 6  -- no visits on Sunday
)
insert into public.visits
  (assignment_id, personnel_id, campaign_id, activity_id, location_id,
   visit_date, check_in_time, check_out_time, duration_minutes,
   check_in_lat, check_in_lng, check_in_gps_accuracy_m, check_in_method,
   distance_from_outlet_m, check_out_lat, check_out_lng,
   consumer_count, samples_given, leads_captured,
   status, notes)
select
  null,  -- synthetic visits are not tied to a specific assignment
  g.personnel_id, g.campaign_id, g.activity_id, g.location_id,
  g.visit_date,
  (g.visit_date + interval '9 hours' + (random() * interval '90 minutes'))::timestamptz,
  (g.visit_date + interval '9 hours' + interval '45 minutes' + (random() * interval '120 minutes'))::timestamptz,
  (45 + floor(random() * 90))::int,
  -1.286389 + (random() - 0.5) * 0.1,
  36.817223 + (random() - 0.5) * 0.1,
  round((5 + random() * 20)::numeric, 2),
  'gps'::check_in_method,
  round((random() * 200)::numeric, 2),
  -1.286389 + (random() - 0.5) * 0.1,
  36.817223 + (random() - 0.5) * 0.1,
  case when random() < 0.3 then (5 + floor(random() * 40))::int else null end,
  case when random() < 0.2 then (10 + floor(random() * 100))::int else null end,
  case when random() < 0.15 then (1 + floor(random() * 20))::int else null end,
  case
    when random() < 0.85 then 'completed'::visit_status
    when random() < 0.5 then 'incomplete'::visit_status
    else 'in_progress'::visit_status
  end,
  '[DEMO] synthetic visit'
from grid g
where random() < 0.75;  -- not every agent visits every outlet every day

-- =========================================================
-- 3. Sales — attach to ~60% of completed visits
-- =========================================================
with products as (
  select unnest(array[
    'Demo Product A',
    'Demo Product B',
    'Demo Product C',
    'Demo Product D',
    'Demo Product E'
  ]) as product_name
)
insert into public.sales
  (visit_id, personnel_id, campaign_id, location_id,
   product_name, sku, category, quantity, unit_price, total_value,
   payment_method, is_promo, captured_at, notes)
select
  v.id,
  v.personnel_id,
  v.campaign_id,
  v.location_id,
  p.product_name,
  'SKU-' || substr(md5(p.product_name), 1, 6),
  'Demo Category',
  1 + floor(random() * 20)::int,
  round((50 + random() * 450)::numeric, 2),
  0,  -- will compute below
  (array['cash','mpesa','card'])[1 + floor(random()*3)]::payment_method,
  random() < 0.2,
  v.check_out_time,
  '[DEMO] synthetic sale'
from public.visits v
cross join products p
where v.status = 'completed'
  and random() < 0.6;

-- Compute total_value correctly (was 0 above)
update public.sales
set total_value = round((quantity::numeric * unit_price)::numeric, 2)
where notes like '[DEMO]%';

-- =========================================================
-- 4. OSA records — attach to ~70% of completed visits
-- =========================================================
with products as (
  select unnest(array[
    'Demo Product A',
    'Demo Product B',
    'Demo Product C',
    'Demo Product D',
    'Demo Product E'
  ]) as product_name
)
insert into public.osa_records
  (visit_id, personnel_id, campaign_id, location_id,
   product_name, sku, category,
   available, out_of_stock, shelf_quantity, backroom_quantity,
   share_of_shelf, competitor_share,
   visibility_ok, planogram_ok, posm_present, price_on_shelf,
   captured_at, notes)
select
  v.id,
  v.personnel_id,
  v.campaign_id,
  v.location_id,
  p.product_name,
  'SKU-' || substr(md5(p.product_name), 1, 6),
  'Demo Category',
  random() > 0.15,             -- 85% available
  random() < 0.15,             -- 15% out of stock
  case when random() < 0.15 then 0 else (3 + floor(random() * 40))::int end,
  (0 + floor(random() * 80))::int,
  round((10 + random() * 30)::numeric, 2),  -- 10-40% share
  round((5 + random() * 30)::numeric, 2),   -- 5-35% competitor
  random() > 0.25,
  random() > 0.35,
  random() > 0.3,
  round((50 + random() * 450)::numeric, 2),
  v.check_out_time,
  '[DEMO] synthetic OSA'
from public.visits v
cross join products p
where v.status = 'completed'
  and random() < 0.7;

-- =========================================================
-- 5. Evidence stubs (metadata only — no actual files)
-- =========================================================
insert into public.evidence
  (visit_id, storage_path, file_name, mime_type, size_bytes, category, uploaded_by)
select
  v.id,
  'demo/' || v.id::text || '/photo-1.jpg',
  '[DEMO] storefront-photo.jpg',
  'image/jpeg',
  150000 + floor(random() * 500000)::bigint,
  (array['storefront','shelf','display','posm','product'])[1 + floor(random()*5)],
  null
from public.visits v
where v.status = 'completed'
  and random() < 0.5;

-- =========================================================
-- 6. A few extra issues (for the Issues donut)
-- =========================================================
insert into public.issues
  (title, description, category, priority, status, campaign_id, location_id, reported_by)
select
  (array[
    '[DEMO] Product out of stock',
    '[DEMO] POSM missing',
    '[DEMO] Competitor promotion spotted',
    '[DEMO] Store closed during visit',
    '[DEMO] Price mismatch on shelf',
    '[DEMO] Damaged display unit'
  ])[1 + floor(random()*6)],
  '[DEMO] synthetic issue for dashboard visuals',
  (array['Stock','POSM','Competitor','Location','Pricing','Display'])[1 + floor(random()*6)],
  (array['low','medium','high','critical'])[1 + floor(random()*4)]::issue_priority,
  (array['open','in_progress','escalated','resolved','closed'])[1 + floor(random()*5)]::issue_status,
  c.id,
  l.id,
  p.id
from public.campaigns c
cross join lateral (select id from public.locations limit 1) l
cross join lateral (select id from public.personnel where personnel_type = 'SUPERVISOR' limit 1) p
where c.status = 'active';

-- =========================================================
-- 7. Summary
-- =========================================================
select
  (select count(*) from public.attendance)  as attendance_rows,
  (select count(*) from public.visits)      as visit_rows,
  (select count(*) from public.sales)       as sales_rows,
  (select count(*) from public.osa_records) as osa_rows,
  (select count(*) from public.evidence)    as evidence_rows;