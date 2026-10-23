<?php
/** Qualification-only same-key signed negative controls; not plugin source. */
if (!defined('ABSPATH')) { throw new RuntimeException('WordPress required'); }
$lab = getenv('BRC_LAB_DIR');
$observed = json_decode(file_get_contents($lab . '/hook-browser.json'), true, 512, JSON_THROW_ON_ERROR);
$original = json_decode($observed['effective'], true, 512, JSON_THROW_ON_ERROR);
foreach (['expired', 'cross_user', 'cross_form', 'changed_criteria_same_ids'] as $case) {
    $payload = $original;
    if ($case === 'expired') { $payload['issued_at'] = time() - 3600; }
    if ($case === 'cross_user') { $payload['user_id'] += 1; }
    if ($case === 'cross_form') { $payload['form_id'] += 1; }
    if ($case === 'changed_criteria_same_ids') {
        $filters = $payload['criteria']['field_filters'];
        if (!is_array($filters) || !$filters) { throw new RuntimeException('No captured filter'); }
        $first = array_key_first($filters);
        $payload['criteria']['field_filters'][$first]['operator'] = 'contains';
    }
    $encoded = wp_json_encode($payload);
    file_put_contents($lab . '/effective-negative-' . $case . '.json',
        wp_json_encode(['payload' => $encoded,
            'seal' => hash_hmac('sha256', 'gfbrc-effective-v1|' . $encoded, wp_salt('auth'))]));
}
echo 'PASS_EFFECTIVE_VALID_HMAC_NEGATIVE_FIXTURES' . PHP_EOL;
