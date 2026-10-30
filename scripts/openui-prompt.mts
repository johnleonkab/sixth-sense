/**
 * Writes the OpenUI prompt Laravel gives the voice, from the same component
 * definitions the canvas renders. Run after changing resources/js/canvas/openui/spec.ts.
 */
import { writeFileSync } from 'node:fs';

import { viewPrompt } from '../resources/js/canvas/openui/spec.ts';

writeFileSync(new URL('../resources/openui/prompt.md', import.meta.url), viewPrompt().trim() + '\n');
console.log('resources/openui/prompt.md');
