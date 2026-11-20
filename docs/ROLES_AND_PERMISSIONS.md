# Who can do what

Every screen and button checks the signed-in person's permissions. If a role cannot use something, it is hidden for that role. The server enforces it as well, so a hidden button cannot be bypassed.

| Role | What this person is for | Can do | Cannot do |
|---|---|---|---|
| **System Administrator** (super administrator) | Runs the system | Everything in the office: users (create, edit, reset passwords, unlock, disable), roles view, settings, system health, audit logs, payroll view, all setup | Field work (visits, check-in, capture) and running payroll |
| **Agency** | Runs the operation day to day | Clients, projects, outlets, teams, territories, assignments, journey plans, reviews, stock, reports, live tracking, audit logs, roles view | Create/edit/disable users (view only), settings, system health, payroll |
| **Project Manager** | Runs assigned projects | Projects they are on: activities, assignments, forms, products, outlets, reviews, reports, tracking | Create clients/projects, territories, users, audit, settings |
| **Supervisor** | Looks after teams in the field | View and review visits, assign work, issues, stock, reports, live tracking | Change setup (forms, products, outlets), users |
| **Team Leader** | Runs a small team and also works in the field | Assign work, review visits, capture visits and attendance, issues, stock, tracking | Reports, setup, users |
| **Analyst** | Reads data | View clients, projects, outlets, stock, sales, OSA, merchandising, reports and export | Change anything; no live tracking |
| **Field Agent** | Works in the app | Check in, capture visits, photos, issues, journey-plan requests; sees only own work | Anything in the office |
| **Authorized Client User** | The client's view | Client portal only: their own approved visits, photos and reports | Everything else |
| **Executive** | Leadership view | Dashboard, executive summary, reports, project list, tracking | Changing anything, visits, outlets, users |
| **HR / Payroll** | Pay and attendance | Attendance, payroll (run and view), tracking, reports, users (view) | Visits, projects, outlets |

## Resetting a password (System Administrator)
Users → the lock key button on any row → leave blank for a generated password or type your own (10+ characters) → the password is shown once. The person is signed out everywhere and must choose a new one at next sign-in. A locked account shows an **Unlock** button. Every reset is written to the audit log. You cannot reset your own password here (use My profile).

## If someone sees "Not authorized"
That means the role lacks a permission the screen needed. Since 1.12.1 such screens, tabs and buttons are hidden for that role. If you still find one, note the role and the page and report it.
