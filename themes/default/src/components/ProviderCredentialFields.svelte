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

<div class="form-grid">
  {#each fields as field (field.input)}
    <Field label={t(field.label)}>
      {#if field.type === 'checkbox'}
        <input class="checkbox" type="checkbox" name={field.input} value="1">
      {:else if field.type === 'textarea'}
        <textarea class="input" name={field.input} rows="5" autocomplete="off" required={field.required}></textarea>
      {:else}
        <input
          class="input"
          type={field.secret ? 'password' : (field.type ?? 'text')}
          name={field.input}
          value={field.default ?? ''}
          autocomplete={field.secret ? 'new-password' : 'off'}
          required={field.required}
        >
      {/if}
    </Field>
  {/each}
</div>
