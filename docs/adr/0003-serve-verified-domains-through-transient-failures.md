---
status: proposed
---

# Serving depends on the domain having been verified, not on the last check

A landing is served on a domain while the domain is verified (it has been
Active at least once), is not Drifted, and the landing is Published. Transient
check outcomes (Unreachable from a resolver timeout, Error from a failed worker)
must not take a live site offline, and a wildcard base failing transiently must
not take down its child domains. The current code gates serving on
`status = active` only; this decision changes that. Only Drifted, which means
DNS really no longer points at the origin target, stops serving.

## Considered options

- Keep gating on the latest status: simplest, but every network hiccup between
  the panel and a resolver unpublishes paid traffic.
- Gate on "ever verified" and treat Drifted as the only negative signal: chosen.
