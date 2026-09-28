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
    }: {
        rows: DataTableRow[];
        cols: string[];
        labels: string[];
        csrf?: string;
        action?: ((row: DataTableRow) => string | null) | null;
        actionText?: string;
    } = $props();

    const t = useI18n();
    const ask = (event: SubmitEvent): void => {
        if (!confirm(t('table.confirm-action', { action: actionText }))) event.preventDefault();
    };
    const cellText = (value: unknown): string => value === null || value === undefined ? '–' : String(value);
</script>

<div class="table-container">
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
                    {#each cols as column}<td>{cellText(row[column])}</td>{/each}
                    <td>
                        {#if actionUrl}
                            <form method="post" action={actionUrl} onsubmit={ask}>
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
