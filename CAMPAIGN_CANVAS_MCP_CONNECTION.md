# Campaign Canvas MCP Connection

Diese zwei Forks arbeiten zusammen, um Campaign-Events und Connections agentenfreundlich über MCP editierbar zu machen.

---

## Fork 1: `damianhunziker/mautic` (PHP Backend)

**Branch:** `feat/campaign-canvas-api`

**Ziel:** Neue granulare REST-Endpunkte bereitstellen, die einzelne Operationen auf Campaign-Events, Connections und Canvas erlauben – ohne jedes Mal das gesamte Campaign-Objekt senden zu müssen.

### Neue Endpunkte

| Endpunkt | Methode | Beschreibung |
|---|---|---|
| `/api/campaigns/events/types` | GET | Alle verfügbaren Event-Typen (Actions/Decisions/Conditions) mit Properties, FormType, ConnectionRestrictions |
| `/api/campaigns/{id}/events` | POST | Ein Event in einer Campaign anlegen (tempId wird automatisch generiert, Canvas wird aktualisiert) |
| `/api/campaigns/events/{eventId}` | PUT | Ein Event aktualisieren (Properties, Trigger, etc.) |
| `/api/campaigns/events/{eventId}` | DELETE | Ein Event löschen (optional mit Redirect), Canvas Nodes/Connections bereinigen |
| `/api/campaigns/{id}/connections` | POST | Eine Connection zwischen zwei Events hinzufügen inkl. Parent/Child-Beziehung |
| `/api/campaigns/{id}/connections` | DELETE | Eine Connection entfernen |
| `/api/campaigns/{id}/canvas` | GET | Canvas-Layout (Nodes + Connections) abrufen |
| `/api/campaigns/{id}/canvas` | PUT | Komplettes Canvas ersetzen |

### Neue Datei

**`app/bundles/CampaignBundle/Controller/Api/CampaignCanvasApiController.php`**

Implementiert das "Read-Modify-Write"-Pattern:
1. Campaign mit aktuellen Events + Canvas laden
2. Die spezifische Änderung vornehmen (Event hinzufügen/löschen/ändern, Connection hinzufügen/entfernen)
3. Die bestehenden `CampaignModel::setEvents()` und `CampaignModel::setCanvasSettings()` mit den vollständigen Arrays aufrufen
4. Campaign persistieren

Der Controller extendet `CommonApiController` und nutzt per Dependency Injection `CampaignModel`, `EventModel` und `EventCollector`.

### Geänderte Datei

**`app/bundles/CampaignBundle/Config/config.php`** – 8 neue API-Routen registriert.

---

## Fork 2: `damianhunziker/mantic-MCP` (TypeScript MCP Server)

**Branch:** `feat/granular-campaign-tools`

**Ziel:** Agentenfreundliche MCP-Tools, die die neuen granularen API-Endpunkte nutzen.

### Neue Tools (11 Stück)

| Tool | Beschreibung | Nutzt neuen Endpunkt? |
|---|---|---|
| `list_event_types` | Alle verfügbaren Event-Typen mit Konfiguration abfragen | ✅ GET /campaigns/events/types |
| `create_event` | Event in Campaign anlegen | ✅ POST /campaigns/{id}/events |
| `update_event` | Event-Eigenschaften ändern | ✅ PUT /campaigns/events/{eventId} |
| `delete_event` | Event löschen (mit optionalem Redirect) | ✅ DELETE /campaigns/events/{eventId} |
| `get_events` | Alle Events einer Campaign abrufen | Benutzt GET /campaigns/{id} |
| `connect_events` | Connection zwischen zwei Events erstellen | ✅ POST /campaigns/{id}/connections |
| `disconnect_events` | Connection entfernen | ✅ DELETE /campaigns/{id}/connections |
| `get_canvas` | Canvas-Layout abrufen | ✅ GET /campaigns/{id}/canvas |
| `update_canvas` | Canvas komplett ersetzen | ✅ PUT /campaigns/{id}/canvas |
| `update_campaign` | Campaign-Metadaten ändern | PATCH /campaigns/{id}/edit (existing) |
| `delete_campaign` | Campaign löschen | DELETE /campaigns/{id}/delete (existing) |

### Erweiterte Interfaces (`src/types/campaigns.ts`)

- `MauticCampaign` – um `events`, `canvasSettings`, `allowRestart`, `republishBehavior`, `lists`, `forms` ergänzt
- `MauticCampaignEvent` – neu: Vollständiges Event-Interface mit allen Trigger-Feldern
- `MauticCanvasSettings` – neu: `{ nodes: [...], connections: [...] }`
- `MauticCanvasNode` – neu: `{ id, positionX, positionY }`
- `MauticCanvasConnection` – neu: `{ sourceId, targetId, anchors: { source, target } }`
- `EventTypeConfig` – neu: Für die Event-Type-Discovery

### Geänderte Dateien

- `src/tools/campaigns.ts` – +421/-28 Zeilen
- `src/types/campaigns.ts` – Vollständig erweitert

---

## Architektur: Read-Modify-Write

```
Agent (LLM)
  │
  ├── list_event_types ─────► GET /api/campaigns/events/types
  │
  ├── create_event ──────────► POST /api/campaigns/{id}/events
  │     │                         ├── load campaign + events + canvas
  │     │                         ├── generate tempId
  │     │                         ├── add event + node
  │     │                         ├── setEvents(campaign, allEvents, allCanvas, [])
  │     │                         ├── saveEntity(campaign)
  │     │                         └── setCanvasSettings(campaign, canvas)
  │     │
  ├── connect_events ────────► POST /api/campaigns/{id}/connections
  │     │                         ├── load campaign + events + canvas
  │     │                         ├── add connection
  │     │                         ├── setEvents(campaign, allEvents, allCanvas, [])
  │     │                         ├── saveEntity(campaign)
  │     │                         └── setCanvasSettings(campaign, canvas)
  │     │
  └── delete_event ──────────► DELETE /api/campaigns/events/{eventId}
                                  ├── load campaign + events + canvas
                                  ├── remove event, node, connections
                                  ├── setEvents(campaign, remainingEvents, cleanedCanvas, deletedEvents)
                                  ├── saveEntity(campaign)
                                  └── setCanvasSettings(campaign, canvas)
```

Der Agent muss **nie** selbst tempIds vergeben oder Canvas-JSON manuell bauen. Der Backend-Controller übernimmt das vollständig.

---

## Agentenfreundlicher Workflow (Beispiel)

```
# 1. Verfügbare Event-Typen abfragen
list_event_types()

# 2. Campaign anlegen (nur Metadaten, kein Automation-JSON nötig)
create_campaign(name: "Welcome Series")

# 3. Events einzeln anlegen (Canvas wird automatisch aktualisiert)
create_event(campaignId: 1, name: "Send Email", type: "email.send", eventType: "action",
             properties: {email: 42}, triggerMode: "immediate")
create_event(campaignId: 1, name: "Add Points", type: "lead.changepoints", eventType: "action",
             properties: {points: 10}, triggerMode: "interval", triggerInterval: 1,
             triggerIntervalUnit: "d")

# 4. Connections setzen
connect_events(campaignId: 1, sourceId: 1, targetId: 2, anchorSource: "yes")

# 5. Canvas abrufen zum Prüfen
get_canvas(campaignId: 1)

# 6. Event löschen mit Redirect
delete_event(eventId: 2, redirectEventId: 3)
```

Der **Unterschied zum alten `create_campaign_with_automation`**: Der Agent muss nicht mehr das gesamte Event-Array + Canvas-JSON in einem Rutsch korrekt hinkriegen, sondern kann Schritt für Schritt vorgehen und Zwischenergebnisse prüfen.
