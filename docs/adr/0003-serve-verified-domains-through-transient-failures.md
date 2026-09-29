---
status: accepted
---

# Serving depends on the domain having been verified, not on the last check

A landing is served on a domain while the domain is verified (it has been
Active at least once), is not Drifted, and the landing is Published. Transient
check outcomes (Unreachable from a resolver timeout, Error from a failed worker)
must not take a live site offline, and a wildcard base failing transiently must
not take down its child domains. Serving is gated on the domain's `verified_at`
marker (set on its first Active status, never cleared) and on the absence of
unresolved drift. A Drifted result records `drifted_at`; only a later Active
result clears it. The latest check status otherwise plays no part: a timeout
after proven drift cannot reopen a hostname whose DNS remains wrong. The six
check statuses remain available to operators, including the latest transient
failure while earlier drift is unresolved.
Drift is itself derived from verification: DNS that no longer matches is
Drifted for a verified domain and still Pending propagation for one that was
never verified.

## Considered options

- Keep gating on the latest status: simplest, but every network hiccup between
  the panel and a resolver unpublishes paid traffic.
- Gate on "ever verified" and treat Drifted as the only negative signal: chosen.
