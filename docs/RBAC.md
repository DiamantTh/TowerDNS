# Rollen- und Berechtigungsmodell

## Grundprinzip

Rechte werden explizit, granular und zentral in der Application-Schicht geprueft (`TowerDNS\Application\Services\AuthorizationService`). Die UI darf nur darstellen, nie autorisieren. Ein Benutzer erhaelt keine impliziten Vollrechte durch reinen Provider- oder Zonenzugriff.

## Permission-Katalog

Alle Permissions sind als Enum-Cases in `TowerDNS\Domain\Auth\Permission` definiert.

### Zonen
- `zone.list`, `zone.read`, `zone.create`, `zone.update`, `zone.delete`

### Records
- `record.read`, `record.create`, `record.update`, `record.delete`

### DNSSEC (separat abgesichert)
- `dnssec.status.read`
- `dnssec.action.execute`

### Provider-Verwaltung (separat abgesichert)
- `provider.credentials.manage`
- `provider.config.manage`

### Benutzer- und Rollenverwaltung (separat abgesichert)
- `user.manage`
- `role.manage`

### System
- `system.settings.manage`

## Empfohlene Rollen

| Rolle         | Permissions                                                                                                        |
|---------------|--------------------------------------------------------------------------------------------------------------------|
| `viewer`      | `zone.read`, `record.read`, `dnssec.status.read`                                                                   |
| `editor`      | viewer + `zone.create`, `zone.update`, `record.create`, `record.update`, `record.delete`                          |
| `dnssec_op`   | viewer + `dnssec.action.execute`                                                                                   |
| `provider_op` | `provider.credentials.manage`, `provider.config.manage`                                                            |
| `iam_admin`   | `user.manage`, `role.manage`                                                                                       |
| `superadmin`  | alle Permissions                                                                                                   |

DNSSEC-, Provider- und IAM-Aktionen werden bewusst nicht in `editor` gebuendelt, sondern an separate Rollen gebunden.

## Durchsetzung

```php
$service->createZone($user, 'powerdns', 'example.com');
// 1. AuthorizationService::assert($user, Permission::ZONE_CREATE)
// 2. ProviderRegistry::get('powerdns')
// 3. Capability-Check: zone.create
// 4. DnsNameValidator::normalise('example.com')
// 5. Adapter-Aufruf
```

UI- und API-Layer rufen ausschliesslich Application-Services auf und erben damit die zentrale Pruefung.
