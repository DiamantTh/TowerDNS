<script lang="ts">
    import { useI18n } from '../lib/i18n';

    export type DataTableRow = Record<string, unknown>;

    let {
        rows,
        cols,
        labels,
        csrf = '',
        action = null,
        actionText = '',
        confirmMessage = null,
        detailCols = [],
        detailLabels = [],
    }: {
        rows: DataTableRow[];
        cols: string[];
        labels: string[];
        csrf?: string;
        action?: ((row: DataTableRow) => string | null) | null;
        actionText?: string;
        confirmMessage?: ((row: DataTableRow) => string | null) | null;
        detailCols?: string[];
        detailLabels?: string[];
    } = $props();

    const t = useI18n();
    const ask = (event: SubmitEvent, row: DataTableRow): void => {
        const message = confirmMessage?.(row) ?? t('table.confirm-action', { action: actionText });
        if (message !== '' && !window.confirm(message)) event.preventDefault();
    };
    const cellText = (value: unknown): string => value === null || value === undefined ? '–' : String(value);
</script>

<!-- svelte-ignore a11y_no_noninteractive_tabindex -- This named region is a keyboard scroll target for the wide data table. -->
<div class="table-container" role="region" aria-label={labels.join(', ')} tabindex="0">
    <table class="table">
        <thead>
            <tr>
                {#each labels as label}<th scope="col">{label}</th>{/each}
                <th scope="col"><span class="sr-only">{t('table.actions')}</span></th>
            </tr>
        </thead>
        <tbody>
            {#each rows as row}
                {@const actionUrl = action?.(row) ?? null}
                <tr>
                    {#each cols as column, index}
                        <td>{cellText(row[column])}
                            {#if index === 0 && detailCols.length > 0}
                                <details class="mt-1"><summary>{t('common.details')}</summary>
                                    <dl class="mt-2">{#each detailCols as detail, detailIndex}<dt>{detailLabels[detailIndex] ?? detail}</dt><dd><code>{cellText(row[detail])}</code></dd>{/each}</dl>
                                </details>
                            {/if}
                        </td>
                    {/each}
                    <td>
                        {#if actionUrl}
                            <form method="post" action={actionUrl} onsubmit={(event) => ask(event, row)}>
                                <input type="hidden" name="csrf_token" value={csrf}>
                                <button class="button is-small is-danger">{actionText}</button>
                            </form>
                        {/if}
                    </td>
                </tr>
            {:else}
                <tr><td colspan={labels.length + 1}>{t('table.empty')}</td></tr>
            {/each}
        </tbody>
    </table>
</div>
