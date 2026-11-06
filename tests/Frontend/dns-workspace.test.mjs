// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { visibleZones, visibleRrsets } from '../../themes/default/src/app/pages/dns-workspace.ts';

test('zone search, provider filter, stable sorting and empty matches preserve input', () => {
    const zones = [
        { id: 3, canonicalName: 'z.example', providerAccountId: 2 },
        { id: 2, canonicalName: 'a.example', providerAccountId: 1 },
        { id: 1, canonicalName: 'a.example', providerAccountId: 2 },
    ];
    assert.deepEqual(visibleZones(zones, '', '').map(z => z.id), [1, 2, 3]);
    assert.deepEqual(visibleZones(zones, ' A.EXAMPLE ', '2').map(z => z.id), [1]);
    assert.deepEqual(visibleZones(zones, 'absent', ''), []);
    assert.deepEqual(zones.map(z => z.id), [3, 2, 1]);
});
test('RRset search covers all values, type filters, long TXT and stable owner/type order', () => {
    const rrset = (ownerName, presentation, code, rdata) => ({ ownerName, type: { presentation, code, isKnown: true }, ttl: 300, rdata });
    const rows = [rrset('www', 'AAAA', 28, ['2001:db8::1']), rrset('_domainkey', 'TXT', 16, ['v=DKIM1; p=' + 'a'.repeat(4000), 'second value']), rrset('www', 'A', 1, ['192.0.2.1']), rrset('', 'MX', 15, ['10 mail.example.'])];
    assert.deepEqual(visibleRrsets(rows, 'SECOND', 'TXT'), [rows[1]]);
    assert.deepEqual(visibleRrsets(rows, '@', ''), [rows[3]]);
    assert.deepEqual(visibleRrsets(rows, 'www', '').map(r => r.type.presentation), ['A', 'AAAA']);
    assert.deepEqual(visibleRrsets(rows, 'mail', 'A'), []);
    assert.equal(rows[1].rdata[0].length, 4011);
});
