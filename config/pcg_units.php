<?php

/*
 * The PCG unit list, grouped under its headings. Defined once, in config/pcg-units.json, which
 * the React app imports too — edit the list there, never here or in a page.
 */

return json_decode(
    file_get_contents(__DIR__.'/pcg-units.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
)['groups'];
