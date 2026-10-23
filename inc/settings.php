<?php
/**
 * 配置层 + 设置页
 *
 * 为什么要单独一层：
 *   本插件从主题里拆出来（2026-10-17），原来所有阈值都躺在主题的独立 option 里
 *   （riven_opt('health_xxx') → get_option('riven_health_xxx')）。
 *   拆分的第一原则是**一个值都不能丢**：免考核期、人情味期、自定义风险词、
 *   以及最要命的 36 个黑名单域名 —— 丢了等于一次静默的全面放行。
 *
 * 所以采用「迁移 + 运行时兜底」双保险：
 *   ① 激活/版本变化时把老 option 逐个搬进 rcg_settings（已存在的不覆盖）；
 *   ② 即使迁移没跑成，rcg_opt() 读不到新值时会回退读老 option。
 *   老 option 一律**不删除**，留作备份 —— 老张的规矩：不删数据。
 *
 * @package wp-comment-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------ 存储与读取 */

/**
 * 需要从主题迁移过来的设置项。
 *
 * 前 19 个是设置页字段，后 3 个是程序自己维护的数据：
 *   health_blacklist   —— 拉黑的域名（最后一道保险，绝不能丢）
 *   health_whitelist   —— 人工白名单
 *   health_restore_enable —— 沉寂域名体检过关后是否自动恢复（代码里读，表单未暴露）
 *
 * @return string[]
 */
function rcg_setting_keys() {
	return array(
		'link_guard_enable',
		'link_guard_days',
		'link_guard_mercy_days',
		'link_guard_host_enable',
		'link_guard_admin_bypass',
		'health_enable',
		'health_verdict_enable',
		'health_block_enable',
		'health_fresh_months',
		'health_threshold',
		'health_timeout',
		'health_batch',
		'health_whitelist_ttl',
		'health_auto_enable',
		'health_ttl_days',
		'health_active_ttl_days',
		'health_urgent_ttl_days',
		'health_extra_words',
		'health_ignore_words',
		'health_block_message',
		'health_blacklist',
		'health_whitelist',
		'health_restore_enable',
	);
}

/**
 * 每个设置项的出厂默认值。
 *
 * 这里必须与各处 `rcg_opt( $key, $default )` 调用里写的默认值**逐字一致** ——
 * 迁移时拿它落库，等价于「把当前代码会返回的值固化下来」，行为零变化。
 *
 * 为什么要有一张表（2026-10-17 老张在 laozhang.org 上看到「4 / 22 项已迁移」）：
 *   laozhang.org 主题停在 1.4.4，那时候只有 4 个 link_guard_* 设置，
 *   体检（1.5.0+）、人情味期（1.10.0+）那些 option 在库里压根不存在，
 *   `get_option()` 返回 null → 老逻辑直接跳过 → 永远卡在「半迁移」状态，
 *   后台一直挂一条黄条说「这些项还在读主题里的旧值」，看着像故障。
 *   现在改成：老库有值就搬，没值就落默认值 → 一次到位 22/22，
 *   而且不必再依赖主题那边的 option 还在不在。
 *
 * @param string $key 去掉 riven_ 前缀的键名
 * @return mixed
 */
function rcg_setting_default( $key ) {
	$defaults = array(
		'link_guard_enable'       => 'on',
		'link_guard_days'         => 365,
		'link_guard_mercy_days'   => 1825,
		'link_guard_host_enable'  => 'on',
		'link_guard_admin_bypass' => 'on',
		'health_enable'           => 'on',
		'health_verdict_enable'   => 'on',
		'health_block_enable'     => 'on',
		'health_fresh_months'     => 12,
		'health_threshold'        => 50,
		'health_timeout'          => 12,
		'health_batch'            => 15,
		'health_whitelist_ttl'    => 30,
		'health_auto_enable'      => 'on',
		'health_ttl_days'         => 7,
		'health_active_ttl_days'  => 30,
		'health_urgent_ttl_days'  => 7,
		'health_extra_words'      => '',
		'health_ignore_words'     => '',
		'health_block_message'    => '你填写的网址已被本站列入黑名单，评论无法提交。如有疑问请联系站长。',
		'health_blacklist'        => array(),
		'health_whitelist'        => array(),
		'health_restore_enable'   => 'on',
	);
	return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : '';
}

/**
 * 把 rcg_settings 里缺的键补齐（迁移 / 保存后都调一次）。
 *
 * 只填缺的，绝不动已有的值 —— 站长自己改过的阈值不会被默认值盖掉。
 *
 * @return int 补了几个
 */
function rcg_fill_defaults() {
	$settings = rcg_settings( true );
	$filled   = 0;
	foreach ( rcg_setting_keys() as $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			continue;
		}
		$settings[ $key ] = rcg_setting_default( $key );
		$filled++;
	}
	if ( $filled > 0 ) {
		update_option( 'rcg_settings', $settings, false );
		rcg_settings( true );
	}
	return $filled;
}

/**
 * 取全部设置（单 option 数组，少 20 多次 get_option）。
 *
 * @param bool $reload 写完值后强制重读
 * @return array
 */
function rcg_settings( $reload = false ) {
	static $cache = null;
	if ( null === $cache || $reload ) {
		$cache = get_option( 'rcg_settings', array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
	}
	return $cache;
}

/**
 * 读一个设置项。
 *
 * 三级回退：插件 option → 主题老 option → 调用方默认值。
 * 第二级是拆分期的安全网：万一迁移没跑（比如插件被直接拷进目录、激活钩子没触发），
 * 老张的阈值照样生效，不会突然全变默认。
 *
 * @param string $key     去掉 riven_ 前缀的键名
 * @param mixed  $default 都没有时的默认值
 * @return mixed
 */
function rcg_opt( $key, $default = '' ) {
	$settings = rcg_settings();
	if ( array_key_exists( $key, $settings ) ) {
		return $settings[ $key ];
	}
	// 老 option 只读不写：让它永远保持迁移前的样子，出问题能对账。
	$legacy = get_option( 'riven_' . $key, null );
	if ( null !== $legacy ) {
		return $legacy;
	}
	return $default;
}

/**
 * 写一个设置项。
 *
 * @param string $key   键名
 * @param mixed  $value 值
 * @return mixed 原值
 */
function rcg_set( $key, $value ) {
	$settings         = rcg_settings( true );
	$settings[ $key ] = $value;
	update_option( 'rcg_settings', $settings, false );
	rcg_settings( true );
	return $value;
}

/* ------------------------------------------------------------ 年 / 天换算 */

/**
 * 后台按「年」填，库里按「天」存。
 *
 * 判定链、体检队列、历史值（365 / 1825）全都按天算，换算只发生在
 * "后台显示"和"后台保存"这两处 —— 这个设计从主题 1.10.0 沿用，不动。
 *
 * @param float $years     年数
 * @param float $min_years 最小年数
 * @return int 天数
 */
function rcg_years_to_days( $years, $min_years = 0 ) {
	$years = (float) $years;
	if ( $years < $min_years ) {
		$years = $min_years;
	}
	if ( $years > 20 ) {
		$years = 20;
	}
	return (int) round( $years * 365 );
}

/**
 * 天数转回年数（只用于后台显示）。
 *
 * @param int $days 天数
 * @return float
 */
function rcg_days_to_years( $days ) {
	return round( ( (int) $days ) / 365, 2 );
}

/* ---------------------------------------------------------------- 迁移 */

/**
 * 把主题里的老设置搬进插件。
 *
 * 幂等：已迁移过本版本就直接返回；插件里已有值的键一律不覆盖。
 * 老 option 不删 —— 留着对账，也留着万一要回退。
 *
 * 老库里根本没有的键（主题版本太旧，那些功能还没上线）→ 落出厂默认值。
 * 这样所有站最终都处在「22 项全部独立存储」一种状态，
 * 不会再出现「一半在插件、一半在读主题」的模糊态。
 *
 * @return int 本次搬过来（含补默认）的键数
 */
function rcg_migrate_legacy() {
	if ( get_option( 'rcg_migrated' ) === RCG_VERSION ) {
		return 0;
	}

	$settings = rcg_settings( true );
	$moved    = 0;
	$filled   = 0;

	foreach ( rcg_setting_keys() as $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			continue; // 插件里已经有值，绝不拿老的盖掉新的
		}
		$legacy = get_option( 'riven_' . $key, null );
		if ( null !== $legacy ) {
			$settings[ $key ] = $legacy;
			$moved++;
		} else {
			$settings[ $key ] = rcg_setting_default( $key );
			$filled++;
		}
	}

	update_option( 'rcg_settings', $settings, false );
	rcg_settings( true );
	update_option( 'rcg_migrated', RCG_VERSION, false );

	if ( $moved > 0 || $filled > 0 ) {
		error_log( '[rcg] 设置迁移：从主题搬来 ' . $moved . ' 个，补默认值 ' . $filled . ' 个' );
	}
	return $moved + $filled;
}

/**
 * 迁移体检：后台设置页顶部的状态条要用。
 *
 * @return array array( 已迁移数, 缺失键名数组 )
 */
function rcg_migrate_report() {
	$settings = rcg_settings();
	$ok       = 0;
	$missing  = array();
	foreach ( rcg_setting_keys() as $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			$ok++;
		} else {
			$missing[] = $key;
		}
	}
	return array( $ok, $missing );
}

/* ------------------------------------------------------------ 设置页渲染 */

/** 输出一个开关（radio） */
function rcg_field_radio( $name, $label, $options, $desc = '' ) {
	$value = (string) rcg_opt( $name, '' );
	if ( '' === $value && isset( $options['on'] ) ) {
		$value = 'on';
	}
	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
	foreach ( $options as $val => $text ) {
		printf(
			'<label style="margin-right:18px"><input type="radio" name="%s" value="%s"%s> %s</label>',
			esc_attr( $name ),
			esc_attr( $val ),
			checked( $value, $val, false ),
			esc_html( $text )
		);
	}
	if ( $desc ) {
		echo '<p class="description">' . $desc . '</p>';
	}
	echo '</td></tr>';
}

/** 输出数字框 */
function rcg_field_number( $name, $label, $desc = '', $min = 0, $max = 9999, $default = '' ) {
	$value = rcg_opt( $name, $default );
	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
	printf(
		'<input type="number" class="small-text" name="%s" value="%s" min="%s" max="%s">',
		esc_attr( $name ),
		esc_attr( $value ),
		esc_attr( $min ),
		esc_attr( $max )
	);
	if ( $desc ) {
		echo '<p class="description">' . $desc . '</p>';
	}
	echo '</td></tr>';
}

/** 输出年数框（内部按天存） */
function rcg_field_years( $name, $label, $desc = '', $min = 0, $max = 20, $default_days = 365 ) {
	$days  = (int) rcg_opt( $name, $default_days );
	$years = rcg_days_to_years( $days );
	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
	printf(
		/*
		 * step 必须是 0.1，不能是 0.5 —— 2026-10-17 老张反馈「免考核期填 1 年填不进去，
		 * 只能 1.1 / 1.6」。HTML5 的合法值是 min + n*step：免考核期 min=0.1，
		 * step=0.5 时合法集合是 0.1 / 0.6 / 1.1 / 1.6…，1.0 正好被判 stepMismatch，
		 * 浏览器直接拦下不让提交。而 1 年恰恰是默认值，最常用的就是这个。
		 * 改成 0.1 后任意一位小数都合法（实测 Chrome：min=0.1 step=0.1 输入 1 → 合法）。
		 */
		'<input type="number" class="small-text" name="%s" value="%s" min="%s" max="%s" step="0.1"> 年',
		esc_attr( $name ),
		esc_attr( $years ),
		esc_attr( $min ),
		esc_attr( $max )
	);
	echo ' <span class="description">（当前按 <code>' . (int) $days . '</code> 天算）</span>';
	if ( $desc ) {
		echo '<p class="description">' . $desc . '</p>';
	}
	echo '</td></tr>';
}

/** 输出文本框 */
function rcg_field_text( $name, $label, $desc = '', $placeholder = '' ) {
	$value = (string) rcg_opt( $name, '' );
	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
	printf(
		'<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s">',
		esc_attr( $name ),
		esc_attr( $value ),
		esc_attr( $placeholder )
	);
	if ( $desc ) {
		echo '<p class="description">' . $desc . '</p>';
	}
	echo '</td></tr>';
}

/** 输出多行文本 */
function rcg_field_textarea( $name, $label, $desc = '', $placeholder = '' ) {
	$value = (string) rcg_opt( $name, '' );
	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
	printf(
		'<textarea name="%s" rows="5" class="large-text code" placeholder="%s">%s</textarea>',
		esc_attr( $name ),
		esc_attr( $placeholder ),
		esc_textarea( $value )
	);
	if ( $desc ) {
		echo '<p class="description">' . $desc . '</p>';
	}
	echo '</td></tr>';
}

/** 设置页 */
function rcg_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	list( $migrated, $missing ) = rcg_migrate_report();
	$blacklist = rcg_opt( 'health_blacklist', array() );
	$whitelist = rcg_opt( 'health_whitelist', array() );
	$blacklist = is_array( $blacklist ) ? $blacklist : array();
	$whitelist = is_array( $whitelist ) ? $whitelist : array();
	$health_url = admin_url( 'admin.php?page=riven-health' );

	/*
	 * 版本 / 检查更新（2026-10-17 老张反馈：1.1.2 上根本找不到「检查更新」按钮）。
	 *
	 * 为什么非得有这个按钮：自更新走 WP 原生更新列表，而更新列表有 12 小时防抖
	 * （update_plugins 的 last_checked 没超时就整个跳过），刚发版时点「仪表盘 → 更新」
	 * 很可能看不到新版本，只能干等或者手动传包 —— laozhang.org 那台还没有 SSH。
	 * 这里点一下 = 绕过本插件自己的 3 小时缓存**立刻拉源**，并当场把结果说清楚。
	 *
	 * 注意：不点按钮时**不发任何远程请求**（只读 transient），设置页不会被拖慢 4 秒。
	 *
	 * 为什么整块必须是一个 .notice（2026-10-17 实测）：后台核心 JS 会把页面里所有
	 * .notice 统一搬到 h1 正后方（common.js 的
	 * $(".notice").not(".inline,.below-h2").insertAfter(...)）。
	 * 版本行原来是裸 <p>，跑在 notice 源码前面、却渲染到所有 notice 后面，
	 * 「检查更新」按钮被挤到配置存储条底下，很不显眼。做成 notice 且源码位置
	 * 最靠前，搬完它就紧贴标题，永远在第一屏。
	 */
	$force_check = isset( $_GET['rcg-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$info        = $force_check ? rcg_fetch_update_info( true ) : get_transient( 'rcg_update_info' );
	$has_info    = is_array( $info ) && ! empty( $info['version'] );
	$latest      = $has_info ? (string) $info['version'] : '';
	$has_new     = $has_info && version_compare( $latest, RCG_VERSION, '>' );
	$checked_at  = (int) get_option( 'rcg_last_check', 0 );
	$check_url   = add_query_arg(
		array(
			'page'      => 'riven-guard',
			'rcg-check' => '1',
		),
		admin_url( 'admin.php' )
	);
	$update_url  = admin_url( 'update-core.php?force-check=1' );
	?>
	<div class="wrap">
		<h1>外链守卫 · 设置</h1>

		<div class="notice notice-info">
			<p style="margin:8px 0">
				<b>插件版本：</b><?php echo esc_html( RCG_VERSION ); ?>
				<a class="button button-small" style="margin-left:10px;vertical-align:baseline" href="<?php echo esc_url( $check_url ); ?>">检查更新</a>
				<?php if ( $checked_at ) : ?>
					<span class="description" style="margin-left:8px">
						上次成功检查：<?php echo esc_html( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i', $checked_at ) : date_i18n( 'Y-m-d H:i', $checked_at ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $has_new ) : ?>
					&nbsp;·&nbsp;<b>发现新版本 <?php echo esc_html( $latest ); ?></b> ——
					<a href="<?php echo esc_url( $update_url ); ?>"><b>去更新</b></a>
					（想以后不用管：到「插件」列表把本插件的<b>「启用自动更新」</b>打开）
				<?php elseif ( $force_check && $has_info ) : ?>
					&nbsp;·&nbsp;<span style="color:#00a32a"><b>已是最新版本</b></span>（更新源当前版本 <?php echo esc_html( $latest ); ?>）
				<?php elseif ( $force_check ) : ?>
					&nbsp;·&nbsp;<span style="color:#b32d2e"><b>连不上更新源</b></span>
					<code><?php echo esc_html( RCG_UPDATE_JSON ); ?></code>
					—— 可能是源站临时不可用或本站出网受限，稍后再点一次；不影响插件本身运行。
				<?php endif; ?>
			</p>
		</div>

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>设置已保存。</p></div>
		<?php endif; ?>

		<?php if ( $missing ) : ?>
			<div class="notice notice-warning">
				<p>
					<b>配置未完整落库：</b>共 <?php echo count( rcg_setting_keys() ); ?> 项设置中，
					有 <b><?php echo count( $missing ); ?></b> 项目前按出厂默认值运行（<b>功能不受影响</b>）。
					在下方点一次「保存设置」即可补齐。
				</p>
			</div>
		<?php endif; ?>

		<div class="notice notice-info">
			<p>
				<b>安全台账：</b>人工黑名单 <b><?php echo count( $blacklist ); ?></b> 个域名，
				人工白名单 <b><?php echo count( $whitelist ); ?></b> 个。
				这两项只是「站长额外手动拉黑 / 放行」，<b>不是防护开关</b> ——
				内置风险词库随插件一起走，黑名单为 0 也照常拦截博彩、色情站点。
				体检明细见 <a href="<?php echo esc_url( $health_url ); ?>">外链体检</a>。
			</p>
		</div>

		<?php
		// 主题里还留着旧副本时，给一个一键清理入口。
		// 为什么不是"删"而是"改名"：老张的规矩是不删数据；万一哪天要停用插件回退，
		// 把 .legacy-bak 改回 .php 就恢复了，不需要重新下载任何东西。
		$theme_dir = (string) wp_get_theme()->get_template_directory();
		$legacy    = array();
		if ( '' !== $theme_dir ) {
			foreach ( array( 'inc/link-health.php', 'inc/comment-link-guard.php', 'assets/helper/link-helper.ps1' ) as $f ) {
				if ( file_exists( $theme_dir . '/' . $f ) ) {
					$legacy[] = $f;
				}
			}
		}
		?>
		<?php if ( $legacy ) : ?>
			<div class="notice notice-warning">
				<p><b>主题里还留着这两个模块的旧副本</b>（插件已经接管，它们不再被加载）：</p>
				<p><code><?php echo implode( '</code> <code>', array_map( 'esc_html', $legacy ) ); ?></code></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px">
					<input type="hidden" name="action" value="rcg_purge_legacy">
					<?php wp_nonce_field( 'rcg_purge_legacy' ); ?>
					<button type="submit" class="button">把旧副本改名停用（可回退）</button>
					<span class="description">只会改成 <code>.legacy-bak</code> 结尾，文件不删。想回退时把后缀去掉即可。</span>
				</form>
			</div>
		<?php endif; ?>

		<?php if ( isset( $_GET['purged'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>旧副本已改名停用。</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rcg_save_settings">
			<?php wp_nonce_field( 'rcg_save_settings' ); ?>

			<h2>外链分级守卫</h2>
			<p class="description" style="max-width:860px">
				访客多年前留下的个人站点，域名可能已被抢注用于博彩、色情等内容，而链接仍挂在你的文章下方。
				本插件的思路不是"猜对方站点好不好"（判断不准、成本也高），而是先问一句：<b>这个人最近还来不来</b>。
				久未往来的访客链接降为纯文本，仍在往来的照常显示。
			</p>
			<table class="form-table" role="presentation">
				<?php
				rcg_field_radio(
					'link_guard_enable',
					'外链分级',
					array( 'on' => '开启', 'off' => '关闭' ),
					'开启后：久未往来的访客，其昵称链接与评论正文里的外链一律降为<b>纯文本</b>，渲染后的网页源码里<b>不再出现该域名</b>；仍在往来的访客正常显示，并自动补上 <code>rel="nofollow ugc noopener external"</code>。<br><b>只改显示，不动数据库</b>。'
				);
				rcg_field_years(
					'link_guard_days',
					'免考核期',
					'最后一次往来在这个年数以内的链接<b>直接放行、不进体检队列</b>，也不会因"已不是个人站"被降级（风险站与死链仍然拦截）。<br>对方再次留言并通过审核后，计时自动重新开始。范围 0.1~20 年。'
					,
					0.1,
					20,
					365
				);
				rcg_field_years(
					'link_guard_mercy_days',
					'人情味期',
					'超过这么久<b>没有任何往来</b>的链接直接归档为纯文本，<b>连体检都不做</b>（节省服务器资源）。<br>对方再次留言并通过审核后，链接立即恢复并触发一次体检。填 <b>0</b> 表示关闭这一层。范围 0~20 年。',
					0,
					20,
					1825
				);
				rcg_field_radio(
					'link_guard_host_enable',
					'域名新鲜度',
					array( 'on' => '开启', 'off' => '关闭' ),
					'补上只按邮箱判定的漏洞：<b>对方换了域名、旧域名过期后被抢注，但本人仍在留言</b> —— 邮箱一直活跃，旧域名链接就会被永久保留。<br>开启后，某域名若连续这么久没有被任何访客填成自己的网址，指向它的链接一律停用（即使留这条评论的人昨天才来过）。<br>正文里那种从来没人当作自己网址填过的引用链接（工具站、代码托管等）不参与这一维，不会误伤。'
				);
				rcg_field_radio(
					'link_guard_admin_bypass',
					'站长豁免',
					array( 'on' => '开启', 'off' => '关闭' ),
					'开启后，有审核权限的用户登录时始终看到原始链接（含已降级的），方便回访老友。前台访客不受影响。<br>被屏蔽的链接会用<b>虚线 + 悬停提示</b>标出（橙=关系或时间原因、红=风险、灰=站点失效），便于分辨。'
				);
				?>
			</table>

			<h2>外链体检与持续监控</h2>
			<p class="description" style="max-width:860px">
				只看"还来不来"是不够的：活跃访客的域名也可能早已被卖掉并挂上博彩内容，而关系判定看不见这一点。
				体检会主动抓取域名首页，判断它<b>现在是什么站点</b>。<br>
				它不推翻上面的默认结论，只做纠偏：<b>活跃域名</b>查到风险才降级；<b>沉寂域名</b>需要确认「内容健康 + 仍是博客 + 近 N 个月还在更新」才恢复。<br>
				抓不到的（DNS 失效 / 403 / 超时）一律不改变现状 —— 看不清就不下结论。<br>
				所有动作<b>只改显示、不动数据库</b>。
			</p>
			<table class="form-table" role="presentation">
				<?php
				rcg_field_radio( 'health_enable', '体检总开关', array( 'on' => '开启', 'off' => '关闭' ), '关闭后不抓取、不判定、不拦截，插件只保留"按往来时间降级"的基本行为。' );
				rcg_field_radio( 'health_verdict_enable', '体检结果参与判定', array( 'on' => '开启', 'off' => '关闭' ), '开启后：① 体检确认为风险的域名，无论访客多活跃一律降级；② 沉寂域名若确认仍在正常更新，恢复可点击。' );
				rcg_field_radio( 'health_block_enable', '黑名单拦截提交', array( 'on' => '开启', 'off' => '关闭' ), '开启后，评论的<b>昵称网址或正文链接</b>命中黑名单时<b>直接拒绝提交</b>（不只是显示时降级）。只拦你明确拉黑过的域名，有审核权限的用户不受限制。' );
				rcg_field_number( 'health_fresh_months', '仍在更新的窗口（月）', '体检时从页面或 RSS 读取"最近更新日期"，距今天数在此窗口内才算"仍在更新"。默认 <b>12</b> 个月。', 1, 60, 12 );
				rcg_field_number( 'health_threshold', '风险阈值', '普通词命中一个 +10 分、高置信词 +50 分，总分达到此值判为风险。默认 <b>50</b>（约需 5 个普通词）。调低更严格，但误报会增多。', 10, 100, 50 );
				rcg_field_number( 'health_timeout', '抓取超时（秒）', '单个域名的抓取时长上限。默认 <b>12</b> 秒；站点以老旧站为主、抓取失败率偏高时可放宽到 15~20。', 5, 30, 12 );
				rcg_field_number( 'health_batch', '每批扫描数量', '「扫描下一批」「连续扫描」每批处理的域名数，可设 <b>5~60</b>，默认 15。<br>单次请求有 55 秒墙钟预算，未处理的域名自动留到下一批（不写库、不影响判定），因此调大只会加快推进，不会导致页面超时。', 5, 60, 15 );
				rcg_field_number( 'health_whitelist_ttl', '白名单保护期（天）', '手动恢复（「误判·恢复」）的域名，这么多天后会<b>重新接受体检</b>。域名随时可能被弃用，永久免检等于一直开着风险口子。<br>到期后若真变成风险站或待售页，会正常降级，并在「外链体检 → 人工白名单」处明确提示，不会静默处理。默认 <b>30</b> 天。', 1, 3650, 30 );
				?>
			</table>

			<h2>自动轮转</h2>
			<p class="description" style="max-width:860px">
				持续监控的关键：某个域名明年被卖掉并挂上非法内容，下一轮轮转就会抓出来并降级。
				<b>调度由本插件自行接管</b>，不依赖主题，也不影响主题切换。
			</p>
			<table class="form-table" role="presentation">
				<?php
				rcg_field_radio( 'health_auto_enable', '自动轮转体检', array( 'on' => '开启', 'off' => '关闭' ), '开启后每小时自动扫描一批到期域名，<b>无需手动操作</b>。关闭后仅支持手动扫描。' );
				rcg_field_number( 'health_ttl_days', '轮转间隔（天）', '同一域名两次体检的最小间隔，<b>只作用于「免考核期 ~ 人情味期」之间的域名</b>。默认 <b>7</b> 天。<br>调小更敏感，但抓取更频繁；调大更省资源，但发现异常的延迟更久。', 1, 90, 7 );
				rcg_field_number( 'health_active_ttl_days', '活跃域名复查间隔（天）', '免考核期内的域名不参与"是否为个人站"的考核，但<b>风险仍要检查</b> —— 最容易出问题的正是"人还常来、域名却被抢注"。默认 <b>30</b> 天。填 0 = 完全不复查（最省资源，但风险不可见）。', 0, 365, 30 );
				rcg_field_number( 'health_urgent_ttl_days', '人还在、域名不用了的复查间隔（天）', '<b>人活跃、域名长期无人使用</b>的域名单独走这一档：多半是<b>换了新域名</b>，旧域名随时可能被抢注。默认压到 <b>7</b> 天复查一次，抓到风险/待售/死链立即降级；抓不到或仍然健康则照常放行（<b>不因"域名旧"降级</b>）。填 0 = 关闭此项加速。', 0, 30, 7 );
				?>
			</table>

			<h2>词库</h2>
			<table class="form-table" role="presentation">
				<?php
				rcg_field_textarea(
					'health_extra_words',
					'自定义风险词',
					'内置词库共 <b>217 个词</b>：博彩 42 / 色情 29 / 算命 26 / 诈骗 21（以上普通词每个 +10 分）、
					高置信词 72 个（组合词或平台名，正常内容几乎不会出现，每个 +50 分）、域名待售/停用页词 27 个。<br>
					此处可补充你发现的词，一行一组，可用空格或逗号分隔。<b>添加后请到「外链体检」点一次「全部重扫」</b>，否则已有域名在轮转间隔内不会重扫，看不到新词效果。',
					'例：糖心 旗舰厅 555WIN'
				);
				rcg_field_textarea(
					'health_ignore_words',
					'停用词（不再参与打分）',
					'内置词中有几个可能误伤正常站点（例如「成人」会命中成人教育、「兼职」会命中正常招聘、「开户」会命中银行证券）。填入此处后不再参与打分。<br><b>词库只增不减，停用是唯一的缩减方式。</b>',
					'例：成人 兼职 开户'
				);
				rcg_field_text( 'health_block_message', '拦截提示语', '评论命中黑名单时向访客显示的提示。', '你填写的网址已被本站列入黑名单，评论无法提交。如有疑问请联系站长。' );
				?>
			</table>

			<h2>使用说明</h2>
			<details id="rcg-help"<?php echo isset( $_GET['rcg-help'] ) ? ' open' : ''; ?> style="max-width:880px;margin:0 0 18px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:10px 16px">
				<summary style="cursor:pointer;font-weight:600;color:#2271b1">第一次用？点开看：怎么开始、扫多久、判定顺序、常见问题</summary>
				<div style="margin-top:12px;font-size:13px;line-height:1.8">

					<h3 style="margin:14px 0 6px;font-size:14px">一、三步开始</h3>
					<ol style="margin:0 0 10px 20px;padding:0">
						<li>下面的设置按需改（默认值基本可用）。</li>
						<li>进左侧菜单「外链体检」，点 <b>连续扫描到完成</b>，让它把所有域名先看一遍。</li>
						<li>扫完就不用管了 —— 之后每小时自动复查一批到期的（7 天一轮）。</li>
					</ol>
					<p class="description" style="margin:0 0 12px">
						<b>第一次一定要扫。</b>装好时体检表是空的，所有链接只按「访客还来不来」判断，<b>还没看过域名现在是什么站</b>。<br>
						只扫「1 年没来往 ~ 5 年没来往」之间的域名：近一年还在留言的不扫（人还在，站不太可能转手），
						超过 5 年的也不扫（前台早按归档降级了）。<br>
						<b>耗时</b>（实测约 2~4 秒 / 个）：100 个 ≈ 5 分钟 · 500 个 ≈ 25 分钟 · 2000 个 ≈ 2 小时。
						扫描时页面会自己刷新推进，<b>可以切到别的标签页</b>；白屏就刷新本页，进度在数据库里。
					</p>

					<h3 style="margin:16px 0 6px;font-size:14px">二、判定顺序（前面命中就停，不再往下看）</h3>
					<table class="widefat striped" style="max-width:680px;margin-bottom:10px">
						<thead><tr><th style="width:52px">顺序</th><th>检查</th><th style="width:130px">结果</th></tr></thead>
						<tbody>
							<tr><td>1</td><td>你手动加的白名单</td><td>放行</td></tr>
							<tr><td>2</td><td>已实锤风险（博彩/色情/诈骗/待售）+ 黑名单</td><td>降级</td></tr>
							<tr><td>3</td><td>死链（DNS 解析不了 / 404 / 410）</td><td>降级</td></tr>
							<tr><td>4</td><td>域名已成停靠页 / 待售页</td><td>降级</td></tr>
							<tr><td>5</td><td>近 1 年还有往来</td><td>放行（也不进体检）</td></tr>
							<tr><td>6</td><td>超过 5 年毫无往来</td><td>归档降级</td></tr>
							<tr><td>7</td><td>夹在中间的 · 已不是个人站</td><td>降级</td></tr>
							<tr><td>8</td><td>其余</td><td>放行</td></tr>
						</tbody>
					</table>
					<p class="description" style="margin:0 0 12px">
						第 5、6 条的时间阈值就是上面的「免考核期」「人情味期」，改完不用重扫，判定实时生效。<br>
						<b>两条铁律</b>：① 抓不到 ≠ 有问题（DNS 死了、403、超时、空白页一律不降级）；
						② 实锤结论不会被网络波动洗白（判过风险的域名，之后某次抓不到也照样拦）。
					</p>

					<h3 style="margin:16px 0 6px;font-size:14px">三、常见问题</h3>
					<p style="margin:0 0 8px"><b>改了设置 / 扫完了，前台还是没变化？</b>
						页面缓存。降级是渲染时替换，缓存插件存的是替换后的页面。插件会自动清 WP Rocket、LiteSpeed、
						W3TC、WP Super Cache、WP Fastest Cache、Autoptimize；用 CDN 的话手动清一次。</p>
					<p style="margin:0 0 8px"><b>某个正常站被降级了？</b>
						去「外链体检 → 域名速查」把域名粘进去，能看到它每一步被判成什么、为什么；判错了就点「加入白名单」，之后不再参与判定。</p>
					<p style="margin:0 0 8px"><b>某个域名一直「抓不到」？</b>
						多半是 IP 被对方防火墙拦了。用页面下方的「本机抓取助手」，在你自己的网络上抓、结果回传，判定标准完全一致。</p>
					<p style="margin:0 0 8px"><b>会改我的评论吗？</b>
						不会。只在前台渲染时替换标签，正文和数据库字段一个字不动。唯一例外是「换域名后的网址替换」，那个自带备份和撤销。</p>
					<p style="margin:0"><b>卸载会丢数据吗？</b>
						停用 = 全部保留、前台退回不做任何处理；删除插件 = 清空体检表与黑白名单，<b>不可逆</b>（两万个域名重扫要好几个小时）。只是想关掉就停用。</p>
				</div>
			</details>

			<h2>关于</h2>
			<div class="card" style="max-width:860px;padding:4px 16px">
				<p>
					<b>数据与隐私：</b>检测结果、黑名单、白名单、体检记录全部保存在<b>你自己的数据库</b>里，
					不向任何第三方上报。插件运行时的外部请求只有一种：<b>抓取待检域名的首页</b>
					（用于判断该站点现在是什么内容），不发送任何本站信息。
				</p>
				<p>
					<b>数据库：</b>本插件只新增自己的数据表（体检记录、黑白名单），
					<b>不修改、不删除任何评论数据</b>；所有降级都是渲染层的纯文本替换，
					撤掉插件即恢复原状。
				</p>
				<p>
					<b>不绑定域名：</b>词库、判据随插件走，装在任何站点行为一致。
				</p>
			</div>

			<?php submit_button( '保存设置' ); ?>
		</form>

		<h2>本机抓取助手</h2>
		<p class="description" style="max-width:860px">
			体检从站点服务器发起，而服务器在海外 —— 对国内博客常常连不上。本机助手用你电脑的网络去抓，判定仍在站点端做，两边标准完全一致。<br>
			<b>已经下载过 bat 的不用重下</b>：令牌和接口地址都没变。需要重新下载时到
			<a href="<?php echo esc_url( $health_url ); ?>">外链体检</a> 页面底部。
		</p>
	</div>
	<?php
}

/* ---------------------------------------------------------------- 保存 */

/** 处理设置表单提交 */
function rcg_handle_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足。' );
	}
	check_admin_referer( 'rcg_save_settings' );

	$years_fields = array( 'link_guard_days', 'link_guard_mercy_days' );
	$int_fields   = array(
		'health_fresh_months'     => array( 1, 60, 12 ),
		'health_threshold'        => array( 10, 100, 50 ),
		'health_timeout'          => array( 5, 30, 12 ),
		'health_batch'            => array( 5, 60, 15 ),
		'health_whitelist_ttl'    => array( 1, 3650, 30 ),
		'health_ttl_days'         => array( 1, 90, 7 ),
		'health_active_ttl_days'  => array( 0, 365, 30 ),
	);
	$radio_fields = array(
		'link_guard_enable'      => array( 'on', 'off' ),
		'link_guard_host_enable' => array( 'on', 'off' ),
		'link_guard_admin_bypass'=> array( 'on', 'off' ),
		'health_enable'          => array( 'on', 'off' ),
		'health_verdict_enable'  => array( 'on', 'off' ),
		'health_block_enable'    => array( 'on', 'off' ),
		'health_auto_enable'     => array( 'on', 'off' ),
	);
	$text_fields  = array( 'health_extra_words', 'health_ignore_words' );

	foreach ( $radio_fields as $key => $allowed ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$val = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		rcg_set( $key, in_array( $val, $allowed, true ) ? $val : 'off' );
	}

	foreach ( $years_fields as $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = trim( (string) wp_unslash( $_POST[ $key ] ) );
		if ( '' === $raw ) {
			rcg_set( $key, 'link_guard_mercy_days' === $key ? 1825 : 365 );
			continue;
		}
		// 免考核期最少 0.1 年；人情味期允许 0 = 关闭这一层
		rcg_set( $key, rcg_years_to_days( $raw, 'link_guard_mercy_days' === $key ? 0 : 0.1 ) );
	}

	foreach ( $int_fields as $key => $spec ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = trim( (string) wp_unslash( $_POST[ $key ] ) );
		if ( '' === $raw ) {
			rcg_set( $key, $spec[2] );
			continue;
		}
		$n = (int) $raw;
		rcg_set( $key, ( $n < $spec[0] || $n > $spec[1] ) ? $spec[2] : $n );
	}

	// 紧急复查间隔：清空回默认 7，0 表示关闭加速，越界一律回 7。
	if ( isset( $_POST['health_urgent_ttl_days'] ) ) {
		$raw = trim( (string) wp_unslash( $_POST['health_urgent_ttl_days'] ) );
		if ( '' === $raw ) {
			rcg_set( 'health_urgent_ttl_days', 7 );
		} else {
			$n = (int) $raw;
			rcg_set( 'health_urgent_ttl_days', ( 0 === $n ) ? 0 : ( ( $n >= 1 && $n <= 30 ) ? $n : 7 ) );
		}
	}

	foreach ( $text_fields as $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		rcg_set( $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) );
	}

	if ( isset( $_POST['health_block_message'] ) ) {
		rcg_set( 'health_block_message', sanitize_text_field( wp_unslash( $_POST['health_block_message'] ) ) );
	}

	// 时间闸门防呆：人情味期必须长于免考核期，否则两个圈首尾颠倒。
	$grace_days = absint( rcg_opt( 'link_guard_days', 365 ) );
	$mercy_days = absint( rcg_opt( 'link_guard_mercy_days', 1825 ) );
	if ( $mercy_days > 0 && $mercy_days <= $grace_days ) {
		rcg_set( 'link_guard_mercy_days', $grace_days + 1 );
	}

	// 表单没暴露的键（黑名单、白名单、restore_enable）在这里补齐，
	// 保证「点一次保存 = 22 项全落到插件自己的存储」。只填缺的，不覆盖已有值。
	rcg_fill_defaults();

	wp_safe_redirect( admin_url( 'admin.php?page=riven-guard&saved=1' ) );
	exit;
}
add_action( 'admin_post_rcg_save_settings', 'rcg_handle_save_settings' );

/**
 * 把主题里的旧副本改名停用。
 *
 * 改名而不是删除：老张的规矩是不删数据。想回退时把 .legacy-bak 后缀去掉就恢复，
 * 不需要重新下载任何东西 —— 对 laozhang.org 那种只能手动传文件、没有 SSH 的站点
 * 尤其重要。
 */
function rcg_handle_purge_legacy() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足。' );
	}
	check_admin_referer( 'rcg_purge_legacy' );

	// ⚠️ 只有插件自己确实在跑（函数由插件提供）才允许动主题文件，
	// 否则可能把当前唯一的实现删掉 —— 那就是自断经脉。
	$probe = new ReflectionFunction( 'riven_link_guard_on' );
	if ( false === strpos( $probe->getFileName(), 'wp-comment-guard' ) ) {
		wp_die( '插件尚未接管，现在不能清理主题副本。请先确认插件已启用。' );
	}

	$theme_dir = (string) wp_get_theme()->get_template_directory();
	$stamp     = gmdate( 'Ymd' );
	$done      = 0;

	foreach ( array( 'inc/link-health.php', 'inc/comment-link-guard.php', 'assets/helper/link-helper.ps1' ) as $f ) {
		$src = $theme_dir . '/' . $f;
		if ( ! file_exists( $src ) ) {
			continue;
		}
		$dst = $src . '.legacy-bak-' . $stamp;
		if ( @rename( $src, $dst ) ) {
			$done++;
		}
	}

	wp_safe_redirect( admin_url( 'admin.php?page=riven-guard&purged=' . $done ) );
	exit;
}
add_action( 'admin_post_rcg_purge_legacy', 'rcg_handle_purge_legacy' );
