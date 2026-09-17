import './bootstrap';
import './template-editor';
import './file-manager';

import { templateImageEditor } from './template-image';
import { fileEditor } from './file-editor';
import { templateMacroInput } from './template-macros';
import { tagSelect } from '@trafficops/fast-landings-ui/tag-select';

document.addEventListener('alpine:init', () => {
    Alpine.data('templateImageEditor', templateImageEditor);
    Alpine.data('fileEditor', fileEditor);
    Alpine.data('templateMacroInput', templateMacroInput);
    Alpine.data('landingTags', (values, suggestions) => {
        const state = tagSelect(values);
        state.options = suggestions.map(name => ({ name, kind: 'tag' }));
        const add = state.add;
        state.add = function (option) {
            add.call(this, option);
            this.open = false;
        };
        return state;
    });
});
