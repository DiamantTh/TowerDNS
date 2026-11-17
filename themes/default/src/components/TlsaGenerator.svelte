<script lang="ts">
    import { useI18n } from '../lib/i18n';

    let { endpoint, csrfToken, onGenerated }: {
        endpoint: string;
        csrfToken: string;
        onGenerated: (values: string[]) => void;
    } = $props();

    const t = useI18n();
    let pem = $state('');
    let usage = $state('3');
    let selector = $state('1');
    let matching = $state('1');
    let busy = $state(false);
    let error = $state('');
    let result = $state('');

    const readFiles = async (event: Event): Promise<void> => {
        const input = event.currentTarget as HTMLInputElement;
        const files = Array.from(input.files ?? []);
        if (!files.length) return;
        try {
            const blocks = await Promise.all(files.map(async file => {
                if (file.size > 750_000) throw new Error(t('tlsa.error.size'));
                const buffer = await file.arrayBuffer();
                const text = new TextDecoder('utf-8', { fatal: false }).decode(buffer);
                if (text.includes('-----BEGIN ')) return text;
                const binary = Array.from(new Uint8Array(buffer), byte => String.fromCharCode(byte)).join('');
                const base64 = btoa(binary).match(/.{1,64}/g)?.join('\n') ?? '';
                return '-----BEGIN CERTIFICATE-----\n' + base64 + '\n-----END CERTIFICATE-----';
            }));
            pem = blocks.join('\n');
            error = '';
        } catch (exception) {
            error = exception instanceof Error ? exception.message : t('tlsa.error.import');
        } finally {
            input.value = '';
        }
    };

    const generate = async (event: SubmitEvent): Promise<void> => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        error = '';
        result = '';
        try {
            const body = new URLSearchParams({ csrf_token: csrfToken, pem, usage, selector, matching });
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                body: body.toString(),
                credentials: 'same-origin',
            });
            const data: { error?: string; rdata?: unknown } = await response.json();
            if (!response.ok || !Array.isArray(data.rdata) || !data.rdata.every((value: unknown) => typeof value === 'string')) {
                throw new Error(data.error ?? t('tlsa.error.generate'));
            }
            const rdata = data.rdata as string[];
            onGenerated(rdata);
            result = t('tlsa.success', { count: rdata.length });
        } catch (exception) {
            error = exception instanceof Error ? exception.message : t('tlsa.error.generate');
        } finally {
            busy = false;
        }
    };
</script>

<section class="box" aria-label={t('tlsa.title')}>
    <h2 class="subtitle">{t('tlsa.title')}</h2>
    <p class="help">{t('tlsa.description')}</p>
    <form onsubmit={generate}>
        <div class="form-grid">
            <label>{t('tlsa.usage')}
                <select class="input" bind:value={usage}>
                    <option value="0">0 – PKIX-TA</option>
                    <option value="1">1 – PKIX-EE</option>
                    <option value="2">2 – DANE-TA</option>
                    <option value="3">3 – DANE-EE</option>
                </select>
            </label>
            <label>{t('tlsa.selector')}
                <select class="input" bind:value={selector}>
                    <option value="0">0 – Full certificate</option>
                    <option value="1">1 – SubjectPublicKeyInfo</option>
                </select>
            </label>
            <label>{t('tlsa.matching')}
                <select class="input" bind:value={matching}>
                    <option value="0">0 – Exact</option>
                    <option value="1">1 – SHA-256</option>
                    <option value="2">2 – SHA-512</option>
                </select>
            </label>
        </div>
        <label class="mt-3" for="tlsa-pem">{t('tlsa.certificates')}</label>
        <textarea id="tlsa-pem" class="input" rows="5" bind:value={pem} placeholder="-----BEGIN CERTIFICATE-----" required></textarea>
        <label class="mt-3" for="tlsa-file">{t('tlsa.upload')}</label>
        <input id="tlsa-file" class="input" type="file" accept=".pem,.crt,.cer,.der,.pub" multiple onchange={readFiles}>
        <p class="help">{t('tlsa.warning')}</p>
        {#if error}<p class="notification is-danger" role="alert">{error}</p>{/if}
        {#if result}<p class="notification is-success" role="status">{result}</p>{/if}
        <button class="button" type="submit" disabled={busy || !pem.trim()}>{busy ? t('tlsa.generating') : t('tlsa.generate')}</button>
    </form>
</section>
