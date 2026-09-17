# Security policy

## Supported versions

Security fixes are applied to the latest release and the `main` branch.

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting for this repository. Do not include credentials, customer data, unpublished landing content, or exploit details in a public issue.

Fast Landings executes trusted uploaded PHP in a dedicated, restricted runtime. This boundary reduces access to the control panel and its secrets, but it is not intended to isolate mutually hostile tenants. Reports that demonstrate a path from the landing runtime to panel secrets, private releases, the database network, or the host are in scope.
