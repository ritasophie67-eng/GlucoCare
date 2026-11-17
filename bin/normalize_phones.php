<?php
// One-off data migration: rewrite patient phone numbers into the canonical
// +256XXXXXXXXX form the application now stores everywhere.
//
//   php bin/normalize_phones.php           preview the changes
//   php bin/normalize_phones.php --apply   save them
//
// Safe to re-run: already-canonical rows are skipped. Numbers that cannot be
// parsed are listed and left untouched so an administrator can correct them
// from Admin → Patients (pencil icon next to the number).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
$pdo = get_db_connection();

$rows = $pdo->query('SELECT patient_id, full_name, phone FROM patients ORDER BY patient_id')->fetchAll();
$update = $pdo->prepare('UPDATE patients SET phone = ? WHERE patient_id = ?');

$changed = 0;
$invalid = [];

echo 'GlucoCare phone normalisation' . PHP_EOL . str_repeat('-', 29) . PHP_EOL;
echo $apply ? 'Mode: apply' . PHP_EOL : 'Mode: preview (re-run with --apply to save)' . PHP_EOL;
echo 'Rows: ' . count($rows) . PHP_EOL . PHP_EOL;

foreach ($rows as $row) {
    $id  = (int)$row['patient_id'];
    $old = (string)$row['phone'];
    $new = phone_normalize($old);

    if ($new === null) {
        $invalid[] = "#{$id} {$row['full_name']} (\"{$old}\")";
        continue;
    }
    if ($new === $old) {
        continue;
    }

    $changed++;
    echo '  ' . ($apply ? 'saved  ' : 'would  ') . "#{$id} {$row['full_name']}: {$old} → " . phone_display($new) . " ({$new})" . PHP_EOL;
    if ($apply) {
        $update->execute([$new, $id]);
    }
}

echo PHP_EOL . ($apply ? 'Updated ' : 'Would update ') . $changed . ' row(s).' . PHP_EOL;

if ($invalid) {
    echo 'Left unchanged — not a Ugandan number, fix in Admin → Patients:' . PHP_EOL;
    foreach ($invalid as $line) {
        echo '  ' . $line . PHP_EOL;
    }
    exit(0);
}

echo 'Every number is in canonical form.' . PHP_EOL;
