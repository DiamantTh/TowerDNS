import { getContext, setContext } from 'svelte';

type Message = string | string[];
type Messages = Record<string, Message>;

const contextKey = Symbol('towerdns.i18n');

export function provideI18n(messages: () => Messages): void {
    setContext(contextKey, messages);
}

export function useI18n(): (key: string, parameters?: Record<string, string | number>) => string {
    const messages = getContext<() => Messages>(contextKey) ?? (() => ({}));

    return (key, parameters = {}) => {
        const message = messages()[key];
        const text = Array.isArray(message) ? message[0] : message ?? key;

        return text.replace(/\{([a-zA-Z0-9_]+)\}/g, (_, name) => String(parameters[name] ?? `{${name}}`));
    };
}
