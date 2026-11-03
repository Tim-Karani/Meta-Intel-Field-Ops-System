# Setting up a new project - step by step

> **Fastest way:** open **New client & project** in the menu. It walks through client, project type, outlets, products, people and launch on one page and does steps 1-9 below for you. The manual steps below remain for changes later.

Follow this order every time a client or project comes up. Each step says **where to click** on the web app. Nothing here needs a developer.
The first time through takes about 30-45 minutes; a repeat project (same type, same client) is about 10 minutes because you can reuse products, outlets and forms.

> **The structure you are building**
> Operations Manager (sees all projects, clients, teams, users) → **Supervisor** (the whole field team, all regions) → **Team Leader** (one region, e.g. Nairobi) → **Field Agents** (permanent merchandisers or relievers).

## Step 0 - Once only: your people structure
1. **Users → New user** → role **Supervisor** (one per field operation). 
2. **Users → New user** → role **Team Leader** for each region: set **Region** (e.g. Nairobi, Mombasa) and the **Supervisor** they report to.
3. Do this before agents, because each agent picks a Team Leader.

## Step 1 - The client
**Clients → New client.** Name, code, contacts. Everything in the project hangs under the client, and the client's own users (step 9) only ever see this client's approved results.

## Step 2 - Products
**Products → New product** (name, SKU, price, brand/category), under that client. Needed for sales, availability (OSA) and share-of-shelf work. Skip for pure photo or audit projects.

## Step 3 - Outlets (locations)
**Give every outlet its position now.** In the *New client & project* wizard (Outlets step) upload a CSV with `latitude` and `longitude`, or add outlets one at a time and click **Pick on map / paste coordinates** (click the shop entrance, or paste the numbers or link copied from Google Maps). Positions entered by the office are trusted: agents are checked in against them (within 200 m), cannot make up or move an outlet, and visits to them are approved automatically when GPS is clean. Outlets without a position can only be visited if the project's *Where do outlet positions come from?* is set to *First visit maps an outlet*, and then the first visit is reviewed.

**Locations → New location** (one at a time), or **Locations → Import outlets (CSV)** to add many at once: download the template, fill it in Excel, Save As CSV, pick the campaign to link them all to, press *Check the file*, then *Import*. Outlets already in the system are reused, not duplicated. What you need depends on the project type:

| Project type | What to do with outlets |
|---|---|
| Merchandising | Add the outlets now (name, town, type). Map them (pin on the map) or let the first check-in save the position. |
| Activation | Add **and map** every outlet now. Agents can only check in at them. |
| Roadshow | Nothing now. Agents add each stop as they arrive. |
| General Trade | Add the known outlets; agents add the new ones. |

An outlet without a saved position is mapped by the first agent check-in (needs a clear GPS fix) and your supervisor confirms it.

## Step 4 - The form agents fill
**Forms → New form.** Add sections and questions (yes/no, number, choice, photo, signature...), set which are required, add "show only if..." conditions, then **Publish**. Keep one form per activity (audit, sales call, activation survey). A published form is frozen; changing it creates a new version, so past visits stay valid.

## Step 5 - Choose the project type
**Project templates** shows the five built-in types: Merchandising, Activation, Roadshow, General Trade, Custom made. Use one as it is, or **Use as a copy** to make your own (for example "Key accounts - 4 photos, relievers can add outlets"). The type decides:
* where outlets come from and **who may add one in the field**,
* whether permanent agents get a **Fixed Journey Plan**,
* which tiles the agent sees (form, sales, OSA, merchandising, photos),
* how many photos are needed, and whether **check-in / check-out photos** are required,
* what the app calls a visit ("Visit", "Activation", "Roadshow stop").

## Step 6 - Create the campaign (the project)
**Campaigns → New campaign.** Client, code, name, dates, budget, and **Project type** (the switches below it fill in; tweak any of them for this project only). Save. The campaign starts as a draft.

## Step 7 - Plug everything into the campaign
Open the campaign → 
1. **Activities** → add the activity (e.g. "Monthly shelf audit") and attach the form from step 4.
2. **Teams, locations & more** → **Manage** each box: Teams, Locations (the outlets from step 3), Products, Forms, **Staff** (campaign managers, supervisors, Team Leaders, agents who may work on it).
3. **KPIs** (optional) → targets like "Approved visits 200" or "OSA 90%".

## Step 8 - Agents
**Users → New user → Field Agent.** Set:
* **Employment: Permanent** (follows a fixed journey plan) **or Reliever** (covers outlets, may add the ones they visit when the project allows),
* **Team Leader** (the Supervisor and region are filled in automatically), agent code, phone.
The system shows a temporary password once - give it to the agent; they must change it at first sign-in. Then add the agent to the campaign's **Staff** (step 7).

## Step 9 - The client's login (optional)
**Users → New user → Authorized Client User**, linked to the client. They see only the client portal: approved visits, photos and reports. Agent names are hidden from them.

## Step 10 - Give the work out
* **Permanent merchandisers (Merchandising, General Trade):** **Journey plans → New plan** → agent, campaign, then which outlets on which weekday. Visits are created for them automatically every night.
* **Activation / Roadshow / one-off jobs:** **Assignments → New assignment** → agent, outlet, date and time (or bulk-create for several agents/outlets).
* **Relievers:** assign the outlets they cover that day, or let them add outlets if the project allows it.

## Step 11 - Switch it on
Campaign page → **Activate**. Agents cannot start visits on a draft or paused campaign. Before the full team starts, test with **one** agent on a real phone: sign in, open the assignment, check in at the outlet (within 200 m, with the selfie), fill the form, take photos, check out (photo), submit - then approve the visit on the web.

## Step 12 - Running it day to day
| What | Where |
|---|---|
| Who is working right now, when they started, every check-in and its photos | **Operations → Team attendance** |
| Review and approve submitted visits | **Visits** (filter "submitted") |
| Look at photos | **Evidence** |
| Problems raised from the field | **Issues** |
| Numbers for the client | **Reports** (export CSV) and the client portal |
| Reports that email themselves (daily check-ins, OSA, SOS...) | **Insights → Scheduled reports** |

## Quick recipes
* **Merchandising project:** steps 1-3 (add outlets), 4 (shelf form), 5 pick *Merchandising*, 6-7, 8 (permanent agents + a couple of relievers), 10 journey plans, 11.
* **Activation project:** steps 1-3 (add **and map** outlets), 4 (activation survey), 5 pick *Activation*, 6-7, 8, 10 assignments per event day, 11.
* **Roadshow project:** steps 1, 4, 5 pick *Roadshow*, 6-7, 8, 10 assign each agent a day (no outlets needed), 11.
* **General trade route sales:** steps 1-4, 5 pick *General Trade*, 6-8, 10 journey plans or routes, 11.

## Checklist (copy for each project)
- [ ] Client created - [ ] Products added - [ ] Outlets added (mapped if Activation) - [ ] Form published
- [ ] Project type chosen - [ ] Campaign created - [ ] Activity added with the form - [ ] Teams / outlets / products / forms / staff linked
- [ ] Supervisor and Team Leaders exist - [ ] Agents created (permanent / reliever, Team Leader set) - [ ] Client user created
- [ ] Journey plans or assignments created - [ ] One-agent phone test done - [ ] Campaign activated - [ ] Scheduled reports set up

---

# Reference: templates, journey plans, permanent vs reliever agents

## Project templates (how a project runs)

Pick a **Project type** when you create a campaign (Campaigns → New campaign). The switches below fill themselves in; change
any of them for that one project. Built-in types cannot be edited, but *Project templates → Use as a copy* makes your own.

| Setting | Meaning |
|---|---|
| Where do outlets come from? | journey plan / set up on the web / added by agents / mix (guidance shown on the campaign) |
| Who can add an outlet in the field? | nobody / relievers only / every agent (**enforced by the server**) |
| Fixed Journey Plans | permanent agents get their visits generated automatically from a weekly plan |
| What the agent does | which task tiles show on the phone: form, sales, OSA, merchandising, photos |
| Photos needed | minimum photos before a visit can be submitted (**enforced by the server** and the phone) |
| Check-in / check-out photos | a selfie at the outlet on arrival and again when closing it (**on by default**, enforced by the server) |
| The app calls a visit | Visit / Activation / Roadshow stop … |
| Default outlet type | pre-selected when an agent adds an outlet |

| Template | Outlets | Who adds outlets | Tasks | Min photos |
|---|---|---|---|---|
| Merchandising | fixed journey plan | relievers only | form, OSA, merchandising, photos | 2 |
| Activation | set up and mapped on the web; agent only checks in | nobody | form, sales, photos | 2 |
| Roadshow | agent adds each stop; position saved at check-in | everyone | form, sales, photos | 3 |
| General Trade | office + field | everyone | form, sales, OSA, photos | 1 |
| Custom made | everything on, you choose | everyone | all | 0 |

Existing campaigns (created before templates) behave as *Custom made* until you edit them and pick a type.

## Permanent vs reliever agents

Users → (field agent) → **Employment**.
* **Permanent**: follows a Fixed Journey Plan. Where a project says "relievers only", a permanent agent cannot add outlets; the
  phone hides *Add outlet* and the server refuses with an explanation.
* **Reliever**: covers outlets (usually with a dated cover plan). May add the outlet they are standing at (project permitting).
  The outlet is created in the system, linked to the campaign, flagged *needs confirmation*, and its position is saved at check-in.

## Fixed Journey Plans

Operations → **Journey plans**. One plan per agent per campaign: outlets × weekdays, optional valid-from / valid-to (for cover).
* Saving a plan creates the agent's visits for the next **14 days** (already accepted: no tapping needed). The scheduler
  (`journey-plans:generate`, 00:20 daily) keeps topping them up; running it twice never duplicates (unique stop + date).
* Removing an outlet/day, pausing or ending a plan cancels the **upcoming, not-yet-started** visits it created; history is kept.
* To put an outlet an agent added in the field onto a plan: it is already linked to the campaign, so open the plan and tick it.
* Visits from a plan show *Journey plan* on the phone and web; visits an agent added show *Added by you / Added in the field*.

## Outlets added on the web

Locations → New location (or import), then link it to the campaign (Campaign → Teams, locations & more). It is now available to
assign (Assignments) or to place on a Journey plan. Outlets with no coordinates are fine: the first check-in maps them.

## Photos

Camera only (no gallery), stamped on the picture with date/time, GPS, outlet and agent; the server also refuses a photo claiming
to be older than 3 days, and one already used on another visit.


## Review, sales and stock settings (v1.5)
* **Which visits does a person review?** *Only exceptions* (default): new/unconfirmed outlets and flagged GPS. *Every submitted visit* if a client demands it.
* **Sales** is off unless the project type needs it (Sales rep / Foot soldier). Merchandising, activation and audit projects capture availability, share of shelf, expiry, facings and photos, and read sales from the outlet's own records.
* **Stock** (menu *Stock*, sales projects only): issue stock to each agent, record returns, and see what they should still hold against what they sold.
