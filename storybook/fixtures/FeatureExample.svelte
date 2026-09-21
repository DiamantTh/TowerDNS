<script lang="ts">
    import type { ComponentProps } from 'svelte';
    import Feature from '../../themes/default/src/components/Feature.svelte';
    import { provideI18n } from '../../themes/default/src/lib/i18n';

    type Language = 'en-GB' | 'de-DE';
    type Props = ComponentProps<typeof Feature> & { language: Language };
    let { language, ...featureProps }: Props = $props();

    const catalogs: Record<Language, Record<string, string>> = {
        'en-GB': { 'common.open': 'Open' },
        'de-DE': { 'common.open': 'Öffnen' },
    };
    provideI18n(() => catalogs[language]);
</script>

<Feature {...featureProps} />
