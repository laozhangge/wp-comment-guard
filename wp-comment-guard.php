<?php
/**
 * Plugin Name: WP 评论外链守卫
 * Plugin URI:  https://laozhang.org/
 * Description: 给评论外链做分级与体检：久未往来的访客链接降为纯文本，域名被抢注成博彩/色情/诈骗站的自动拦下，抓不到的一律不降级。只改显示，不动数据库。
 * Version:     1.5.6
 * Author:      老张
 * Author URI:  https://laozhang.org/
 * Requires PHP: 7.4
 * Requires at least: 5.5
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-comment-guard
 *
 * @package wp-comment-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RCG_VERSION', '1.5.6' );
define( 'RCG_FILE', __FILE__ );
define( 'RCG_DIR', plugin_dir_path( __FILE__ ) );
define( 'RCG_URL', plugin_dir_url( __FILE__ ) );

/**
 * 旧主题自检：主题自己还带着这两个模块时，插件必须「待命」，不能抢着定义函数。
 *
 * 为什么（2026-10-17 拆分当天就想清楚的一条铁律）：
 *   WordPress 先加载插件、后加载主题。如果主题还是老版本（无条件 require
 *   inc/comment-link-guard.php），而插件已经把同名函数定义了 —— 主题再 require 一次
 *   就是 "Cannot redeclare function"，**整站前后台一起 500**，站长连后台都进不去。
 *   laozhang.org 当时还停在 1.4.4，完全符合这个条件。
 *
 * 判据是「主题会不会无条件 require」，**不是版本号**。
 *   2026-10-17 当天晚些时候主题版本号归位（1.4.0–1.11.5 整段移交插件，主题回到 1.3.x），
 *   如果还拿 version_compare 判大小会得出完全相反的结论：laozhang.org 的 1.4.4
 *   反而「大于」1.3.4，被当成新版 → 插件抢先定义 → 主题再 require → 整站 500。
 *   新主题的加载点长这样：`if ( ! function_exists( 'riven_link_guard_allow_url' ) ) {`
 *   旧主题没有这层判断，直接 require。查这个字符串最准，且与版本号彻底解耦。
 *
 * @return bool true = 主题自己管，插件待命
 */
function rcg_theme_still_owns() {
	static $owns = null;
	if ( null !== $owns ) {
		return $owns;
	}

	$owns = false;
	if ( function_exists( 'wp_get_theme' ) ) {
		$dir   = (string) wp_get_theme()->get_template_directory();
		$guard = $dir . '/inc/comment-link-guard.php';

		// 主题里根本没有这个文件 → 一定是新版（1.3.4 起已剥离），插件接管。
		if ( '' !== $dir && file_exists( $guard ) ) {
			/*
			 * 判据：**看主题自带的那份文件自己有没有重复定义保护**。
			 *   1.4.x 的文件里 `function_exists` 出现 0 次（裸 function 定义）
			 *   → 插件只要先定义，主题再 require 一次就是 Cannot redeclare → 整站 500。
			 *
			 * ⚠️ 曾经拿 functions.php 里的 `function_exists( 'riven_link_guard_allow_url' )`
			 * 当判据，2026-10-17 发 laozhang.org 安装包前自查发现是错的：
			 * 1.4.4 的**兜底块**里正好也有这串字，会被误判成「新主题」→ 插件抢先定义
			 * → 主题再 require → 500。所以判据必须落在「文件本身有没有保护」上。
			 *
			 * 偏向安全侧：判断不出来就待命。待命最坏是功能由主题提供，
			 * 误判成接管则整站白屏 —— 两者代价差着一个数量级。
			 */
			$src  = (string) @file_get_contents( $guard ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$owns = ( false === strpos( $src, 'function_exists' ) );
		}
	}
	return $owns;
}

/*
 * 自更新检查：**必须无条件加载**（2026-10-17 修）。
 *
 * 原来它排在下面对判定引擎的 require 之后，而待命分支是 return，
 * 于是「主题还是 1.4.4」的站永远检查不到更新 —— laozhang.org 正好就是这台
 * 没有 SSH、升级只能人工传 zip 的机器，最需要自动更新的反而是它。
 *
 * 它只依赖 RCG_FILE / RCG_VERSION 与 WP 原生函数，跟判定引擎零耦合。
 */
require_once RCG_DIR . 'inc/updater.php';

if ( rcg_theme_still_owns() ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p><b>WP 评论外链守卫：待命中，尚未接管。</b><br>'
				. '当前主题仍在自带这两个模块，插件的判定引擎没有加载（否则会函数重复定义、整站白屏）。'
				. '请先把主题更新到 <b>1.3.4</b>（与本插件配套的版本），本插件会自动接管。<br>'
				. '在此之前，外链防护由主题自己完成，功能不受影响。</p></div>';
		}
	);
	return; // 不加载任何模块 —— 主题跑自己的那份。
}

/* ------------------------------------------------------------------ 加载 */

require_once RCG_DIR . 'inc/settings.php';

/*
 * ⚠️ 这两个 require 必须带 function_exists 保护（2026-10-17 部署当场踩到）。
 *
 * 正常前台请求的顺序是「插件先、主题后」，插件定义完函数，主题那边
 * function_exists 为真就跳过了，看起来万事大吉。
 * 但 **启用插件的那一刻不是这个顺序**：不管后台点「启用」还是 wp-cli，
 * 都是在一个「主题已经加载完」的请求里 include 插件文件 ——
 * 主题的 riven_link_guard_* 早已定义，插件再定义一次就是
 * "Cannot redeclare function" → 激活请求直接 500，插件永远启用不了。
 *
 * 加了保护之后：
 *   · 正常请求：函数不存在 → 插件定义 → 主题跳过（插件接管）；
 *   · 启用请求：函数已存在 → 插件不重复定义 → 激活成功，下一个请求生效；
 *   · 主题旧版：见上面 rcg_theme_still_owns()，压根不走到这里。
 */
if ( ! function_exists( 'riven_link_guard_allow_url' ) ) {
	require_once RCG_DIR . 'inc/comment-link-guard.php';
}
if ( ! function_exists( 'riven_health_scan_batch' ) ) {
	require_once RCG_DIR . 'inc/link-health.php';
}

/* ------------------------------------------------------------------ 菜单 */

add_action(
	'admin_menu',
	function () {
		// 异常播报（1.5.0）：菜单上直接挂未读数，不用点进去才发现有新风险。
		// 只挂主菜单 —— 侧边栏折叠时也能看到；子菜单里再挂一遍会重复。
		$label = '外链守卫';
		if ( function_exists( 'riven_health_alert_badge_count' ) ) {
			$n = (int) riven_health_alert_badge_count();
			if ( $n > 0 ) {
				$label .= ' <span class="update-plugins count-' . $n . '"><span class="plugin-count">' . $n . '</span></span>';
			}
		}
		add_menu_page(
			'外链守卫',
			$label,
			'manage_options',
			'riven-guard',
			'rcg_settings_page',
			'dashicons-shield-alt',
			58
		);
		add_submenu_page( 'riven-guard', '设置', '设置', 'manage_options', 'riven-guard', 'rcg_settings_page' );
		// slug 保持 riven-health：老书签、主题设置页里的跳转链接都还能用。
		// 体检页由 link-health.php 提供；万一它没加载（例如激活请求里被跳过），
		// 宁可不显示这个子菜单，也不能让菜单点开就 fatal。
		if ( function_exists( 'riven_health_page' ) ) {
			add_submenu_page( 'riven-guard', '外链体检', '外链体检', 'manage_options', 'riven-health', 'riven_health_page' );
		}
	},
	9
);

/* -------------------------------------------------------- 插件列表快捷入口 */

/**
 * 在「插件」列表里插件名下面直接给「设置 / 外链体检」两个链接。
 *
 * 2026-10-17 老张反馈：插件列表里只有「停用」，找不到设置入口 ——
 * 侧边栏虽然有菜单，但人在插件页看状态时，惯例就是在名字下面点。
 */
add_filter(
	'plugin_action_links_' . plugin_basename( RCG_FILE ),
	function ( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		$mine = array(
			'settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=riven-guard' ) ) . '">设置</a>',
			// 使用说明直达（1.5.3）：已安装且无更新时，WordPress 的插件列表**没有「查看详情」入口**
			// （那个链接只给未安装的插件用），所以必须在这里给一个，否则用户装完找不到任何说明。
			'help'     => '<a href="' . esc_url( admin_url( 'admin.php?page=riven-guard&rcg-help=1#rcg-help' ) ) . '">使用说明</a>',
			// 直达「立刻检查更新」：点完回到设置页，顶上直接显示已是最新 / 发现 x.y.z。
			// 老张 2026-10-17 在 1.1.2 上找不到这个入口 —— 插件页是看版本的地方，就该在这里能点。
			'check'    => '<a href="' . esc_url( admin_url( 'admin.php?page=riven-guard&rcg-check=1' ) ) . '">检查更新</a>',
		);
		// 体检模块没加载时不给这个链接，免得点进去是一片空白。
		if ( function_exists( 'riven_health_page' ) ) {
			$mine['health'] = '<a href="' . esc_url( admin_url( 'admin.php?page=riven-health' ) ) . '">外链体检</a>';
		}
		return array_merge( $mine, (array) $links );
	}
);

/* ------------------------------------------------------------ 生命周期 */

/**
 * 激活：搬配置 + 建表 + 登记调度。
 *
 * 迁移放第一位：万一后面出错，配置已经落地了。
 */
register_activation_hook(
	__FILE__,
	function () {
		rcg_migrate_legacy();
		if ( function_exists( 'riven_health_install' ) ) {
			riven_health_install();
		}
		// 主题里那份旧的定时事件如果还在，先全部清掉，避免插件和主题各排一份。
		// 用 wp_clear_scheduled_hook 而不是单次 wp_unschedule_event：同一个 hook
		// 可能残留多个（历史上重复注册过），只删一个等于没清干净。
		wp_clear_scheduled_hook( 'riven_health_cron_hook' );
		if ( 'off' !== rcg_opt( 'health_auto_enable', 'on' ) ) {
			wp_schedule_event( time() + 120, 'hourly', 'riven_health_cron_hook' );
		}
	}
);

/**
 * 停用：摘掉定时事件。
 *
 * 不停用主题 —— 数据表和结论全部保留，重新启用立刻接上。
 * 但要注意：停用后前台会退回「插件缺失」的兜底（全部放行），
 * 主题那边会挂红色告警，这是设计好的降级，不会静默。
 */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'riven_health_cron_hook' );
	}
);

/**
 * 每次加载时补一次迁移。
 *
 * 原因：有的站是从旧主题直接把插件拷上来的，没走激活钩子；
 * 还有人先停用再启用。这个检查很便宜（一次 get_option），保证配置一定跟得上。
 */
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'rcg_migrated' ) !== RCG_VERSION ) {
			rcg_migrate_legacy();
		}
	},
	20
);

/* ------------------------------------------------------------ 升级提示 */

/**
 * 插件已接管、但主题仍是旧版（自带模块）时的告警。
 *
 * 这种情况不会冲突（主题的条件式加载会跳过），但那两个文件已经没人用了，
 * 留着容易变成"以为改了却改的是旧文件"的坑 —— 提醒老张去删。
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$theme = wp_get_theme();
		$dir   = (string) $theme->get_template_directory();
		if ( '' === $dir ) {
			return;
		}
		$left = array();
		foreach ( array( 'inc/link-health.php', 'inc/comment-link-guard.php', 'assets/helper/link-helper.ps1' ) as $f ) {
			if ( file_exists( $dir . '/' . $f ) ) {
				$left[] = $f;
			}
		}
		if ( ! $left ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><b>WP 评论外链守卫：主题里还留着旧副本</b><br>'
			. '插件已经接管，这些文件不再被加载，建议升级主题到 <b>1.3.4</b> 让它们被自动清掉：<br><code>'
			. implode( '</code><br><code>', array_map( 'esc_html', $left ) )
			. '</code></p></div>';
	}
);
