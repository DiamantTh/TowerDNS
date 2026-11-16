// SPDX-License-Identifier: AGPL-3.0-or-later

export interface CurrentUserDetailsSignal {
    rpId: string;
    /** Base64url-encoded WebAuthn user handle. */
    userId: string;
    name: string;
    displayName: string;
}

interface SignalApi {
    signalCurrentUserDetails?: (details: CurrentUserDetailsSignal) => Promise<void>;
}

function browserSignalApi(): SignalApi | null {
    if (typeof PublicKeyCredential === 'undefined') return null;
    return PublicKeyCredential as unknown as SignalApi;
}

/** Best-effort metadata refresh for this authenticated user's own credentials. */
export async function signalCurrentUserDetails(
    details: readonly CurrentUserDetailsSignal[],
    api: SignalApi | null = browserSignalApi(),
): Promise<void> {
    const signal = api?.signalCurrentUserDetails;
    if (typeof signal !== 'function') return;

    // Keep work bounded even if a malformed payload or unusually large legacy
    // credential set reaches the browser.
    await Promise.all(details.slice(0, 100).map(async detail => {
        try {
            await signal.call(api, detail);
        } catch {
            // Authenticator support and availability are outside the RP's control.
        }
    }));
}
