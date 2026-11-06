<script lang="ts">
  import Field from './Field.svelte';
  import { useI18n } from '../lib/i18n';

  type CredentialField = {
    input: string;
    label: string;
    required: boolean;
    secret: boolean;
    default?: string;
    type?: string;
  };

  let { fields }: { fields: CredentialField[] } = $props();
  const t = useI18n();
</script>

<p class="help">{t('provider.workflow-required')}</p>
<div class="form-grid">
  {#each fields as field (field.input)}
    <Field label={`${t(field.label)}${field.required ? ' *' : ''}`}>
      {#if field.type === 'checkbox'}
        <input class="checkbox" type="checkbox" name={field.input} value="1" checked={field.default === '1'} required={field.required}>
      {:else if field.type === 'textarea'}
        <textarea class="input" name={field.input} rows="5" autocomplete={field.secret ? 'new-password' : 'off'} required={field.required}>{field.secret ? '' : field.default ?? ''}</textarea>
      {:else}
        <input
          class="input"
          type={field.secret ? 'password' : (field.type ?? 'text')}
          name={field.input}
          value={field.secret ? '' : field.default ?? ''}
          autocomplete={field.secret ? 'new-password' : 'off'}
          required={field.required}
        >
      {/if}
    </Field>
  {/each}
</div>
