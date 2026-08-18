# IntraVox vs SharePoint Online: vergelijking voor beslissers

> **Documenttype**: Architectuur-overweging
> **Doel**: Beslissers helpen kiezen tussen SharePoint Online (moderne pagina's) en IntraVox als intranetplatform — inclusief een volledige concept-mapping en de dimensies soevereiniteit, regie en wetgeving die deze keuze steeds vaker bepalen
> **Doelgroep**: CIO's, IT-managers, informatie-/securitymanagers, inkoop

---

## Managementsamenvatting

SharePoint Online en IntraVox leveren allebei een modern, widget-gebaseerd intranet. De functionele overlap is groot — het fundamentele verschil is **waar je data staat, wie het platform bestuurt en welke jurisdictie geldt**.

| Aspect | SharePoint Online | IntraVox (op Nextcloud) |
|--------|-------------------|-------------------------|
| **Leveringsmodel** | Alleen SaaS, Microsoft-cloud | Self-hosted, EU-hoster of on-premises — jouw keuze |
| **Jurisdictie** | Amerikaans moederbedrijf (CLOUD Act van toepassing) | De jurisdictie die jij kiest |
| **Broncode** | Proprietair, gesloten | Open source (AGPL-3.0), auditeerbaar |
| **Content-opslag** | Proprietaire SharePoint content-databases | Gewone bestanden + JSON in een Nextcloud Team folder |
| **Ecosysteem** | Microsoft 365 / Viva / Power Platform | Nextcloud-ecosysteem (Files, Talk, Agenda, Office) |
| **Regie op upgrades** | Evergreen — Microsoft bepaalt wanneer features veranderen | Jij bepaalt wanneer je upgradet |
| **Exitstrategie** | Migratieproject; content zit vast in platform-formaat | Content is bestanden en JSON in mappen; ZIP-export ingebouwd |

**In één zin**: kies SharePoint Online als je organisatie strategisch aan het Microsoft 365-ecosysteem is verbonden en de afhankelijkheid van Amerikaanse cloud accepteert; kies IntraVox als digitale soevereiniteit, regie over data en juridische zekerheid beslissingscriteria zijn, of als je al Nextcloud draait (of dat van plan bent).

---

## Concept-mapping

Voor lezers die SharePoint kennen: zo vertalen de concepten zich naar IntraVox.

### Platform-concepten

| SharePoint Online | IntraVox | Toelichting |
|-------------------|----------|-------------|
| Communication site | IntraVox-instance / top-level paginaboom | IntraVox is broadcast-georiënteerd, zoals een communication site |
| Site page (moderne pagina) | Pagina | In IntraVox is een pagina een map met een JSON-bestand — zie [Architectuur-overzicht](overview.nl.md) |
| Web part | Widget | Zelfde bouwblok-model |
| Sectie-layouts (1–3 kolommen, verticale sectie) | Rijen, kolommen en zijkolommen | Inclusief inklapbare secties |
| Site-navigatie / megamenu | Navigatie-editor met megamenu | Plus breadcrumbs en een paginaboom |
| Hub sites | Paginahiërarchie (max. 5 niveaus) | IntraVox gebruikt één boom in plaats van gefedereerde site collections |
| Paginatemplates | Templates | Elke pagina is als template op te slaan; template-API beschikbaar |
| Meertalige pagina's | Meertalige content | Taal volgt de Nextcloud-taal van de gebruiker, met fallback-meldingen |

### Web parts → widgets

| SharePoint web part | IntraVox-widget | Dekking |
|---------------------|-----------------|---------|
| Text | Text (rich-text-editor) | ✅ Volledig |
| Image | Image | ✅ Volledig |
| Quick links | Links | ✅ Volledig |
| News | News (map-als-feed) | ✅ Volledig |
| People | People (uit Nextcloud-groepen) | ✅ Volledig |
| Events | Calendar (Nextcloud Agenda-integratie) | ✅ Volledig |
| File viewer / Document library | File en File Story | ✅ Volledig — File Story toont een map als documentstroom |
| Stream / YouTube | Video | ✅ Volledig |
| Image gallery | Photo Story | ✅ Volledig — met EXIF, kaarten en tijdlijn |
| Divider / Spacer | Divider / Spacer | ✅ Volledig |
| RSS | Feed | ✅ Volledig — leest ook Moodle-, Jira-, ICS- en SharePoint-feeds |
| Hero | Rij-layouts + Image/Links-widgets | 🟡 Deels — geen dedicated hero-widget |
| Highlighted content | News- + Feed-widgets | 🟡 Deels — geen query-gebaseerde rollup |
| Power BI-, Forms-, Power Apps-embeds | — | ❌ Ecosysteem-specifiek; geen equivalent |

### Publicatie & levenscyclus

| SharePoint Online | IntraVox | Toelichting |
|-------------------|----------|-------------|
| Concept / gepubliceerd | Draft / gepubliceerd | Zelfde model |
| Paginaplanning | Publiceren op / Verlopen op | IntraVox ondersteunt ook een vervaldatum |
| Check-out | Page locking | Voorkomt gelijktijdig bewerken |
| Versiegeschiedenis | Paginaversiebeheer | Geërfd van Nextcloud Files-versies; preview en herstel |
| Pagina-goedkeuring (Power Automate) | Approval-workflow-scenario | Zie [Scenario's](../admin/scenarios.nl.md) — op basis van GroupFolder-ACL, geen aparte workflow-engine |
| Comments en likes | Engagement: comments en reacties | Per instance configureerbaar; zie [Engagement](../admin/engagement.nl.md) |
| News digest / auto-news | Persoonlijke RSS-feeds met token-authenticatie | Ander mechanisme, zelfde doel: op de hoogte blijven |
| Delen met externe gebruikers | Publiek delen met link-tokens | Anonieme paginadeling |

### Rechten & governance

| SharePoint Online | IntraVox | Toelichting |
|-------------------|----------|-------------|
| Site owner / member / visitor | Admin / Manager / Editor / User | Zie [Autorisatie](../admin/authorization.nl.md) |
| Rechten op item- en bibliotheekniveau | GroupFolder-ACL per map/pagina | Fijnmazig, groepsgebaseerd |
| Audience targeting | ACL-gebaseerde zichtbaarheid | Andere filosofie: IntraVox beperkt *toegang*, SharePoint filtert *presentatie* — met ACL kunnen onbevoegden de content überhaupt niet zien |
| Microsoft Search | Nextcloud unified search | Pagina's worden geïndexeerd zoals andere Nextcloud-content |
| Microsoft Graph / SharePoint REST API | IntraVox REST API (OpenAPI) | Zie [API-referentie](api-reference.nl.md) |
| Purview (retentie, DLP, audit) | Nextcloud audit-log, retentie- en workflow-apps | 🟡 Deels — Nextcloud biedt bouwstenen, geen geïntegreerde compliance-suite |
| Migratie-tooling | Confluence-import, ZIP-export/-import, MetaVox-metadata | SharePoint-pagina-import is niet ingebouwd; de Feed-widget kan tijdens de overgang SharePoint-feeds tonen |

### Wat SharePoint Online biedt en IntraVox niet

Eerlijkheid vereist dat we dit expliciet benoemen:

- **Viva Connections / Viva Engage** — een geïntegreerde employee-experience-laag bovenop het intranet
- **Power Platform** — low-code-automatisering en ingebedde apps in pagina's
- **Purview** — een geïntegreerde suite voor compliance, retentie en eDiscovery
- **Copilot** — AI-features ingebed in het platform (met bijbehorende dataverwerkings-implicaties; Nextcloud biedt met Nextcloud Assistant self-hosted AI als alternatief)
- **Omvang van het ecosysteem** — third-party web parts, consultants en trainingsmateriaal

### Wat IntraVox (op Nextcloud) biedt en SharePoint Online niet

- **Vrijheid van deployment** — on-premises, private cloud of een (EU-)hoster naar keuze
- **Open source** — AGPL-3.0, code is auditeerbaar, geen black box
- **Content als bestanden** — elke pagina is een map met JSON en media in Nextcloud Files; leesbaar zonder IntraVox, geversioneerd en geback-upt zoals elk bestand
- **Regie op upgrades** — geen gedwongen evergreen-wijzigingen aan je intranet-UI
- **Eén geïntegreerd platform zonder per-gebruiker cloudlicenties** — IntraVox draait in de Nextcloud die je al beheert
- **Federatie** — de File Story- en Photo Story-widgets werken over gefedereerde Nextcloud-instances heen

---

## Soevereiniteit, regie en wetgeving

Voor veel (semi-)publieke organisaties beslist dit hoofdstuk, niet de feature-tabellen, de keuze.

### Jurisdictie en de CLOUD Act

SharePoint Online wordt geëxploiteerd door Microsoft, een Amerikaans bedrijf. De Amerikaanse **CLOUD Act (2018)** stelt Amerikaanse autoriteiten in staat om Amerikaanse aanbieders te verplichten data af te geven, *ongeacht waar die data staat* — dus ook in EU-datacenters. Microsofts **EU Data Boundary** (afgerond februari 2025) houdt de verwerking van klantdata binnen de EU, maar verandert niets aan de jurisdictionele realiteit van een Amerikaans moederbedrijf.

IntraVox draait waar jij Nextcloud draait. Gehost op eigen hardware of bij een EU-aanbieder zonder Amerikaanse moeder valt je intranet-content uitsluitend onder EU-/nationale jurisdictie.

### AVG en internationale doorgifte

- **SharePoint Online**: rechtmatige doorgifte naar de VS rust momenteel op het **EU-VS Data Privacy Framework** (2023). De voorgangers (Safe Harbor, Privacy Shield) zijn beide door het EU-Hof ongeldig verklaard — laatstelijk in *Schrems II* (2020). Er lopen juridische procedures tegen het DPF; een derde ongeldigverklaring zet de doorgiftegrondslag van Amerikaanse clouddiensten opnieuw op losse schroeven. De Rijksoverheid mitigeert dit met onderhandelde aanpassingen en periodieke **DPIA's via SLM Rijk** — een inspanning die een individuele organisatie niet kan repliceren.
- **IntraVox**: met EU- of on-premises-hosting is er **geen doorgifte naar een derde land** voor je intranet-content. De AVG-verwerkersketen is kort en controleerbaar: jijzelf, en hooguit je hoster.

### NIS2, BIO en overheidsbeleid

- **NIS2** (in Nederland: de Cyberbeveiligingswet) maakt besturen expliciet verantwoordelijk voor ketenrisico's. Een gesloten SaaS-platform is lastiger te beoordelen dan een open-source-stack die je kunt auditen.
- De **BIO** (Baseline Informatiebeveiliging Overheid) en het kabinetsbeleid rond **digitale autonomie** vragen expliciet om exitstrategieën en om het verminderen van afhankelijkheid van niet-EU-cloudaanbieders. De Tweede Kamer heeft herhaaldelijk aangedrongen op minder afhankelijkheid van Amerikaanse clouddiensten voor overheidsdata.
- De **EU Data Act** (van toepassing sinds september 2025) versterkt het recht om van clouddienst te wisselen — een erkenning dat exitkosten een reëel, gereguleerd risico zijn.

### Regie

| Vraag | SharePoint Online | IntraVox |
|-------|-------------------|----------|
| Wie bepaalt wanneer het platform verandert? | Microsoft (evergreen) | Jij |
| Wie kan technisch bij de content? | Microsoft (operator) | Alleen je eigen organisatie/hoster |
| Kun je de code auditen? | Nee | Ja (AGPL-3.0) |
| Kun je op de huidige versie blijven draaien? | Nee | Ja |
| Hoe ziet exit eruit? | Migratieproject uit proprietaire formaten | Content is al bestanden + JSON; ZIP-export ingebouwd |
| Waar leeft de authenticatie? | Entra ID (Microsoft-cloud) | Je eigen Nextcloud (LDAP/SAML/OIDC naar keuze) |

### Een eerlijke kanttekening

Soevereiniteit is niet gratis. Self-hosting betekent dat jij (of je hoster) eigenaar bent van beschikbaarheid, back-ups, patching en security-operations — werk dat Microsoft in SaaS onzichtbaar doet. Organisaties zonder die capaciteit doen er goed aan een Nextcloud-hostingpartner in de vergelijking mee te nemen in plaats van uit te gaan van kale self-hosting.

---

## Beslissingskader

**SharePoint Online past wanneer:**

- Je organisatie strategisch verbonden is aan Microsoft 365 en Viva
- Power Platform-integratie in intranetpagina's een harde eis is
- Een geïntegreerde compliance-suite (Purview) vereist én al gelicentieerd is
- Afhankelijkheid van Amerikaanse cloud een geaccepteerd, bestuurlijk gedragen risico is

**IntraVox past wanneer:**

- Digitale soevereiniteit, dataresidentie of jurisdictie beslissingscriteria zijn (overheid, zorg, onderwijs, vitale infrastructuur)
- Je al Nextcloud draait, of één samenwerkingsplatform onder eigen regie wilt
- Je een aantoonbare, goedkope exitstrategie wilt (content = bestanden + JSON)
- Voorspelbare licentiekosten zonder per-gebruiker cloudabonnementen tellen
- Je DPIA/risicoanalyse doorgifte naar Amerikaanse cloud aanmerkt als restrisico dat je wilt elimineren in plaats van mitigeren

**Hybride realiteit**: veel organisaties draaien beide tijdens een overgang. De Feed-widget van IntraVox leest SharePoint-feeds, zodat een Nextcloud-intranet SharePoint-content kan tonen terwijl de migratie loopt.

---

## Conclusie

Functioneel dekt IntraVox de kern van SharePoint Online moderne pagina's — pagina's, widgets, nieuws, planning, versiebeheer, rechten, meertalige content, engagement — binnen Nextcloud. SharePoint Online voegt daar een ecosysteem aan toe (Viva, Power Platform, Purview) dat IntraVox niet probeert na te bouwen.

De echte keuze is architecturaal: **een feature-rijk platform in andermans jurisdictie, of een gefocust intranet onder eigen regie**. Voor organisaties waar soevereiniteit, AVG-zekerheid, NIS2-verantwoordelijkheid en exitstrategie zwaar wegen, maakt IntraVox van het intranet geen cloudafhankelijkheid maar content die je simpelweg bezit: bestanden en JSON in je eigen Nextcloud.

---

## Referenties

**IntraVox-documentatie**
- [Architectuur-overzicht](overview.nl.md) — hoe IntraVox pagina's als mappen in Nextcloud opslaat
- [Autorisatie](../admin/authorization.nl.md) — rollen, permissies, GroupFolder-ACL
- [Beveiliging](../admin/security.nl.md) — beveiligingsmodel en sanitization-lagen
- [Export & Import](../admin/export-import.nl.md) — exitstrategie in de praktijk

**Extern**
- [Microsoft EU Data Boundary](https://www.microsoft.com/en-us/trust-center/privacy/european-data-boundary-eudb)
- [Adequaatheidsbesluit EU-VS Data Privacy Framework](https://commission.europa.eu/law/law-topic/data-protection/international-dimension-data-protection/eu-us-data-transfers_en)
- [SLM Rijk / Privacy Company DPIA's op Microsoft 365](https://slmmicrosoftrijk.nl/)
- [NIS2-richtlijn](https://digital-strategy.ec.europa.eu/en/policies/nis2-directive)
- [EU Data Act](https://digital-strategy.ec.europa.eu/en/policies/data-act)
