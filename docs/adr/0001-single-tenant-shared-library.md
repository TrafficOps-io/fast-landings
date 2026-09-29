---
status: accepted
---

# Single-tenant installation with a shared library

Fast Landings is self-hosted, one installation per team. Landings, domains,
templates and integrations belong to the installation, not to a user; the only
distinction between operators is the Administrator / Editor role, and Editors
may deploy, pause and delete any landing. We chose this over per-user ownership
or multi-tenancy because the panel serves a small trusted team and isolation
between teams is achieved by running separate installations. `uploaded_by`
fields are audit trail, not ownership.

## Consequences

- Adding ownership later means a migration of every model and permission check.
- Anything that needs isolation between groups of operators is out of scope for
  a single installation.
