insert into public.clients (name, company_name, contact_person, email, phone, industry, notes)
values
  ('Demo Client — ABC Beverages', 'ABC Beverages Ltd', 'Jane Demo', 'jane@demo-abcbeverages.test', '+254700000001', 'FMCG', 'Seed data — safe to delete'),
  ('Demo Client — XYZ Consumer', 'XYZ Consumer Products', 'John Demo', 'john@demo-xyz.test', '+254700000002', 'FMCG', 'Seed data — safe to delete');

insert into public.campaigns (client_id, name, code, campaign_type, status, start_date, end_date, objectives)
select id, 'Demo Campaign — Retail Visibility 2026', 'DEMO-RV-2026', 'Visibility', 'active',
       current_date - interval '10 days', current_date + interval '50 days',
       'Demo objectives: improve shelf visibility in modern trade.'
from public.clients where name like 'Demo Client — ABC%';

insert into public.activities (campaign_id, name, activity_type, status, start_date, end_date, target)
select id, 'Demo Activity — Merchandising', 'Merchandising', 'active',
       current_date - interval '5 days', current_date + interval '40 days', 500
from public.campaigns where code = 'DEMO-RV-2026';

insert into public.personnel (full_name, phone, personnel_type, agent_code, region, status)
values
  ('Demo Field Agent One', '+254700000101', 'MERCHANDISER', 'DEMO-AG-001', 'Nairobi', 'active'),
  ('Demo Field Agent Two', '+254700000102', 'BRAND_AMBASSADOR', 'DEMO-AG-002', 'Nairobi', 'active'),
  ('Demo Supervisor',     '+254700000103', 'SUPERVISOR',     'DEMO-SUP-001','Nairobi', 'active');

insert into public.teams (name, region, description)
values ('Demo Team Nairobi East', 'Nairobi', 'Seed team — safe to delete');

insert into public.locations (client_id, name, code, county, town, outlet_type, status)
select id, 'Demo Outlet — Supermart A', 'DEMO-LOC-001', 'Nairobi', 'Eastleigh', 'Supermarket', 'active'
from public.clients where name like 'Demo Client — ABC%';