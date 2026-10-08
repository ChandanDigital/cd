# Chandan Digital AI for NVIDIA

Private WordPress plugin by [Chandan Digital](https://chandandigital.com/) that connects WordPress to NVIDIA-hosted AI models, including **Moonshot AI Kimi K3** (`moonshotai/kimi-k3`).

Version 1.1.1 · Requires WordPress 6.9+ (7.0+ recommended) and PHP 7.4+ · GPL-2.0-or-later · Manual updates only

## Deliverables

| # | Deliverable | Location |
|---|---|---|
| 1 | Installable plugin ZIP | [`dist/chandan-digital-ai-for-nvidia-v1.1.1.zip`](../../dist/chandan-digital-ai-for-nvidia-v1.1.1.zip) |
| 2 | Complete source code | [`chandan-digital-ai-for-nvidia/`](../../chandan-digital-ai-for-nvidia/) |
| 3 | API integration report | [api-integration-report.md](api-integration-report.md) |
| 4 | Security and privacy audit | [security-privacy-audit.md](security-privacy-audit.md) |
| 5 | Installation guide | [installation-guide.md](installation-guide.md) |
| 6 | API configuration guide | [api-configuration-guide.md](api-configuration-guide.md) |
| 7 | Testing report | [testing-report.md](testing-report.md) |
| | Test harness and raw results | [`tests/chandan-digital-ai-for-nvidia/`](../../tests/chandan-digital-ai-for-nvidia/) |

ZIP SHA-256: `ae536ce4cc76c68609a9284e43478688991526a7a9388bdc7405512852c7ccdd`

## Dashboard

Admin menu **NVIDIA AI** with seven tabs: Overview, NVIDIA API Settings, AI Models, Kimi K3 Settings, AI Playground, API Diagnostics, and Privacy & Security.

![AI Playground streaming a Kimi K3 reply](screenshots/05-playground-stream.png)

More screenshots are in [screenshots/](screenshots/). They were taken on a test site that uses a local mock of the NVIDIA API, so the replies shown are test replies.

## Important notes

- Live tests against NVIDIA's real API were **not** possible in the build environment. Please run the checks in the API Configuration Guide on your site.
- Revoke the API key that was shared earlier in conversation, and create a new one.
- Deactivate the original "AI Provider for NVIDIA" plugin before activating this one.
