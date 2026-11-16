// SPDX-License-Identifier: AGPL-3.0-or-later
import assert from 'node:assert/strict';
import { signalCurrentUserDetails } from '../../themes/default/src/lib/webauthnSignal.ts';

const details = [
    { rpId: 'dns.example.test', userId: 'bGVnYWN5LW9sZA', name: 'new@example.test', displayName: 'New Name' },
    { rpId: 'dns.example.test', userId: 'bGVnYWN5LW5ldw', name: 'new@example.test', displayName: 'New Name' },
];

{
    const calls = [];
    await signalCurrentUserDetails(details, {
        signalCurrentUserDetails: async detail => { calls.push(detail); },
    });
    assert.deepEqual(calls, details, 'each distinct legacy handle receives the current account labels');
}

{
    await assert.doesNotReject(signalCurrentUserDetails(details, null), 'unsupported browsers do not block the workflow');
}

{
    const calls = [];
    await assert.doesNotReject(signalCurrentUserDetails(details, {
        signalCurrentUserDetails: async detail => {
            calls.push(detail);
            if (detail.userId === details[0].userId) throw new DOMException('Authenticator unavailable', 'NotFoundError');
        },
    }));
    assert.equal(calls.length, 2, 'a failed authenticator signal does not prevent other handles');
}

{
    let calls = 0;
    const many = Array.from({ length: 150 }, (_, index) => ({ ...details[0], userId: `handle-${index}` }));
    await signalCurrentUserDetails(many, { signalCurrentUserDetails: async () => { calls += 1; } });
    assert.equal(calls, 100, 'signal processing remains bounded');
}

console.log('WebAuthn Signal API tests passed.');
