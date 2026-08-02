# IntraVox autorisatie-model

IntraVox gebruikt Nextcloud's native GroupFolder-permissies voor autorisatie. Dat betekent dat toegangscontrole volledig wordt beheerd via Nextcloud's bestaande permissie-systeem — geen aparte permissie-configuratie nodig in IntraVox.

## Overzicht

```
+------------------------------------------------------------------+
|                       Nextcloud-server                            |
|  +--------------------------------------------------------------+ |
|  |                     GroupFolders-app                         | |
|  |  +----------------------------------------------------------+ |
|  |  |                 IntraVox-GroupFolder                     | |
|  |  |  +--------------------+  +--------------------+          | |
|  |  |  | Groep-permissies   |  |    ACL-regels      |          | |
|  |  |  | (basis-toegang)    |  |  (fijn-granulair)  |          | |
|  |  |  +--------------------+  +--------------------+          | |
|  |  +----------------------------------------------------------+ |
|  +--------------------------------------------------------------+ |
|                              |                                    |
|                              v                                    |
|  +--------------------------------------------------------------+ |
|  |                      IntraVox-app                            | |
|  |        PermissionService leest permissies                    | |
|  |        en handhaaft ze op alle operaties                     | |
|  +--------------------------------------------------------------+ |
+------------------------------------------------------------------+
```

## Permissie-typen

IntraVox respecteert de standaard Nextcloud-permissie-bits:

| Permissie | Bit | Beschrijving |
|-----------|-----|--------------|
| Lezen | 1 | Pagina's en content bekijken |
| Bijwerken | 2 | Bestaande pagina's bewerken |
| Aanmaken | 4 | Nieuwe pagina's maken |
| Verwijderen | 8 | Pagina's verwijderen |
| Delen | 16 | Vereist voor RSS-feed-toegang (publieke endpoints vereisen Lezen + Delen) |

## Hoe permissies werken

### 1. Basis-permissies (GroupFolder-groepen)

Wanneer een groep wordt toegevoegd aan de IntraVox-GroupFolder, ontvangen alle leden van die groep de geconfigureerde basis-permissies. Dit is de eerste laag van toegangscontrole.

Voorbeeld:

- Groep "Medewerkers" heeft Lezen-permissie op IntraVox-folder
- Groep "Editors" heeft Lezen + Schrijven + Aanmaken
- Groep "Admins" heeft Alle permissies

### 2. ACL-regels (fijn-granulaire controle)

Met de GroupFolders "Advanced Permissions" (ACL) ingeschakeld kunnen beheerders specifiekere permissies op submappen instellen.

> **⚠️ De belangrijkste regel: ACL-regels kunnen toegang alleen _beperken_, nooit _toekennen_.**
>
> Een ACL-regel kan een permissie wégnemen die een gebruiker anders zou hebben, maar kan **geen permissie toevoegen die de groep van de gebruiker niet al op basis-niveau heeft**. De basis-groepspermissie is het *plafond*; ACL-regels kunnen dat alleen verlagen voor specifieke paden, nooit verhogen.
>
> Dit is standaard Nextcloud GroupFolders-gedrag — niet iets wat IntraVox bepaalt. IntraVox leest simpelweg de effectieve Nextcloud-permissie op elk bestand en elke folder.

**Wat dit in de praktijk betekent:**

- Als de enige groep van een gebruiker **Lezen** als basis-permissie heeft, dan werkt het via een ACL-regel toekennen van "Lezen + Schrijven" op één submap **niet** — het schrijfrecht wordt weggemaskeerd door het alleen-lezen-plafond. De gebruiker blijft overal alleen-lezen.
- Om iemand *sommige* secties wél en andere niet te laten bewerken, moet hun groep **Schrijven op basis-niveau** hebben, en gebruik je ACL-regels om schrijven te **verwijderen** op de secties die ze niet mogen bewerken.

Voorbeeld (beperkend model — de juiste manier):

- Basis: groep "Afdelings-editors" heeft **Lezen + Schrijven + Aanmaken** op de hele IntraVox-folder
- ACL op `/nl/afdelingen/sales` → schrijven verwijderen voor iedereen behalve de Sales-groep
- ACL op `/nl/afdelingen/hr` → schrijven verwijderen voor iedereen behalve de HR-groep
- Resultaat: elke editor schrijft alleen in de eigen afdeling, leest de rest

### 3. Permissie-overerving

Permissies worden van ouder- naar child-folders overgeërfd, altijd **naar beneden versmallend**:

- De **basis-groepspermissie is het maximum** dat een gebruiker ergens in de folder kan hebben. ACL-regels kunnen dat alleen per pad verlagen.
- Een child-folder kan nooit *meer* permissie hebben dan zijn ouder toekent.
- ACL-regels op een ouder-folder raken alle children eronder.
- Specifiekere regels (diepere paden) gaan vóór minder specifieke — maar altijd binnen het basis-plafond.

Omdat overerving bij Team folders (GroupFolders) top-down én aftrekkend werkt, is het juiste mentale model: **begin breed, neem dan wég** — niet "begin op slot, ken dan toe".

## Permissies opzetten

### Stap 1: maak GroupFolder

1. Ga naar Nextcloud-beheer → GroupFolders
2. Maak een folder met de naam "IntraVox"
3. Voeg groepen toe die toegang moeten hebben

### Stap 2: configureer basis-permissies

Stel voor elke groep het juiste permissie-niveau in:

| Groep | Aanbevolen permissies | Automatisch aangemaakt? |
|-------|------------------------|---------------------------|
| IntraVox Users | Lezen, Delen | Ja |
| IntraVox Editors | Lezen, Schrijven, Aanmaken | Ja |
| IntraVox Admins | Alles | Ja |
| Custom-groepen (bv. afdelingsmanagers) | Lezen, Schrijven, Aanmaken, Verwijderen, Delen | Nee — handmatig toevoegen |

> **Waarom Delen?** De RSS-feed is een publiek endpoint (geen gebruikers-sessie). GroupFolders vereist zowel Lezen als Delen-permissies voor folders om zichtbaar te zijn in publieke requests. Zonder Delen blijven feeds leeg.

### Stap 3: ACL inschakelen (optioneel)

Voor fijn-granulaire controle:

1. Schakel "Advanced Permissions" in op de GroupFolder
2. Navigeer naar submappen in Nextcloud Files
3. Klik op het share-icoon en configureer ACL-regels

### Voorbeeld: afdelings-gebaseerde toegang

```
IntraVox/
├── nl/
│   ├── afdelingen/
│   │   ├── hr/          → HR-groep: volledige toegang, anderen: lezen
│   │   ├── sales/       → Sales-groep: volledige toegang, anderen: lezen
│   │   ├── marketing/   → Marketing-groep: volledige toegang, anderen: lezen
│   │   └── it/          → IT-groep: volledige toegang, anderen: lezen
│   └── nieuws/          → Editors-groep: volledige toegang, anderen: lezen
└── en/
    └── (zelfde structuur)
```

### Voorbeeld: "alles lezen, alleen mijn sectie bewerken"

Een veelvoorkomend verzoek: een gebruiker moet **alle secties A, B en C lezen**, maar alleen
**sectie B** (hun afdeling) bewerken. De voor-de-hand-liggende-maar-foute reflex is de
gebruiker Lezen op basis-niveau geven en een "Schrijven"-ACL op sectie B toevoegen — **dat
werkt niet**, want een ACL kan geen schrijven toekennen boven een alleen-lezen basis (zie de
waarschuwing onder [ACL-regels](#2-acl-regels-fijn-granulaire-controle)).

De juiste, beperkende setup:

1. Zet de gebruiker in een groep die **Lezen + Schrijven** op basis-niveau van de
   IntraVox-folder heeft (bv. "IntraVox Editors", of een eigen groep "Sectie B Editors").
2. Schakel Advanced Permissions in op de folder.
3. Voeg ACL-regels toe die **schrijven verwijderen** op de secties die ze *niet* mogen bewerken:
   - Sectie A → schrijven verwijderen voor de groep (lezen laten staan)
   - Sectie C → schrijven verwijderen voor de groep (lezen laten staan)
   - Sectie B → ongewijzigd laten (basis-schrijven geldt)

```
IntraVox/
└── nl/
    ├── sectie-a/   → Sectie B Editors: basis-schrijven VERWIJDERD via ACL → alleen-lezen
    ├── sectie-b/   → Sectie B Editors: basis-schrijven geldt → bewerkbaar
    └── sectie-c/   → Sectie B Editors: basis-schrijven VERWIJDERD via ACL → alleen-lezen
```

Resultaat: de gebruiker leest A, B en C, maar kan alleen pagina's in B bewerken.

> **Doe dit niet** door de groep Lezen-alleen op basis-niveau te geven en een Schrijven-ACL
> op sectie B toe te voegen. Het schrijven wordt weggemaskeerd en de gebruiker is overal
> alleen-lezen — dit is de meest gemaakte Team-folder-permissie-fout.

## Permissie-checks in IntraVox

IntraVox checkt permissies op meerdere niveaus:

### API-niveau

Elke API-call valideert permissies vóór uitvoering:

- `GET /api/page` — vereist Lezen
- `PUT /api/page` — vereist Schrijven
- `POST /api/page` — vereist Aanmaken
- `DELETE /api/page` — vereist Verwijderen

### UI-niveau

De frontend past zich aan op basis van permissies:

- Bewerk-knoppen alleen zichtbaar bij Schrijfrechten
- Pagina-aanmaak-opties alleen bij Aanmaken-rechten
- Verwijder-opties alleen bij Verwijderen-rechten

### Navigatie

Navigatie-items worden gefilterd op basis van pagina-permissies — gebruikers zien alleen pagina's waartoe ze toegang hebben.

## Problemen oplossen

### Gebruiker ziet een pagina niet

1. Check of gebruiker lid is van een groep met toegang tot de IntraVox-GroupFolder
2. Check ACL-regels op het specifieke folder-pad
3. Verifieer dat het pagina-bestand op de verwachte locatie bestaat

### Gebruiker kan een pagina niet bewerken (terwijl een ACL schrijven toekent)

Dit is de meest voorkomende permissie-verwarring bij Team folders. Symptoom: je gaf een
gebruiker "Lezen + Schrijven" op één sectie via een ACL-regel, maar ze kunnen daar nog
steeds niet bewerken — de Bewerken-knop ontbreekt, of opslaan faalt.

**Oorzaak:** de *basis-groepspermissie* van de gebruiker is alleen-lezen, en **een ACL-regel
kan geen schrijven toekennen boven een alleen-lezen basis** (zie [ACL-regels](#2-acl-regels-fijn-granulaire-controle)
hierboven). De ACL wordt gecapt door het groepsplafond, dus Nextcloud rapporteert het
bestand als alleen-lezen en IntraVox verbergt terecht de Bewerken-knop.

**Oplossing — kies er één:**

1. **Zet de editors in een groep die Schrijven op basis-niveau heeft** (bv. de ingebouwde
   "IntraVox Editors", met Lezen + Schrijven + Aanmaken), en gebruik ACL-regels om schrijven
   te *verwijderen* op de secties die ze niet mogen bewerken. Dit is het aanbevolen model.
2. Of verhoog de basis-permissie van de bestaande groep tot Schrijven, en beperk die met
   ACL-regels weer op de alleen-lezen-secties.

**Zo verifieer je wat de gebruiker echt heeft:** draai op de server
`occ groupfolders:permissions <folderId> <pad> --test -u <gebruiker>` om de effectieve
permissie voor dat pad te zien. Toont het `+write` maar faalt bewerken toch, controleer dan
of de *groep* schrijven op basis-niveau heeft (de `--test`-output toont de ACL-regel, maar
de effectieve node-permissie wordt nog steeds gecapt door de groepsbasis).

### Gebruiker kan een pagina niet bewerken (andere oorzaken)

1. Verifieer dat de groep van de gebruiker Schrijfrechten heeft op de GroupFolder **op basis-niveau**
2. Check of ACL-regels Schrijven expliciet *verwijderen* op dat pad
3. Check ouder-folder-permissies (een child kan nooit meer dan zijn ouder, en nooit meer dan het basis-groepsplafond)

### RSS-feed van gebruiker is leeg

1. Check dat de groep van de gebruiker **Delen**-permissie heeft op de GroupFolder (basis-niveau)
2. Met ACL: verifieer Delen-permissie op de taal-folder (`nl/`, `en/`, etc.) en alle ouder-folders
3. Verifieer dat "Gebruikers toestaan via link en e-mail te delen" aan staat in Nextcloud-beheer → Delen
4. Zie [RSS-feed](../user/rss-feeds.md#beheerder-setup) voor de volledige setup-gids

### Navigatie toont pagina's die gebruiker niet kan openen

Dit zou niet moeten gebeuren bij correct geconfigureerde permissies. Check:

1. Navigatie-bestand-permissies versus pagina-bestand-permissies
2. Cache-issues — probeer Nextcloud-cache te wissen

## Technische implementatie

IntraVox berekent permissies **niet** zelf. Het leest de **effectieve Nextcloud-permissie**
op elk pagina-bestand en elke folder via de eigen gemounte view van de gebruiker, waarin
Nextcloud alle GroupFolder-basis-permissies en ACL-regels al heeft toegepast:

```php
// Schrijven per pagina wordt gegate op het JSON-bestand van de pagina zelf, via de
// ACL-bewuste view van de gebruiker. canWrite = de UPDATE-bit staat aan ÉN de node
// rapporteert isUpdateable() voor deze gebruiker.
$canWrite = ($file->getPermissions() & 2) !== 0 && $file->isUpdateable();
```

Dit is waarom een ACL-regel die schrijven *lijkt* toe te kennen (in de ACL-editor of in
`occ groupfolders:permissions … --test`) een pagina toch alleen-lezen kan laten: de
ACL-regel wordt vastgelegd, maar de **effectieve node-permissie** die Nextcloud aan IntraVox
geeft, is gecapt door de basis-permissie van de groep. IntraVox weerspiegelt getrouw wat
Nextcloud rapporteert — de oplossing zit dus altijd in de GroupFolder-basis-permissies +
ACL-configuratie, nooit in IntraVox zelf.

Omdat permissies per-gebruiker zijn en live uit de filesystem-view worden gelezen, worden ze
nooit tussen gebruikers gecachet: IntraVox herberekent `canWrite` bij elke read, zodat de
toegang van de ene gebruiker nooit naar een andere kan lekken.

## Best practices

1. **Gebruik groepen** — wijs permissies altijd aan groepen toe, niet aan individuele gebruikers
2. **Principle of least privilege** — begin met alleen-lezen en voeg permissies toe waar nodig
3. **Documenteer je structuur** — houd bij welke groepen toegang hebben tot wat
4. **Test grondig** — test na permissie-setup met gebruikers uit elke groep
5. **Regelmatige audits** — review groep-lidmaatschappen en ACL-regels periodiek
6. **RSS-feed vereist Delen** — de RSS-feed is een publiek endpoint. GroupFolders vereist zowel Lezen als Delen voor folders om zichtbaar te zijn in publieke (niet-geauthenticeerde) requests. Meldt een gebruiker een lege feed? Check dat hun groep Delen-permissie heeft op de relevante folders.
