<?php
/**
 * 插件自更新：把「有没有新版」告诉 WordPress，剩下的交给核心。
 *
 * 为什么不像主题那样自己写下载/解压/覆盖（2026-10-17 的判断）：
 *   1. 核心的 Plugin_Upgrader 是**整目录替换**（clear_destination = true），
 *      不会出现主题更新器那种「包里删掉的文件永远残留」的问题，也不需要新增文件白名单
 *      —— 那两条正是 1.4.3 整站 500 的根因；
 *   2. 失败有核心的回滚与错误提示，自己写没有；
 *   3. 插件一出现在更新列表里，插件列表每行就自带「启用自动更新」开关，老张点一下即可全自动。
 *
 * 我们只负责两件事：① 说「有新版本 + 包在哪」；② 提供「查看详情」里的更新日志。
 *
 * 更新源：https://w.593200.xyz/theme-api/wp-comment-guard-update.json
 *
 * @package wp-comment-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'RCG_UPDATE_JSON' ) ) {
	define( 'RCG_UPDATE_JSON', 'https://w.593200.xyz/theme-api/wp-comment-guard-update.json' );
}

/**
 * 拉取更新信息（带缓存）。
 *
 * 缓存策略故意分两档：
 *   · 成功 → 3 小时。太短会让后台每次点插件页都发一次远程请求；太长则发版后看不到。
 *   · 失败 → 30 分钟。源站临时抽风时别把后台卡住，也别一直重试。
 *
 * @param bool $force 强制绕过缓存。
 * @return array|null
 */
function rcg_fetch_update_info( $force = false ) {
	static $memo       = null;
	static $memo_force = false; // 本请求内是否已经强制拉过一次
	// 后台任何页面带 force-check=1（「检查更新」链接）都直接拉源，
	// 不能只认插件页 —— 2026-10-17 首测发现：点「检查更新」落在
	// update-core.php 时缓存没被绕过，点了也看不到新版本。
	if ( ! $force && is_admin() && isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$force = true;
	}
	/*
	 * 同一请求内最多发一次远程请求。
	 *
	 * 强制模式下不能再看 $memo 就返回（那样设置页点了按钮还是旧缓存），
	 * 但也不能每次调用都重发 —— 设置页渲染一次、判定链、plugin_information
	 * 都可能调到这里，强制一次就变成连着打源站四次。
	 * 用 $memo_force 记住「本次请求已经强制拉过」，之后一律用那次结果。
	 */
	if ( $memo_force ) {
		return $memo;
	}
	if ( ! $force && null !== $memo ) {
		return $memo;
	}

	$cached = get_transient( 'rcg_update_info' );
	if ( ! $force && is_array( $cached ) ) {
		$memo = $cached;
		return $memo;
	}

	$res = wp_remote_get(
		add_query_arg( 'v', RCG_VERSION, RCG_UPDATE_JSON ),
		array(
			'timeout'    => 4,
			'user-agent' => 'WP-Comment-Guard/' . RCG_VERSION . '; ' . home_url( '/' ),
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		set_transient( 'rcg_update_info', array(), 30 * MINUTE_IN_SECONDS );
		$memo       = null;
		$memo_force = true; // 本次请求别再重试，4 秒超时试四次就是 16 秒
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['url'] ) ) {
		set_transient( 'rcg_update_info', array(), 30 * MINUTE_IN_SECONDS );
		$memo       = null;
		$memo_force = true;
		return null;
	}

	set_transient( 'rcg_update_info', $data, 3 * HOUR_IN_SECONDS );
	// 设置页要显示「上次成功检查：…」，时间戳落在 option 里（transient 会被清、也读不到写入时刻）。
	update_option( 'rcg_last_check', time(), false );
	$memo       = $data;
	$memo_force = true;
	return $memo;
}

/**
 * 后台「检查更新」时作废缓存，否则点了也看不到新版本。
 */
add_action(
	'load-plugins.php',
	function () {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( 'rcg_update_info' );
		}
	}
);

/**
 * 「检查更新」链接落在 update-core.php 时也能作废缓存（见 rcg_fetch_update_info 注释）。
 */
add_action(
	'load-update-core.php',
	function () {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( 'rcg_update_info' );
		}
	}
);

/**
 * 告诉 WordPress：有新版本，包在哪。
 *
 * @param object $transient update_plugins 的 transient。
 * @return object
 */
function rcg_check_update( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
		return $transient;
	}

	$info = rcg_fetch_update_info();
	if ( ! $info ) {
		return $transient;
	}

	$basename = plugin_basename( RCG_FILE );
	$current  = isset( $transient->checked[ $basename ] ) ? $transient->checked[ $basename ] : RCG_VERSION;

	if ( version_compare( (string) $info['version'], (string) $current, '>' ) ) {
		$transient->response[ $basename ] = (object) array(
			'id'             => 'laozhang.org/wp-comment-guard',
			'slug'           => 'wp-comment-guard',
			'plugin'         => $basename,
			'new_version'    => (string) $info['version'],
			'url'            => isset( $info['homepage'] ) ? (string) $info['homepage'] : 'https://laozhang.org/',
			'package'        => (string) $info['url'],
			'icons'          => array(),
			'banners'        => array(),
			'banners_rtl'    => array(),
			'tested'         => isset( $info['tested'] ) ? (string) $info['tested'] : '',
			'requires_php'   => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '',
			'compatibility'  => new stdClass(),
		);
		// 同一插件不能同时出现在「有新版本」和「已是最新」里。
		unset( $transient->no_update[ $basename ] );
	} else {
		unset( $transient->response[ $basename ] );
	}

	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'rcg_check_update' );

/**
 * 「查看详情」弹窗：版本、兼容性、更新日志。
 *
 * @param false|object|array $result 默认结果。
 * @param string             $action 动作名。
 * @param object             $args   参数（含 slug）。
 * @return false|object|array
 */
function rcg_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wp-comment-guard' !== $args->slug ) {
		return $result;
	}

	$info = rcg_fetch_update_info();
	if ( ! $info ) {
		return $result;
	}

	/*
	 * 1.5.3：把使用说明放进「查看详情」弹窗（Description / Installation / FAQ 三个 tab）。
	 *
	 * 起因：老张说"readme 在插件界面看不到" —— README.md 只是包里的一个文件，
	 * WordPress 的弹窗读的是 plugins_api 返回的 sections，不读 md 文件。
	 * 所以这几个 tab 必须在这里写全，否则用户装完只能在后台瞎点。
	 */
	$description = '<p><b>给 WordPress 评论外链做分级与体检</b>：久未往来的访客链接降为纯文本，'
		. '域名被抢注成博彩/色情/诈骗站的自动拦下，<b>抓不到的一律不降级</b>。</p>'
		. '<p>只改显示，不改数据库 —— 所有降级都是渲染时把 <code>&lt;a&gt;</code> 换成 <code>&lt;span&gt;</code>，'
		. '评论内容和数据库字段一个字不动。</p>'
		. '<h4>它解决什么问题</h4>'
		. '<p>博客跑久了，评论区会攒下几千个外链，而这些链接是会变的：</p>'
		. '<ul>'
		. '<li><b>老博友的域名被抢注</b> —— 十几年前一起写博客的人早不写了，域名到期被买走，'
		. '现在指向博彩站，链接还挂在你的文章下面。</li>'
		. '<li><b>人还在，旧域名没了</b> —— 他换了域名、还在留言，但旧域名随时可能被人注册。</li>'
		. '<li><b>域名变成交易所 / 资源站</b> —— 站还是那个站，内容已经不是个人博客了，'
		. '甚至是用 WordPress 搭的（光看"有没有 CMS 指纹"根本判断不出来）。</li>'
		. '</ul>'
		. '<p>手工一条条点开看，两万条评论要点一年。这个插件做三件事：'
		. '<b>看人还来不来 → 查域名现在是什么 → 只降级该降的</b>。</p>'
		. '<h4>判定顺序（前面命中就停，不再往下看）</h4>'
		. '<table class="widefat striped"><thead><tr><th style="width:60px">顺序</th><th>检查</th><th style="width:110px">结果</th></tr></thead><tbody>'
		. '<tr><td>1</td><td>站长手动加的白名单</td><td>放行</td></tr>'
		. '<tr><td>2</td><td>已实锤风险（博彩 / 色情 / 诈骗 / 域名待售）+ 黑名单</td><td><b>降级</b></td></tr>'
		. '<tr><td>3</td><td>死链（DNS 解析不了 / 404 / 410）</td><td><b>降级</b></td></tr>'
		. '<tr><td>4</td><td>域名已成停靠页 / 待售页</td><td><b>降级</b></td></tr>'
		. '<tr><td>5</td><td>近 1 年还有往来</td><td>放行（也不进体检）</td></tr>'
		. '<tr><td>6</td><td>超过 5 年毫无往来</td><td>归档降级</td></tr>'
		. '<tr><td>7</td><td>夹在中间的 · 已不是个人站</td><td><b>降级</b></td></tr>'
		. '<tr><td>8</td><td>其余</td><td>放行</td></tr>'
		. '</tbody></table>'
		. '<p class="description">第 5、6 条的时间阈值可在设置里改（默认 1 年 / 5 年）。</p>'
		. '<h4>两条铁律</h4>'
		. '<ul>'
		. '<li><b>抓不到 ≠ 有问题</b>：DNS 死了、403、超时、页面一片空白，一律不降级 —— 看不清就不给结论。</li>'
		. '<li><b>实锤结论不会被网络波动洗白</b>：一个域名一旦被判为风险，之后哪怕某次抓不到，结论仍然保留。</li>'
		. '</ul>';

	$installation = '<ol>'
		. '<li>后台上传 zip 包，启用。</li>'
		. '<li>左侧菜单出现「外链守卫」→ 进「设置」按需调整（默认值基本可用）。</li>'
		. '<li>进「外链体检」，点「连续扫描到完成」做第一次体检。</li>'
		. '</ol>'
		. '<p><b>第一次一定要扫描。</b>装好时体检表是空的，所有链接都按默认规则（只看往来时间）放行；'
		. '要它开始判断"域名现在是什么"，得先扫一遍。</p>'
		. '<p>只扫「1 年没来往 ~ 5 年没来往」之间的域名 —— 近一年还在留言的（人还在，站不太可能转手）不扫，'
		. '超过 5 年的也不扫（前台早按归档降级了，扫了白烧 CPU）。</p>'
		. '<p><b>耗时参考</b>（服务器并发抓取，实测约 2~4 秒 / 个）：</p>'
		. '<ul><li>100 个域名 ≈ 5 分钟</li><li>500 个 ≈ 25 分钟</li><li>2000 个 ≈ 2 小时</li></ul>'
		. '<p>扫描时页面会自己刷新推进，<b>可以切到别的标签页</b>，不会中断；万一白屏就刷新本页，进度在数据库里。'
		. '扫完之后每小时还会自动复查一批到期的（7 天一轮），不用再管。</p>'
		. '<p><b>服务器抓不到的站</b>：可以用「本机抓取助手」，在你自己的电脑上跑一个小脚本去抓、结果回传插件。'
		. '后台「外链体检」页面里有下载链接和一键脚本。</p>';

	$faq = '<h4>改了设置 / 扫完了，前台还是没变化？</h4>'
		. '<p>页面缓存。降级是渲染时替换，缓存插件存的是替换后的页面。'
		. '插件会自动清 WP Rocket、LiteSpeed Cache、W3 Total Cache、WP Super Cache、WP Fastest Cache、'
		. 'Autoptimize 的缓存；如果你用的是别的缓存插件或 CDN，手动清一次。</p>'
		. '<h4>有些站的链接变成纯文本了，我点开看明明是正常的？</h4>'
		. '<p>点开看的是"现在"，判定用的是数据库里"最近一次体检的结论"，可能已经过期；也可能是标题被误判。'
		. '用「外链体检 → 域名速查」把域名粘进去，能看到它每一步被判成什么、为什么。'
		. '觉得判错了就点「加入白名单」，之后不再参与判定。</p>'
		. '<h4>某个域名一直显示"抓不到"？</h4>'
		. '<p>多半是 IP 被对方防火墙拦了，或者站点只对国内网络开放。用「本机抓取助手」在你自己的网络上抓，'
		. '结果回传后判定跟服务器抓的一样准。</p>'
		. '<h4>会不会误伤正常访客？</h4>'
		. '<p>近一年来留过言的访客链接<b>一律放行</b>，不做任何体检判定。真正会被降级的只有两类：'
		. '确实变成黄赌 / 诈骗 / 待售页的，和五年以上毫无来往的。</p>'
		. '<h4>会改我的数据库吗？</h4>'
		. '<p>不会。插件只在前台渲染时替换标签。唯一的例外是「换域名后的网址替换」，'
		. '那个功能自带备份和撤销。</p>'
		. '<h4>卸载会丢数据吗？</h4>'
		. '<p><b>停用</b>：全部保留，前台退回不做任何处理（链接全部可点）。<br>'
		. '<b>删除插件</b>：清空体检表、黑白名单、设置和定时任务，<b>不可逆</b> '
		. '—— 两万个域名的体检结果重扫要好几个小时。只是想关掉的话，停用就够了。</p>';

	$changelog = '';
	if ( ! empty( $info['changelog'] ) && is_array( $info['changelog'] ) ) {
		foreach ( array_slice( $info['changelog'], 0, 20 ) as $rel ) {
			if ( ! is_array( $rel ) ) {
				continue;
			}
			$ver  = isset( $rel['version'] ) ? (string) $rel['version'] : '';
			$date = isset( $rel['date'] ) ? (string) $rel['date'] : '';
			$changelog .= '<h4>v' . esc_html( $ver ) . ( '' !== $date ? '（' . esc_html( $date ) . '）' : '' ) . '</h4><ul>';
			if ( ! empty( $rel['notes'] ) && is_array( $rel['notes'] ) ) {
				foreach ( $rel['notes'] as $note ) {
					$changelog .= '<li>' . wp_kses_post( $note ) . '</li>';
				}
			}
			$changelog .= '</ul>';
		}
	}
	if ( '' === $changelog ) {
		$changelog = '<p>更新日志见插件目录下的 CHANGELOG.md。</p>';
	}

	return (object) array(
		'name'          => 'WP 评论外链守卫',
		'slug'          => 'wp-comment-guard',
		'version'       => (string) $info['version'],
		'author'        => '<a href="https://laozhang.org/">老张</a>',
		'author_profile'=> 'https://laozhang.org/',
		'homepage'      => isset( $info['homepage'] ) ? (string) $info['homepage'] : 'https://laozhang.org/',
		'requires'      => isset( $info['requires_wp'] ) ? (string) $info['requires_wp'] : '5.5',
		'requires_php'  => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '7.4',
		'tested'        => isset( $info['tested'] ) ? (string) $info['tested'] : '',
		'last_updated'  => isset( $info['last_updated'] ) ? (string) $info['last_updated'] : '',
		'download_link' => (string) $info['url'],
		'sections'      => array(
			'description'  => $description,
			'installation' => $installation,
			'faq'          => $faq,
			'changelog'    => $changelog,
		),
	);
}
add_filter( 'plugins_api', 'rcg_plugin_info', 20, 3 );

/**
 * 升级完成后作废缓存，下一次检查能立刻看到「已是最新」。
 *
 * @param WP_Upgrader $upgrader 升级器实例。
 * @param array       $data     升级上下文。
 */
add_action(
	'upgrader_process_complete',
	function ( $upgrader, $data ) {
		if ( ! is_array( $data ) ) {
			return;
		}
		$action = isset( $data['action'] ) ? $data['action'] : '';
		$type   = isset( $data['type'] ) ? $data['type'] : '';
		if ( 'update' === $action && 'plugin' === $type ) {
			delete_transient( 'rcg_update_info' );
			// 核心的整目录替换会把包内不存在的旧文件一起清掉，
			// 所以这里不需要再做任何「清理旧副本」的动作。
		}
	},
	10,
	2
);
