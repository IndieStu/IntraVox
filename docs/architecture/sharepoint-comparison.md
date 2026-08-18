# IntraVox vs SharePoint Online: Comparison for Decision Makers

> **Document type**: Architectural consideration
> **Purpose**: Help decision makers choose between SharePoint Online (modern pages) and IntraVox as their intranet platform — including a full concept mapping, and the sovereignty, control, and legal dimensions that increasingly drive this choice
> **Audience**: CIOs, IT managers, information/security officers, procurement

---

## Executive Summary

SharePoint Online and IntraVox both deliver a modern, widget-based intranet. The functional overlap is large — the fundamental difference is **where your data lives, who controls the platform, and which jurisdiction applies**.

| Aspect | SharePoint Online | IntraVox (on Nextcloud) |
|--------|-------------------|-------------------------|
| **Delivery model** | SaaS only, Microsoft cloud | Self-hosted, EU hoster, or on-premises — your choice |
| **Jurisdiction** | US parent company (CLOUD Act applies) | The jurisdiction you choose |
| **Source code** | Proprietary, closed | Open source (AGPL-3.0), auditable |
| **Content storage** | Proprietary SharePoint content databases | Plain files + JSON in a Nextcloud Team folder |
| **Ecosystem** | Microsoft 365 / Viva / Power Platform | Nextcloud ecosystem (Files, Talk, Calendar, Office) |
| **Upgrade control** | Evergreen — Microsoft decides when features change | You decide when to upgrade |
| **Exit strategy** | Migration project; content locked in platform format | Content is files and JSON in folders; ZIP export built in |

**In one sentence**: choose SharePoint Online when your organization is committed to the Microsoft 365 ecosystem and accepts US cloud dependency; choose IntraVox when digital sovereignty, data control, and regulatory certainty are decision criteria, or when you already run (or plan to run) Nextcloud.

---

## Concept Mapping

For readers who know SharePoint: this is how its concepts translate to IntraVox.

### Platform Concepts

| SharePoint Online | IntraVox | Notes |
|-------------------|----------|-------|
| Communication site | IntraVox instance / top-level page tree | IntraVox is broadcast-oriented, like a communication site |
| Site page (modern page) | Page | In IntraVox, a page is a folder with a JSON file — see [Architecture Overview](overview.md) |
| Web part | Widget | Same building-block model |
| Section layouts (1–3 columns, vertical section) | Rows, columns, and side columns | Including collapsible sections |
| Site navigation / megamenu | Navigation editor with megamenu | Plus breadcrumbs and a page tree |
| Hub sites | Page hierarchy (max. 5 levels) | IntraVox uses one tree instead of federated site collections |
| Page templates | Templates | Save any page as a template; template API available |
| Multilingual pages | Multi-language content | Language follows the user's Nextcloud language, with fallback notices |

### Web Parts → Widgets

| SharePoint web part | IntraVox widget | Coverage |
|---------------------|-----------------|----------|
| Text | Text (rich text editor) | ✅ Full |
| Image | Image | ✅ Full |
| Quick links | Links | ✅ Full |
| News | News (folder-as-feed) | ✅ Full |
| People | People (from Nextcloud groups) | ✅ Full |
| Events | Calendar (Nextcloud Calendar integration) | ✅ Full |
| File viewer / Document library | File and File Story | ✅ Full — File Story renders a folder as a document stream |
| Stream / YouTube | Video | ✅ Full |
| Image gallery | Photo Story | ✅ Full — with EXIF, maps, and timeline |
| Divider / Spacer | Divider / Spacer | ✅ Full |
| RSS | Feed | ✅ Full — also reads Moodle, Jira, ICS, and SharePoint feeds |
| Hero | Row layouts + Image/Links widgets | 🟡 Partial — no dedicated hero widget |
| Highlighted content | News + Feed widgets | 🟡 Partial — no query-based rollup |
| Power BI, Forms, Power Apps embeds | — | ❌ Ecosystem-specific; no equivalent |

### Publishing & Lifecycle

| SharePoint Online | IntraVox | Notes |
|-------------------|----------|-------|
| Draft / published | Draft / published | Same model |
| Page scheduling | Publish on / Expire on | IntraVox also supports expiry |
| Check-out | Page locking | Prevents concurrent edits |
| Version history | Page versioning | Inherited from Nextcloud Files versions; preview and restore |
| Page approval (Power Automate) | Approval workflow scenario | See [Scenarios](../admin/scenarios.md) — GroupFolder-ACL based, no separate workflow engine |
| Comments and likes | Engagement: comments and reactions | Configurable per instance; see [Engagement](../admin/engagement.md) |
| News digest / auto-news | Personal RSS feeds with token authentication | Different mechanism, same "stay informed" goal |
| Sharing with external users | Public sharing with link tokens | Anonymous page sharing |

### Permissions & Governance

| SharePoint Online | IntraVox | Notes |
|-------------------|----------|-------|
| Site owner / member / visitor | Admin / Manager / Editor / User | See [Authorization](../admin/authorization.md) |
| Item- and library-level permissions | GroupFolder ACL per folder/page | Fine-grained, group-based |
| Audience targeting | ACL-based visibility | Different philosophy: IntraVox restricts *access*, SharePoint filters *presentation* — with ACL, unauthorized users cannot see the content at all |
| Microsoft Search | Nextcloud unified search | Pages are indexed like other Nextcloud content |
| Microsoft Graph / SharePoint REST API | IntraVox REST API (OpenAPI) | See [API Reference](api-reference.md) |
| Purview (retention, DLP, audit) | Nextcloud audit log, retention & workflow apps | 🟡 Partial — Nextcloud offers building blocks, not an integrated compliance suite |
| Migration tooling | Confluence import, ZIP export/import, MetaVox metadata | SharePoint page import is not built in; the Feed widget can consume SharePoint feeds during transition |

### What SharePoint Online Offers That IntraVox Does Not

Fairness requires naming these explicitly:

- **Viva Connections / Viva Engage** — an integrated employee-experience layer on top of the intranet
- **Power Platform** — low-code automation and embedded apps in pages
- **Purview** — an integrated compliance, retention, and eDiscovery suite
- **Copilot** — AI features embedded in the platform (with corresponding data-processing implications; Nextcloud offers self-hosted AI via Nextcloud Assistant as an alternative)
- **Scale of ecosystem** — third-party web parts, consultants, and training material

### What IntraVox (on Nextcloud) Offers That SharePoint Online Does Not

- **Deployment freedom** — on-premises, private cloud, or an (EU) hoster of your choice
- **Open source** — AGPL-3.0, code is auditable, no black box
- **Content as files** — every page is a folder with JSON and media in Nextcloud Files; readable without IntraVox, versioned and backed up like any file
- **Upgrade control** — no forced evergreen changes to your intranet UI
- **One integrated platform without per-user cloud licensing** — IntraVox runs inside the Nextcloud you already operate
- **Federation** — File Story and Photo Story widgets work across federated Nextcloud instances

---

## Sovereignty, Control, and Legislation

For many (semi-)public organizations this section, not the feature tables, decides the choice.

### Jurisdiction and the CLOUD Act

SharePoint Online is operated by Microsoft, a US company. The US **CLOUD Act (2018)** allows US authorities to compel US providers to hand over data *regardless of where that data is stored* — including in EU datacenters. Microsoft's **EU Data Boundary** (completed February 2025) keeps customer data processing within the EU, but does not change the jurisdictional reality of a US parent company.

IntraVox runs wherever you run Nextcloud. Hosted on your own hardware or at an EU provider without a US parent, your intranet content falls solely under EU/national jurisdiction.

### GDPR and International Transfers

- **SharePoint Online**: lawful transfer to the US currently rests on the **EU–US Data Privacy Framework** (2023). Its predecessors (Safe Harbor, Privacy Shield) were both invalidated by the EU Court of Justice — most recently in *Schrems II* (2020). Legal challenges against the DPF are ongoing; a third invalidation would again put the transfer basis of US cloud services in question. The Dutch government mitigates this with negotiated amendments and periodic **DPIAs via SLM Rijk**, an effort an individual organization cannot replicate.
- **IntraVox**: with EU or on-premises hosting there is **no third-country transfer** for your intranet content. The GDPR processor chain is short and inspectable: you, and at most your hoster.

### NIS2, BIO, and Government Policy

- **NIS2** (in the Netherlands: Cyberbeveiligingswet) makes boards explicitly accountable for supply-chain risk. A closed SaaS platform is harder to assess than an open-source stack you can audit.
- The **BIO** (Baseline Informatiebeveiliging Overheid) and Dutch government policy on **digital autonomy** explicitly ask for exit strategies and reduced dependency on non-EU cloud providers. Parliament has repeatedly urged reducing reliance on US cloud services for government data.
- The **EU Data Act** (applicable from September 2025) strengthens cloud-switching rights — an acknowledgment that exit costs are a real, regulated risk.

### Control (*regie*)

| Question | SharePoint Online | IntraVox |
|----------|-------------------|----------|
| Who decides when the platform changes? | Microsoft (evergreen) | You |
| Who can read the content, technically? | Microsoft (operator) | Only your own organization/hoster |
| Can you audit the code? | No | Yes (AGPL-3.0) |
| Can you keep running the current version? | No | Yes |
| What does exit look like? | Migration project out of proprietary formats | Content is already files + JSON; ZIP export built in |
| Where does authentication live? | Entra ID (Microsoft cloud) | Your Nextcloud (LDAP/SAML/OIDC of your choice) |

### An Honest Caveat

Sovereignty is not free. Self-hosting means you (or your hoster) own availability, backups, patching, and security operations — work Microsoft does invisibly in SaaS. Organizations without that capacity should weigh a Nextcloud hosting partner into the comparison rather than assuming bare self-hosting.

---

## Decision Framework

**SharePoint Online fits when:**

- Your organization is strategically committed to Microsoft 365 and Viva
- Power Platform integration in intranet pages is a hard requirement
- An integrated compliance suite (Purview) is required and already licensed
- US cloud dependency is an accepted, board-approved risk

**IntraVox fits when:**

- Digital sovereignty, data residency, or jurisdiction are decision criteria (government, healthcare, education, critical infrastructure)
- You already run Nextcloud, or want one collaboration platform under your own control
- You want a demonstrable, cheap exit strategy (content = files + JSON)
- Predictable licensing without per-user cloud subscriptions matters
- Your DPIA/risk assessment flags US cloud transfer as a residual risk to eliminate rather than mitigate

**Hybrid reality**: many organizations run both during a transition. IntraVox's Feed widget reads SharePoint feeds, so a Nextcloud-based intranet can surface SharePoint content while migration is under way.

---

## Conclusion

Functionally, IntraVox covers the core of SharePoint Online modern pages — pages, widgets, news, scheduling, versioning, permissions, multilingual content, engagement — inside Nextcloud. SharePoint Online adds an ecosystem (Viva, Power Platform, Purview) that IntraVox does not try to replicate.

The real choice is architectural: **a feature-rich platform in someone else's jurisdiction, or a focused intranet under your own control**. For organizations where sovereignty, GDPR certainty, NIS2 accountability, and exit strategy weigh heavily, IntraVox turns the intranet from a cloud dependency into content you simply own: files and JSON in your own Nextcloud.

---

## References

**IntraVox documentation**
- [Architecture Overview](overview.md) — how IntraVox stores pages as folders in Nextcloud
- [Authorization](../admin/authorization.md) — roles, permissions, GroupFolder ACL
- [Security](../admin/security.md) — security model and sanitization layers
- [Export & Import](../admin/export-import.md) — exit strategy in practice

**External**
- [Microsoft EU Data Boundary](https://www.microsoft.com/en-us/trust-center/privacy/european-data-boundary-eudb)
- [EU–US Data Privacy Framework adequacy decision](https://commission.europa.eu/law/law-topic/data-protection/international-dimension-data-protection/eu-us-data-transfers_en)
- [SLM Rijk / Privacy Company DPIAs on Microsoft 365](https://slmmicrosoftrijk.nl/)
- [NIS2 Directive](https://digital-strategy.ec.europa.eu/en/policies/nis2-directive)
- [EU Data Act](https://digital-strategy.ec.europa.eu/en/policies/data-act)
