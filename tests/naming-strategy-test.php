<?php
/** Isolated candidate evaluator checks; not a WP/GF integration claim. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/../');
function sanitize_file_name(string $v): string {
    return str_replace(['?', '/', '\\', ':'], '', $v);
}
require_once __DIR__ . '/../includes/class-naming_strategy.php';
use GFBulkRename\Naming_Strategy;
function ensure(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$entry = ['id' => '12', '6' => 'شیراز', '7' => '0'];
ensure(Naming_Strategy::candidate(['method'=>'composite','field_ids'=>[7,6],'separator'=>'__'],
    $entry, 'original.jpg') === '0__شیراز.jpg', 'Unicode/order/zero failed');
ensure(Naming_Strategy::candidate(['method'=>'single','field_id'=>6],
    $entry, 'original.png') === 'شیراز.png', 'Single/extension failed');
foreach ([['id'=>'12', '6'=>''], ['id'=>'12'], ['id'=>'12','6'=>[]]] as $invalid) {
    try {
        Naming_Strategy::candidate(['method'=>'single','field_id'=>6], $invalid, 'x.jpg');
        throw new RuntimeException('Expected safe failure not raised');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(),'Naming field')) { throw $e; }
    }
}
// Reserved device names must fail AFTER filename sanitization, not be rewritten.
foreach (['CON.jpg', 'con.jpg', 'NUL.jpg', 'COM1.jpg', 'CON.txt.jpg',
          'PRN.jpg', 'AUX.jpg', 'LPT9.jpg', 'COM¹.jpg', 'LPT².jpg'] as $bad) {
    $extension = pathinfo($bad, PATHINFO_EXTENSION);
    $stem = substr($bad, 0, -(strlen($extension) + 1));
    try {
        Naming_Strategy::candidate(['method'=>'single','field_id'=>6],
            ['id'=>'12', '6'=>$stem], 'upload.' . $extension);
        throw new RuntimeException('Reserved device name accepted: ' . $bad);
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'Windows reserved device')) { throw $e; }
    }
}
foreach (['CONSOLE.jpg','COM10.jpg','NULITY.jpg','شیراز.jpg','0.jpg'] as $good) {
    $stem = substr($good, 0, -4);
    ensure(Naming_Strategy::candidate(['method'=>'single','field_id'=>6],
        ['id'=>'12', '6'=>$stem], 'upload.jpg') === $good,
        'Allowed new name was altered: ' . $good);
}
try {
    Naming_Strategy::candidate(['method'=>'composite','field_ids'=>[7,6],'separator'=>'.'],
        ['id'=>'12','7'=>'CON','6'=>'txt'], 'original.jpg');
    throw new RuntimeException('Composite reserved multi-dot device name accepted');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'Windows reserved device')) { throw $e; }
}
ensure(Naming_Strategy::candidate(['method'=>'composite','field_ids'=>[7,6],'separator'=>'.'],
    ['id'=>'12','7'=>'0','6'=>'شیراز'], 'original.jpg') === '0.شیراز.jpg',
    'Composite field order / numeric zero / Unicode altered');
echo "PASS_ISOLATED_WINDOWS_RESERVED_NEW_CANDIDATES\n";
echo "PASS_ISOLATED_EXT001_CANDIDATE_TESTS; WordPress integration NOT_PROVEN\n";
