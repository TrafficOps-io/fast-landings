---
status: accepted
---

# Request macros use the exported shared renderer

Request macro syntax, lookup and rendering come from `trafficops/tops-runtime`.
The panel embeds its standalone export when publishing each dynamic page;
the landing PHP runtime retains its dependency-free isolation and never reads
panel vendor code. This removes the duplicated renderer while preserving the
landing contract for missing/null values, booleans, whole-source macros and HTML
escaping. Request collection, `@validation`, redirect policies and HTML-context
checks stay in Fast Landings. Existing releases retain their compiled engine
until republished.

Implements TrafficOps-io/fast-landings#14 and TrafficOps-io/tops-runtime#4.
