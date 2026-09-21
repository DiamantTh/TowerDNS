<script lang="ts">
    import DnsZonesPage from '../../themes/default/src/components/DnsZonesPage.svelte';
    import { provideI18n } from '../../themes/default/src/lib/i18n';
    import type { DnsZonesPageProps } from '../../themes/default/src/lib/bootstrap';

    type Language = 'en-GB' | 'de-DE';
    let { page, language = 'en-GB' }: { page: DnsZonesPageProps; language?: Language } = $props();
    const messages: Record<Language, Record<string, string>> = {
        'en-GB': {
            'page.zones.title': 'DNS zones', 'zones.create': 'Create zone', 'zones.provider': 'Provider account', 'zones.provider-required': 'Configure a provider account before creating a zone.',
            'zones.provider-connection-unavailable': 'Provider connection unavailable', 'zones.confirm-delete': 'Delete {name}?', 'zones.empty': 'No zones are managed in this account.', 'field.name': 'Name', 'common.delete': 'Delete',
        },
        'de-DE': {
            'page.zones.title': 'DNS-Zonen', 'zones.create': 'Zone anlegen', 'zones.provider': 'Provider-Konto', 'zones.provider-required': 'Vor dem Anlegen einer Zone muss ein Provider-Konto konfiguriert werden.',
            'zones.provider-connection-unavailable': 'Provider-Verbindung nicht verfügbar', 'zones.confirm-delete': '{name} löschen?', 'zones.empty': 'In diesem Konto werden keine Zonen verwaltet.', 'field.name': 'Name', 'common.delete': 'Löschen',
        },
    };
    provideI18n(() => messages[language]);
</script>

<DnsZonesPage {page} />
