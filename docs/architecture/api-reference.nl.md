# IntraVox-API-referentie

> **Let op:** de complete API-referentie is uitvoerig technisch en wordt in zijn geheel onderhouden in het Engels in de IntraVox-repository. Voor de actuele endpoint-specificatie, request-/response-schema's en migratie-voorbeelden, raadpleeg de [Engelse API-referentie](api-reference.md).

Op deze pagina vind je een Nederlandstalige inleiding tot de IntraVox-REST-API en pointers naar de relevante secties van de volledige Engelstalige referentie.

## Inleiding

IntraVox biedt een uitgebreide REST-API voor het beheren van pagina's, media, navigatie, comments, reacties, MetaVox-metadata, search, en meer. De API is bedoeld voor:

- **CMS-migraties** — content van SharePoint, Confluence of vergelijkbare systemen importeren
- **Custom integraties** — IntraVox koppelen aan externe systemen (HR, ticketing, LMS)
- **Bulk-operaties** — programmatisch grote aantallen pagina's beheren
- **Frontend-extensies** — eigen UI's bovenop de IntraVox-backend bouwen
- **Backup/restore** — geautomatiseerde content-exports en -imports

## Authenticatie

Alle endpoints vereisen HTTP-Basic-authenticatie met een Nextcloud-app-wachtwoord:

```bash
curl -u "username:app-password-token" \
  https://your-nextcloud.com/apps/intravox/api/pages
```

Maak een app-wachtwoord aan via **Nextcloud-instellingen → Beveiliging → Apparaten & sessies**.

## Base-URLs

Twee mounts, en het verschil is kleiner én groter dan de naam suggereert:

| Stijl | Base-URL | Wat er hangt |
|-------|----------|--------------|
| **App-mount** | `/apps/intravox/api/...` | Alle 171 gedocumenteerde routes |
| **OCS-mount** | `/ocs/v2.php/apps/intravox/api/v1/...` | Alleen de acht `/api/v1/*`-routes |

Twee dingen die je moet weten voor je hierop bouwt, beide gemeten tegen een
draaiende server en niet afgeleid uit de naam:

- **Er is géén OCS-envelop.** Ondanks het `/ocs/`-pad krijg je dezelfde kale JSON
  als op de app-mount — geen `{ocs:{meta,data}}`. Geen enkele IntraVox-controller
  extendt `OCSController`. Een client die de envelop verwacht, parseert mis.
- **De OCS-mount draagt alleen `/api/v1/*`.** Elk ander pad geeft daar 404 met een
  OCS-foutenvelop, `/api/health` inbegrepen. Voor een liveness-check gebruik je
  `https://your-nextcloud.com/apps/intravox/api/health`.

Wel verplicht op de OCS-mount: de header `OCS-APIRequest: true` bij writes. Zonder
die header weigert Nextcloud een POST/PUT/DELETE met 412 (`CSRF check failed`).
Op reads is hij onschadelijk — stuur hem altijd mee.

## Endpoint-categorieën

De Engelstalige referentie behandelt de volgende categorieën:

| Categorie | Doel |
|-----------|------|
| **Pages-API** | CRUD voor pagina's, layout, widgets |
| **Page-layout & widgets** | Widget-types, JSON-schema's, voorbeelden |
| **Media-API** | Upload, download en verwijderen van afbeeldingen, video, bestanden |
| **Translations-API** | Vertalingen aanmaken/koppelen/ontkoppelen; kandidaten en beschikbare talen (sinds 2.0) |
| **Versioning-API** | Pagina-versies tonen, preview, restore |
| **Comments-API** | Comments aanmaken, bewerken, verwijderen |
| **Reactions-API** | Emoji-reacties op pagina's en comments |
| **Analytics-API** | Pageviews, top-pagina's, engagement-metrics |
| **Bulk-operations-API** | Meerdere pagina's tegelijk verplaatsen, verwijderen, kopiëren |
| **Navigation & Footer-API** | Navigatie- en footer-structuur beheren |
| **Settings-API** | Admin- en gebruikers-instellingen |
| **Page-metadata-API** | Snelle lijst-/zoek-endpoints op pagina-metadata |
| **News-API** | News-widget-data, MetaVox-filtering, publicatie-datums |
| **Resources-API** | Externe-feed-connections (Jira, Moodle, SharePoint, enz.) |
| **Permissions-API** | Permissie-checks, GroupFolder-ACL-info |
| **MetaVox-integratie-API** | Metadata-velden ophalen en filteren |
| **Setup & demo-data-API** | App-setup-status, demo-content importeren |
| **Calendar-API** | Calendar-widget-events |
| **Search-API** | Pagina-zoeken |
| **Export/Import-API** | Volledige IntraVox-content-export en -import |
| **Error-codes** | Standaard-error-formaat en statuscodes |
| **Security** | CSRF, rate-limiting, sanitization |
| **Migration-tool-integration** | Aanbevolen flow voor SharePoint-/Confluence-migraties |

## Foutafhandeling

Twee dingen die automatische clients verrassen:

**Fouten dragen geen oorzaak meer.** Een mislukte aanroep geeft een vaste zin plus
een `errorId`:

```json
{ "success": false, "error": "Could not load comments.", "errorId": "err_68adf1c2" }
```

De echte oorzaak staat in het serverlog onder die id. Citeer de `errorId` in een
supportvraag, niet de tekst — die is voor elk geval hetzelfde. Bij import-endpoints
is er een uitzondering: validatiefouten (400) dragen een `errorCode` die stabiel en
vertaalbaar is, want die beschrijven de upload en geen interne staat.

**Herhaald mislukte authenticatie geeft 429 op élk endpoint.** Dat is Nextclouds
brute-force-bescherming, geen IntraVox-limiet, en hij geldt ook waar geen eigen
rate limit gedeclareerd is. Eenmaal getript blijft hij 429 geven, óók bij juiste
credentials, tot het venster verloopt. Een client die een fout token in een lus
herhaalt sluit zichzelf dus buiten in plaats van er doorheen te komen.

Behandel 401 als definitief: repareer de credential, herhaal niet.

## Snelstart

### Pagina ophalen

```bash
curl -u "username:app-password" \
  https://your-nextcloud.com/ocs/v2.php/apps/intravox/api/v1/pages/page-abc-123 \
  -H "OCS-APIRequest: true"
```

### Pagina-content updaten

```bash
curl -X PUT \
  -u "username:app-password" \
  -H "Content-Type: application/json" \
  -H "OCS-APIRequest: true" \
  -d '{"title":"Nieuwe titel","layout":{...}}' \
  https://your-nextcloud.com/ocs/v2.php/apps/intravox/api/v1/pages/page-abc-123
```

### Media uploaden (via WebDAV)

> Het pad hieronder bevat een taalcode (`nl`) en een pagina-slug (`welcome`);
> vervang beide door je eigen waarden. Media hoort bij één pagina in één taal.

```bash
# Upload bestand naar pagina-media-map via WebDAV
curl -u "username:app-password" \
  -T banner.png \
  https://your-nextcloud.com/remote.php/dav/files/username/IntraVox/nl/welcome/_media/banner.png
```

## Voor de complete referentie

Zie de [Engelse API-referentie](api-reference.md) voor:

- Volledige request-/response-schema's per endpoint
- HTTP-statuscodes en error-formaat
- Code-voorbeelden in cURL, JavaScript, Python en PHP
- Bulk-operatie-patterns en best-practices
- Rate-limiting-details en CSRF-instructies
- WebDAV-chunked-upload-flow voor grote bestanden
- Migratie-voorbeelden (SharePoint → IntraVox)

## Gerelateerd

- [Template-API-quickstart](template-api-quickstart.nl.md) — pagina's maken vanuit templates (in 5 minuten)
- [OpenAPI-tooling](openapi-tooling.nl.md) — Swagger UI, Postman, code-generatie
- [API-development-gids](api-development.nl.md) — eigen endpoints toevoegen
- [Autorisatie](../admin/authorization.md) — GroupFolder-ACL en permissie-model
- [Beveiliging](../admin/security.md) — CSRF, sanitization, audit-logging
