<?php

/*
|--------------------------------------------------------------------------
| Character variant normalization (char_variant_map)
|--------------------------------------------------------------------------
|
| See docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md. These strings surface in
| Codes UI flash messages and the `notices` field of v2 mutate responses (covering
| 80+ code tables and every person subresource), so per AGENTS.md §6 they must go
| through __() rather than being hard-coded.
|
*/

return [
    'notice' => 'Character variants: :pairs',
    'notice_pair' => '":variant" normalized to ":reference"',
    'notice_separator' => '; ',

    'incomplete_payload' => 'Updating a mapping requires both the variant and the reference character (or the id of the mapping being updated).',
    'single_codepoint_required' => 'Both the variant character and the reference character must be a single character.',
    'self_reference_not_allowed' => 'The variant character and the reference character must differ.',
    'cycle_not_allowed' => 'This mapping would create a cycle (":char" maps back to itself). Please choose a different reference character.',
];
