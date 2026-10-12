# GoVisa self-hosting notice

This repository is a fork of [`anonaddy/anonaddy`](https://github.com/anonaddy/anonaddy),
the source of [addy.io](https://addy.io/), maintained by **AFG Ventura** for
GoVisa's own alias-forwarding rail.

## License

Upstream is licensed **AGPL-3.0**. This fork keeps the same license and the
upstream copyright notices. See [`LICENSE.md`](LICENSE.md). We do not relicense.

Under AGPL §13, anyone interacting with our running instance over a network is
entitled to the source of that running version. Keeping this fork public and
this notice current is how we satisfy that, so:

- **Do not make this repository private.**
- **Do not force-push or rewrite `master`.** It must stay fast-forwardable from
  upstream.
- Keep the fork public for as long as the instance serves anyone outside the
  machine it runs on.

## Branch policy

| Branch | Rule |
|---|---|
| `master` | **Tracks upstream exactly.** No local commits, ever. |
| `govisa/self-hosted` | Our configuration and deployment overlay only. Nothing here touches upstream application code. |

The split exists so upgrading is mechanical:

```bash
git fetch upstream --tags
git checkout master
git merge --ff-only upstream/master
```

If a local change cannot live on `govisa/self-hosted` — because it has to patch
application code — that is a signal to stop and reconsider, not to commit it to
`master`.

## Deployed version

| Item | Value |
|---|---|
| Upstream tag | `v1.7.3` (commit `150983e`, released 2026-10-06) |
| Instance hostname | to be recorded when the host is provisioned |
| Deployment config | `afgventura/govisa` → `infra/terraform/addy/` (host) and `infra/addy/` (stack + backup scripts) |

Bump the tag here and in `infra/terraform/addy/terraform.tfvars` together, in the
same change.

## What is ours and what is not

- **Ours** (this fork's `govisa/self-hosted` branch, and `infra/` in the GoVisa
  monorepo): the deployment shape, the relay configuration, the migration tooling,
  the domain cutover order.
- **Not ours**: the application itself. Behaviour questions are upstream's, and
  they interact badly with a divergent fork.

Planning and the operational runbook live in the GoVisa monorepo at
`docs/plans/self-hosted-addy-io.md`.
