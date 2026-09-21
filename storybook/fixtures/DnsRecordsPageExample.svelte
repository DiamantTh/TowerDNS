<script lang="ts">
    import DnsRecordsPage from '../../themes/default/src/components/DnsRecordsPage.svelte';
    import { provideI18n } from '../../themes/default/src/lib/i18n';
    import { dnsRecordsMessages, type FixtureLanguage } from './dns-records';
    import type { DnsRecordsPageProps } from '../../themes/default/src/lib/bootstrap';

    let { page, language = 'en-GB' }: { page: DnsRecordsPageProps; language?: FixtureLanguage } = $props();
    provideI18n(() => dnsRecordsMessages[language]);
</script>

<DnsRecordsPage {page} />
