# Shelf and coverage reports

All reports respect the viewer's access scope, take the date/client/project/territory filters plus **chain**, **category**, **brand** and **product**, and export to CSV (logged). Only visits that are submitted, under review or approved count; client users see approved visits only.

## On-shelf availability (OSA)
`OSA = checks where the product was available / all checks`. Out-of-stock rate is the complement.
- **By channel**: one row per chain (outlets with no chain show as *Unassigned*), with its channel group.
- **By category**: main category and sub-category. Products in a sub-category appear under their main category with the sub-category named.
- **By outlet**: one row per outlet with its chain and town.
- **By product**: one row per product with SKU, brand, category.

## Share of shelf (SOS)
`SOS = our facings / all facings counted on the shelf`. "All facings" is the shelf total recorded once per visit (merchandising step). "Our facings" is the visit's own count when entered, otherwise the sum of the product facings captured in the OSA step.
- **By channel** and **by outlet** use the whole-shelf figure per visit.
- **By category** and **by product** use the product facings over the visit's shelf total, and compare with the expected share (the product's target, or the default of 10%). The gap column is actual minus expected.

## Outlets not visited
Outlets linked to the projects you can see (active outlets, non-archived projects) with no started/completed/submitted/approved visit in the period (all time when no dates are chosen; approved only for client users). Status: *Never visited*, *Planned, not done* (an assignment existed in the period), or *Not visited in period*. Summary gives outlets in scope, visited, not visited and coverage %.
