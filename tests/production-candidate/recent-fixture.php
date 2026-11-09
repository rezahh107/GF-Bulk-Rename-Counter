<?php
/** Synthetic Recent N data and independent expected archive membership. */
if (!defined('ABSPATH') || !class_exists('GFAPI')) { throw new RuntimeException('Installed GF required'); }
global $wpdb;
$lab = getenv('BRC_LAB_DIR');
$file = $lab . '/recent-fixture.json';
$action = getenv('BRC_RECENT_ACTION');

function require_recent($result) {
    if ($result === false || is_wp_error($result)) {
        throw new RuntimeException('Recent N fixture operation failed');
    }
    return $result;
}

if ($action === 'setup') {
    if (is_file($file)) { throw new RuntimeException('Recent N fixture already exists'); }
    $definition = [
        'fields' => [
            ['id' => 1, 'type' => 'fileupload', 'label' => 'Single'],
            ['id' => 2, 'type' => 'fileupload', 'label' => 'Multiple', 'multipleFiles' => true],
            ['id' => 3, 'type' => 'text', 'label' => 'Archive name'],
            ['id' => 5, 'type' => 'text', 'label' => 'Group'],
        ],
        'button' => ['type' => 'text', 'text' => 'Submit'],
    ];
    $form = (int)require_recent(GFAPI::add_form($definition + ['title' => 'Recent N boundary']));
    $small = (int)require_recent(GFAPI::add_form($definition + ['title' => 'Recent N small and ACK']));
    $empty = (int)require_recent(GFAPI::add_form($definition + ['title' => 'Recent N file-free']));
    $upload = wp_upload_dir();
    $dir = $upload['basedir'] . '/gfbrc-recent-fixture';
    if (!wp_mkdir_p($dir)) { throw new RuntimeException('Cannot create test uploads'); }
    $make_source = static function (string $key) use ($dir, $upload): array {
        $path = "$dir/$key.txt";
        $bytes = "Recent N synthetic source $key\n";
        require_recent(file_put_contents($path, $bytes));
        return ['url' => $upload['baseurl'] . "/gfbrc-recent-fixture/$key.txt",
            'sha' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'path' => $path];
    };
    $source = [];
    $entries = [];
    for ($i = 1; $i <= 501; $i++) {
        $key = 'recent-' . $i;
        $values = ['form_id' => $form, '3' => $key, '5' => $i <= 250 ? 'group-a' : 'group-b'];
        if ($i === 501) { $values['date_created'] = '2001-01-01 00:00:00'; }
        $source[$i] = $make_source($key);
        if ($i !== 500) { $values['1'] = $source[$i]['url']; }
        if ($i === 501) {
            $extra = $make_source('extra');
            $values['2'] = wp_json_encode([$extra['url'], $extra['url']]);
        }
        $id = (int)require_recent(GFAPI::add_entry($values));
        $members = $i === 500 ? [] : ["$key.txt" => $source[$i]];
        if ($i === 501) {
            $members["$key-$id.txt"] = $extra;
            $members["$key-$id-1.txt"] = $extra;
        }
        if ($i === 499) {
            gform_update_meta($id, 'bdfgf_deleted_file_urls', [$source[$i]['url']], $form);
            gform_update_meta($id, 'bdfgf_files_deleted', 1, $form);
            $members = [];
        }
        $entries[] = ['id' => $id, 'members' => (object)$members];
        if ($i === 1 || $i % 100 === 0) {
            require_recent(GFAPI::add_entry(['form_id' => $small, '3' => "interleaved-$i"] +
                ($i === 1 ? ['1' => $source[$i]['url']] : [])));
        }
    }
    foreach (['spam', 'trash', 'deleted'] as $status) {
        $id = (int)require_recent(GFAPI::add_entry(['form_id' => $form, '1' => $source[501]['url']]));
        require_recent($status === 'deleted' ? GFAPI::delete_entry($id) : GFAPI::update_entry_property($id, 'status', $status));
    }
    require_recent(GFAPI::add_entry(['form_id' => $empty]));
    $rules = get_option('gf_bulk_rename_per_form_rules', []);
    $rules[$form] = ['method' => 'single', 'field_id' => 3];
    require_recent(update_option('gf_bulk_rename_per_form_rules', $rules));
    $data = [
        'form' => $form, 'small' => $small, 'empty' => $empty,
        'entries' => array_reverse($entries), 'small_source' => $source[1], 'race_source' => $extra,
        'missing_source' => $source[501],
    ];
    require_recent(file_put_contents($file, wp_json_encode($data)));
} else {
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $form = $data['form'];
    $top = $data['entries'][0]['id'];
    switch ($action) {
        case 'expire':
            $seal = getenv('BRC_RECENT_SEAL');
            if (!is_string($seal) || !preg_match('/^[a-f0-9]{64}$/D', $seal)) {
                throw new RuntimeException('Expected an authenticated synthetic preview seal');
            }
            require_recent(update_option('_transient_timeout_gfbrc_recent_' . $seal, time() - 1));
            $data = ['expired' => true];
            break;
        case 'actors':
            $data = [];
            foreach (['viewer' => true, 'denied' => false] as $actor => $allowed) {
                $id = require_recent(wp_insert_user([
                    'user_login' => 'recent_lab_' . $actor, 'user_pass' => wp_generate_password(),
                    'role' => 'subscriber',
                ]));
                $user = new WP_User($id);
                if ($allowed) { $user->add_cap('gravityforms_view_entries'); }
                $expires = time() + 600;
                $token = WP_Session_Tokens::get_instance($id)->create($expires);
                $logged_in = wp_generate_auth_cookie($id, $expires, 'logged_in', $token);
                $_COOKIE[LOGGED_IN_COOKIE] = $logged_in;
                wp_set_current_user($id);
                $data[$actor] = ['nonce' => wp_create_nonce('gfbrc_independent_flat_zip_' . $form), 'cookies' => [
                    ['name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie($id, $expires, 'auth', $token)],
                    ['name' => LOGGED_IN_COOKIE, 'value' => $logged_in],
                ]];
            }
            break;
        case 'late-commit':
            $late = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            require_recent($late->query('START TRANSACTION'));
            try {
                require_recent($late->insert(GFFormsModel::get_entry_table_name(), [
                    'form_id' => $form, 'date_created' => '2000-01-01 00:00:00', 'ip' => '',
                ]));
                $delayed = $late->insert_id;
                $later = (int)require_recent(GFAPI::add_entry(['form_id' => $form, '3' => 'committed-first']));
                $criteria = ['status' => 'active'];
                $sort = ['key' => 'id', 'direction' => 'DESC', 'is_numeric' => true];
                $page = ['offset' => 0, 'page_size' => 2];
                $before = array_map('intval', GFAPI::get_entry_ids($form, $criteria, $sort, $page));
                require_recent($late->query('COMMIT'));
                $after = array_map('intval', GFAPI::get_entry_ids($form, $criteria, $sort, $page));
                $data = ['before' => $before, 'after' => $after, 'late' => $delayed, 'later' => $later];
            } finally {
                $late->query('ROLLBACK');
                $late->close();
            }
            break;
        case 'state':
            $sql = $wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options}
                WHERE option_name LIKE %s OR option_name LIKE %s OR option_name IN (%s, %s)
                ORDER BY option_name",
                $wpdb->esc_like('gfbrc_new_coverage_') . '%', $wpdb->esc_like('gfbrc_new_pending_') . '%',
                'gf_bulk_rename_options', 'gf_bulk_rename_per_form_rules');
            $state = $wpdb->get_results($sql, ARRAY_A);
            $data = ['sha' => hash('sha256', wp_json_encode($state)),
                'covered' => (string)get_option('gfbrc_new_coverage_' . $data['small']),
                'pending' => (string)get_option('gfbrc_new_pending_' . $data['small'])];
            break;
        case 'trash-top':
        case 'restore-top':
            require_recent(GFAPI::update_entry_property($top, 'status', $action === 'trash-top' ? 'trash' : 'active'));
            break;
        default:
            throw new RuntimeException('Unknown Recent N fixture operation');
    }
}
echo 'GFBRC_RECENT_FIXTURE ' . wp_json_encode($data) . PHP_EOL;
