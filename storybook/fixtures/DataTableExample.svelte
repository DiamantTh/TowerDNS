<script lang="ts">
    import type { ComponentProps } from 'svelte';
    import DataTable from '../../themes/default/src/components/DataTable.svelte';
    import { provideI18n } from '../../themes/default/src/lib/i18n';

    type Language = 'en-GB' | 'de-DE';
    type Props = ComponentProps<typeof DataTable> & { language: Language };
    let { language, ...tableProps }: Props = $props();

    const catalogs: Record<Language, Record<string, string>> = {
        'en-GB': { 'table.empty': 'No rows to display.', 'table.confirm-action': 'Do you want to {action} this item?' },
        'de-DE': { 'table.empty': 'Keine Einträge vorhanden.', 'table.confirm-action': 'Möchtest du diese Aktion ausführen: {action}?' },
    };
    provideI18n(() => catalogs[language]);
</script>

<DataTable {...tableProps} />
