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
echo "PASS_ISOLATED_EXT001_CANDIDATE_TESTS; WordPress integration NOT_PROVEN\n";
