---
status: accepted
---

# DNS pointing at the origin target is the proof of hostname control

Custom domains are not verified with a TXT record or an HTTP challenge. A
hostname counts as controlled by the installation as soon as its DNS resolves to
the origin target. We chose this because it removes a step for operators, and
under the single-tenant model (ADR-0001) there is no untrusted party who could
register a hostname that already points at this origin. The unused
`verification_token` column is a leftover of the rejected TXT design and should
be removed.
