<?php
/**
 * 卸载清理（1.5.1 补上）
 *
 * WordPress 只在「删除插件」时执行这个文件 —— 停用不会。
 * 删的是本插件自己建的东西：体检表、设置、缓存标记、定时任务。
 *
 * ⚠️ 这一步**不可逆**。体检表的每一行都是一次真实抓取的结果，
 * 老站两万个域名重扫一遍要好几个小时，所以：
 *   · 用户主动删插件 = 明确表示不要了，按规范清理，不留垃圾；
 *   · 只是「停用」的话这个文件根本不会跑，数据完好。
 * 如果哪天想保留数据，把下面 DELETE TABLE 那两行注释掉即可。
 *
 * @package wp-comment-guard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ① 自定义表
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'riven_link_health' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'riven_link_urlbackup' );

// ② 设置与台账（黑名单/白名单/自定义词都在 rcg_settings 里）
$options = array(
	'rcg_settings',
	'rcg_migrated',
	'rcg_last_check',
	'rcg_scan_per_item',
	'rcg_lexicon_hash',
	'rcg_lex_sync_note',
	'rcg_health_suspect_ignored',
	'rcg_alert_seen_risk',
	'rcg_alert_seen_suspect',
	'rcg_alert_seen_at',
	'riven_health_db_version',
	'riven_health_db_ready',
	'riven_health_backup_ready',
	'riven_health_last_cron',
	'riven_helper_token',
	'riven_helper_last',
	// 主题时代的旧台账（如果还在）
	'riven_health_blacklist',
	'riven_health_whitelist',
	'riven_health_restore_enable',
	'riven_health_exclude_hosts',
	'riven_health_batch',
	'riven_health_timeout',
	'riven_health_threshold',
	'riven_health_fresh_months',
	'riven_health_ttl_days',
	'riven_health_block_enable',
	'riven_health_block_message',
	'riven_health_extra_words',
	'riven_health_ignore_words',
	'riven_health_auto_enable',
	'riven_health_verdict_enable',
	'riven_health_active_ttl_days',
	'riven_health_urgent_ttl_days',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// ③ 多站点：每个子站也清一遍
if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( (array) $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'riven_link_health' );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'riven_link_urlbackup' );
		foreach ( $options as $opt ) {
			delete_option( $opt );
		}
		restore_current_blog();
	}
}

// ④ 缓存与定时任务
$transients = array(
	'riven_health_hosts',
	'riven_health_cache',
	'rcg_update_info',
	'rcg_alert_suspect',
	'rcg_lex_sync_lock',
	'rcg_health_host_map',
	'rcg_host_resolve',
);
foreach ( $transients as $t ) {
	delete_transient( $t );
}
wp_clear_scheduled_hook( 'riven_health_cron_hook' );
