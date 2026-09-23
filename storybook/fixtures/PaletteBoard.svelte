<script lang="ts">
    import type { UxPalette } from '../ux-palettes';

    let { palette }: { palette: UxPalette } = $props();
    const entries = $derived(Object.entries(palette.colors));
</script>

<section class="palette-board" aria-label={`${palette.name} palette`}>
    <header class="palette-board__header">
        <div><p class="heading">{palette.code} · {palette.mode}</p><h1 class="title is-4">{palette.name}</h1></div>
        <p class="muted">{palette.description}</p>
    </header>
    <div class="palette-board__grid">
        {#each entries as [name, hex]}
            <article class="palette-swatch">
                <span class="palette-swatch__color" style={`background:${hex}`}></span>
                <strong>{name}</strong>
                <code>{hex}</code>
            </article>
        {/each}
    </div>
</section>

<style>
    .palette-board { max-width: 72rem; margin: 0 auto; padding: 1.5rem; }
    .palette-board__header { display: flex; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; }
    .palette-board__header .muted { max-width: 36rem; margin: .25rem 0 0; }
    .palette-board__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: .75rem; }
    .palette-swatch { display: grid; gap: .45rem; min-width: 0; border: 1px solid var(--color-surface-300); border-radius: var(--radius-container); background: var(--color-surface-50); padding: .75rem; }
    .palette-swatch__color { min-height: 3.25rem; border: 1px solid color-mix(in oklab, var(--color-surface-950) 18%, transparent); border-radius: var(--radius-base); }
    .palette-swatch code { overflow: hidden; color: inherit; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-width: 42rem) { .palette-board__header { display: grid; } }
</style>
