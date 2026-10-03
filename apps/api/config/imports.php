<?php

return [
    'max_kib' => min(10240, max(1, (int) env('IMPORT_MAX_KIB', 10240))),
    'sample_rows' => 20,
    'batch_rows' => 100,
    'normalization_batch_rows' => min(25, max(1, (int) env('IMPORT_NORMALIZATION_BATCH_ROWS', 25))),
    'max_rows' => 10000,
];
