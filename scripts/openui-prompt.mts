/**
 * Writes the OpenUI prompt Laravel gives the voice, and the live-data guide it
 * asks for on demand, from the same component definitions the canvas renders. Run after changing resources/js/canvas/openui/spec.ts.
 */
import { writeFileSync } from 'node:fs';

import { dataGuide, viewPrompt } from '../resources/js/canvas/openui/spec.ts';

writeFileSync(new URL('../resources/openui/prompt.md', import.meta.url), viewPrompt().trim() + '\n');
writeFileSync(new URL('../resources/openui/data-guide.md', import.meta.url), dataGuide().trim() + '\n');
console.log('resources/openui/prompt.md, resources/openui/data-guide.md');
