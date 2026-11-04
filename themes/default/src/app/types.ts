import type { PageUrlTemplates, ThemeBootstrap } from '../lib/bootstrap';

export type PageBoot = {
    page: string;
    props: Record<string, any>;
    themes: ThemeBootstrap[];
    theme: ThemeBootstrap;
    debug: boolean;
    urls?: PageUrlTemplates;
    i18n?: { locale: string; messages: Record<string, string | string[]> };
};
