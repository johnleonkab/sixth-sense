/**
 * Checks OpenUI Lang programs with the real parser and the canvas library:
 * prints the errors of each file and fails when any has one. Tests call it
 * on every program Laravel generates.
 *
 *   node scripts/openui-check.mts view-1.ui view-2.ui
 */
import { readFileSync } from 'node:fs';

import { createParser } from '@openuidev/react-lang';

import { buildLibrary } from '../resources/js/canvas/openui/spec.ts';

const library = buildLibrary();
const parser = createParser(library.toJSONSchema(), library.root);
let failed = false;

for (const file of process.argv.slice(2)) {
    const result = parser.parse(readFileSync(file, 'utf8'));
    const errors = [...(result.meta?.errors ?? []), ...(result.meta?.unresolved ?? []).map((name: string) => ({ code: 'unresolved', name }))];

    if (result.root === null || errors.length > 0) {
        failed = true;
        console.log(JSON.stringify({ file, errors, root: result.root !== null }));
    }
}

process.exit(failed ? 1 : 0);
