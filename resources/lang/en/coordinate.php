<?php

/*
|--------------------------------------------------------------------------
| Coordinate blank/zero normalization (CoordinatePairNormalizer)
|--------------------------------------------------------------------------
|
| These strings currently surface in the top-level `notices` field of v2 mutate/create
| responses (sharing that field with character-variant replacement). The Codes UI flash is
| not wired up yet - that write path does not have the zeroing guard on it. Per AGENTS.md
| §6 they must go through __() rather than being hard-coded.
|
| Wording constraint: `cleared_with_partner` must not assert that a value the user
| just typed was thrown away. Callers already drop the entries where nothing actually
| happened (the stored value was already NULL), but what remains may still be "this
| row had a value and it has now been cleared" rather than "your input was discarded",
| and the sentence has to be true of both.
|
*/

return [
    'notice' => 'Coordinates: :items',
    'notice_separator' => '; ',

    'cleared_blank' => '":column" was left empty and is treated as NULL',
    'cleared_zero' => '":column" was 0 and is treated as NULL (0,0 is not a valid coordinate)',
    'cleared_with_partner' => '":column" is treated as NULL as well (longitude and latitude go together, and the other axis was empty or 0)',
];
