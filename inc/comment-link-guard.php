<?php
/**
 * 评论外链分级守卫（comment link guard）
 *
 * 为什么要有这个：
 *   十几年的博客，评论里沉淀了上万条访客留下的个人站点地址。域名是有寿命的 ——
 *   停更、忘记续费、被抢注，最后挂上博彩或色情内容，而那条链接还静静地躺在文章
 *   下面。国内已经有博主因为早年留言里的一个域名变成违规站点，被网信办约谈。
 *
 * 为什么不能用"检测对方站点"来判定：
 *   ① 要访问几千个外部站点，慢，而且永远跑不完；
 *   ② 判定标准模糊 —— 正常老博客也会因为页面里恰好出现敏感词被误伤；
 *   ③ 就算今天干净，明天变了又得重跑。这是一场打不赢的仗。
 *
 * 所以换个问法：不去猜"对方站点好不好"，只问"我还认不认这个人 / 这个域名"。
 *
 * 判定有两个维度，**任一过期就停掉链接**（2026-10-14 补的第二维度）：
 *
 *   一、人（邮箱）：该邮箱最后一次**通过审核**的评论距今超过 N 天（默认 365）
 *       → 昵称降为纯文本，评论正文里的外链也一并降为纯文本。
 *       关键是**渲染后的 HTML 里根本没有 href**，不是"看得见点不了"。
 *
 *   二、站（域名）：该域名最后一次**被任何一位通过审核的访客填成自己的网址**
 *       距今超过 N 天 → 同样停用。
 *       为什么需要这一条：光看邮箱是漏的 —— 博友换了域名，旧域名过期被抢注成
 *       违规站，可他本人还在用同一个邮箱留言。邮箱活跃，旧域名链接就一直挂着，
 *       风险一点没少。实测本站这类链接有 1068 条 / 63 个域名，其中 96% 确认是
 *       "人还在、旧域名早换了"。加上域名这一维，这批全部收进去。
 *
 *   两个维度都新鲜 → 正常显示，但补上 rel="nofollow ugc noopener external"。
 *   他哪天回来再留一条言、或重新填上还在用的域名，链接自动恢复，计时重新开始。
 *
 * 关于误伤：正文里那种从来没人当自己网址填过的引用链接（工具站、代码托管等），
 *   域名这一维"无从判断"，就不参与判定，只按人的维度走 —— 不会把正常引用变灰。
 *
 * 不改数据库，只改输出 —— 关掉开关或改天数立刻恢复原样，随时可回滚。
 * 有审核权限的人（站长）登录时始终看到原始链接，方便回访老博友。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------- 配置 */

function riven_link_guard_on() {
	return rcg_opt( 'link_guard_enable', 'on' ) !== 'off';
}

/**
 * 免考核期（内部单位：天）：最后一次往来在这个天数以内 → **免予体检考核，直接放行**
 *
 * 2026-10-17（1.10.0）语义变了：以前它是"超过就降级"的唯一门槛，现在它是
 * 「时间闸门」的内圈 —— 圈里（近一年还在来往的）根本不进体检队列，
 * 也不允许拿"不是个人站"去卡它。
 *
 * ⚠️ 后台是按「年」填的（1.10.1），换算只发生在 admin-settings.php 的保存处：
 *    年 × 365 → 存这里。**判定链一律按天读**，别在这里再乘一次。
 */
function riven_link_guard_days() {
	$d = absint( rcg_opt( 'link_guard_days', 365 ) );
	return $d > 0 ? $d : 365;
}

/**
 * 人情味期（天）：超过这么久毫无往来 → 链接归档，**连体检都不做了**
 *
 * 老张 2026-10-17 的原话：「五年都没有来我们留言互访，我保持你的链接五年，
 * 也算是人情味给足了。」
 *
 * 为什么敢一刀切：实测 >5 年那 1460 个域名里，死的/待售的/看不清的占 81%，
 * 体检它们就是拿服务器去"垃圾里捡食材"。而真正还活着的博客，只要回来留一句话，
 * approved 那一刻就重新进入"免考核期"（riven_health_touch_host 会把它推回队列）。
 *
 * 返回 0 表示关闭这一层（不做"人情味期"降级）。
 */
function riven_link_guard_mercy_days() {
	$d = absint( rcg_opt( 'link_guard_mercy_days', 1825 ) );
	if ( $d <= 0 ) {
		return 0;
	}
	// 防呆：人情味期必须比免考核期长，否则两个圈会首尾颠倒
	return max( $d, riven_link_guard_days() + 1 );
}

/** 域名这一维要不要参与判定（默认开） */
function riven_link_guard_host_on() {
	return rcg_opt( 'link_guard_host_enable', 'on' ) !== 'off';
}

function riven_link_guard_admin_bypass() {
	return rcg_opt( 'link_guard_admin_bypass', 'on' ) !== 'off';
}

/** 当前这次请求是否完全放行（站长登录时看原始链接） */
function riven_link_guard_bypass_now() {
	// 强制以"访客视角"计算（给站长视角加"仅站长可见"标记时用，见 riven_link_guard_guest_allow_url）
	if ( ! empty( $GLOBALS['riven_link_guard_force_guest'] ) ) {
		return false;
	}
	return riven_link_guard_admin_bypass() && current_user_can( 'moderate_comments' );
}

/**
 * 「访客视角」下这条链接会不会被放行（临时关掉站长豁免算一遍）
 *
 * 只用来判断"站长能看到、但访客看不到"的链接 —— 这些要在站长视角下打标记，
 * 让站长知道"这个链接访客那边是屏蔽掉的"。
 *
 * @param object $comment
 * @param string $url
 * @return bool
 */
function riven_link_guard_guest_allow_url( $comment, $url ) {
	$GLOBALS['riven_link_guard_force_guest'] = true;
	$r = riven_link_guard_allow_url( $comment, $url );
	unset( $GLOBALS['riven_link_guard_force_guest'] );
	return (bool) $r;
}

/** 「仅站长可见」的说明文案（按屏蔽原因给出不同解释） */
function riven_link_guard_admin_only_tip( $kind = 'stale' ) {
	switch ( $kind ) {
		case 'risk':
			$why = '已被安全检测标记为风险';
			break;
		case 'dead':
			$why = '对方站点已无法访问';
			break;
		case 'unreachable':
			// 这一条**不会降级**，只是提醒站长"我这个角度看不见"。
			// 访客仍可点（海外连不上、国内正常的情况很常见），所以不能套下面那句
			// "访客看不到"——那是假话（2026-10-17 自查时发现文案会说反）。
			return '本站服务器抓不到这个站点（超时/403/内容异常），未作降级，访客仍可点击';
		case 'archived':
			$why = '多年前就已没有往来，链接已按"人情味期"归档';
			break;
		default:
			$why = '域名已经不是个人站了（久未往来，体检发现它变成了企业站/导航站/空壳页）';
			break;
	}
	return '访客看不到这个链接（' . $why . '），只有你登录时能点到';
}

/** 判断"访客看不到这条链接"的原因 */
function riven_link_guard_mute_kind( $url, $comment = null ) {
	$v = riven_link_guard_guest_verdict( $comment, $url );
	if ( ! empty( $v['kind'] ) ) {
		return $v['kind'];
	}
	// 兜底：极致情况下 verdict 放行但仍被降级（例如体检模块刚被关掉），
	// 至少给个准确的类别，别一律说成"久未往来"。
	if ( function_exists( 'riven_health_is_risk' ) && riven_health_is_risk( $url ) ) {
		return 'risk';
	}
	if ( function_exists( 'riven_health_is_dead' ) && riven_health_is_dead( $url ) ) {
		return 'dead';
	}
	if ( function_exists( 'riven_health_is_unreachable' ) && riven_health_is_unreachable( $url ) ) {
		return 'unreachable';
	}
	return 'stale';
}

/**
 * 这条链接"访客看不到"吗？看不到的话返回原因 kind，看得到（或不是站长视角）返回空串
 *
 * @return string '' | 'stale' | 'risk' | 'dead' | 'unreachable'
 */
function riven_link_guard_admin_only_kind( $comment, $url ) {
	if ( ! riven_link_guard_bypass_now() ) {
		return '';
	}
	if ( ! $comment || '' === (string) $url ) {
		return '';
	}
	if ( riven_link_guard_guest_allow_url( $comment, $url ) ) {
		// 访客能看、但本站抓不到 → 只给站长一条灰色提示，**不降级**。
		// 1.5.6 定稿是"站长视野不少于访客，差异用标记表达"；这个 unreachable 标记
		// 在后来重构判定链时丢了（CSS 和文案都还在，函数却再也不会返回它），
		// 2026-10-17 自查补回 —— 站长看页面就是为了确认"哪些链接点开是空的"。
		if ( function_exists( 'riven_health_is_unreachable' ) && riven_health_is_unreachable( $url ) ) {
			return 'unreachable';
		}
		return '';
	}
	return riven_link_guard_mute_kind( $url, $comment );
}

/**
 * 给 a 标签属性加上"仅站长可见"的 class + title（已存在的 class 会合并，不重复输出属性）
 *
 * @param string $attrs 已有属性（可传空串）
 * @param string $kind  屏蔽原因
 * @return string
 */
function riven_link_guard_admin_mark( $attrs, $kind ) {
	$class = trim( 'riven-link-admin-only riven-link-admin-' . $kind );
	if ( preg_match( '/\bclass\s*=\s*["\']([^"\']*)["\']/i', $attrs, $m ) ) {
		// 同一个链接可能被处理两次（get_comment_text 与 comment_text 都挂了钩子），
		// 直接拼接会把 class 叠成 "riven-link-admin-only riven-link-admin-only…"。
		// 这里按词去重（2026-10-17 自查）。
		$parts = preg_split( '/\s+/', trim( (string) $m[1] ) );
		foreach ( explode( ' ', $class ) as $one ) {
			if ( '' !== $one && ! in_array( $one, $parts, true ) ) {
				$parts[] = $one;
			}
		}
		$attrs = preg_replace(
			'/\bclass\s*=\s*["\'][^"\']*["\']/i',
			'class="' . esc_attr( implode( ' ', array_filter( $parts ) ) ) . '"',
			$attrs,
			1
		);
	} else {
		$attrs .= ' class="' . esc_attr( $class ) . '"';
	}
	if ( false === stripos( $attrs, 'title=' ) ) {
		$attrs .= ' title="' . esc_attr( riven_link_guard_admin_only_tip( $kind ) ) . '"';
	}
	return $attrs;
}

/**
 * 站长视角专属属性：这条链接访客看不到、只有站长能点时，返回 ' class="..." title="..."'
 *
 * 2026-10-16 定稿：站长视野必须**不少于**访客 —— 区分状态的活交给这里的标记做，
 * 而不是把站长的链接也降级（那样反而让他少看到东西，老张实测发现）。
 *
 * @param object $comment
 * @param string $url
 * @return string 不需要标记时返回空串
 */
function riven_link_guard_admin_only_attrs( $comment, $url ) {
	$kind = riven_link_guard_admin_only_kind( $comment, $url );
	return '' === $kind ? '' : riven_link_guard_admin_mark( '', $kind );
}

/* ------------------------------------------------- 批量取邮箱最后活动时间 */

/**
 * 一次 SQL 把当前这批评论涉及的邮箱"最后一次通过审核的评论时间"全取回来，
 * 避免每条评论各查一次。结果缓存在静态变量里，同一次请求内不会重复查。
 *
 * @param array $comments WP_Comment 列表
 */
function riven_link_guard_prime( $comments ) {
	static $known = array();

	if ( empty( $comments ) || ! is_array( $comments ) ) {
		return;
	}

	global $wpdb;

	$emails = array();
	foreach ( $comments as $c ) {
		$e = strtolower( trim( isset( $c->comment_author_email ) ? (string) $c->comment_author_email : '' ) );
		if ( '' !== $e && ! isset( $known[ $e ] ) ) {
			$emails[ $e ] = true;
		}
	}
	$emails = array_keys( $emails );
	if ( empty( $emails ) ) {
		return;
	}

	// 分批，避免 IN 子句过长
	foreach ( array_chunk( $emails, 300 ) as $chunk ) {
		$ph    = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		$sql   = "SELECT comment_author_email AS e, MAX(comment_date) AS d
		          FROM {$wpdb->comments}
		          WHERE comment_approved = '1' AND comment_author_email IN ( $ph )
		          GROUP BY comment_author_email";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ) );
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$known[ strtolower( (string) $r->e ) ] = (string) $r->d;
			}
		}
		// 查不到的（比如只有待审核评论）记为空，表示"没有通过审核的往来"
		foreach ( $chunk as $e ) {
			if ( ! isset( $known[ $e ] ) ) {
				$known[ $e ] = '';
			}
		}
	}

	$GLOBALS['riven_link_guard_last'] = $known;
}

/** 当前请求里缓存的 mapping；没有就返回空数组 */
function riven_link_guard_last_map() {
	return isset( $GLOBALS['riven_link_guard_last'] ) ? $GLOBALS['riven_link_guard_last'] : array();
}

/* --------------------------------------------------------- 活跃度判定 */

/**
 * 这位访客还算"熟人"吗？
 *
 * @param string $email
 * @return bool true = 保留可点击链接
 */
function riven_link_guard_active( $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return false;
	}

	$map = riven_link_guard_last_map();
	if ( ! isset( $map[ $email ] ) ) {
		// 没预查过（比如单条评论单独渲染）：临时补一次，保持结果一致
		global $wpdb;
		$d = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(comment_date) FROM {$wpdb->comments}
				 WHERE comment_approved = '1' AND comment_author_email = %s",
				$email
			)
		);
		$GLOBALS['riven_link_guard_last'][ $email ] = $d ? (string) $d : '';
		$map[ $email ] = $d ? (string) $d : '';
	}

	$last = $map[ $email ];
	if ( '' === $last ) {
		return false;
	}
	$ts = strtotime( $last . ' UTC' );
	if ( ! $ts ) {
		$ts = strtotime( $last );
	}
	if ( ! $ts ) {
		return false;
	}

	return ( time() - $ts ) <= riven_link_guard_days() * DAY_IN_SECONDS;
}

/* ------------------------------------------------- 域名新鲜度（站维度） */

/** 域名归一：小写、去掉 www. */
function riven_link_guard_host_key( $host ) {
	$host = (string) $host;
	// 1.11.2：访客粘贴网址时常把空格带进来，浏览器编码成 %20 ——
	// 于是库里出现了 "%20www.zoujiang.com"、"ihaihe.%20cn" 这种"域名"。
	// 它们必然 DNS 解析失败 → 被判死链 → 前台无辜降级（真实站点其实是好的）。
	// 归一化前先解码，再把残留空白全部去掉。
	if ( false !== strpos( $host, '%' ) ) {
		$host = rawurldecode( $host );
	}
	$host = strtolower( trim( $host ) );
	$host = preg_replace( '/\s+/', '', $host );
	$host = preg_replace( '/^www\./', '', $host );
	$host = trim( $host, '.' );
	// 1.4.0：畸形 host 清洗。参考 nickel-comment-link-guard 的 validate_needle/is_clean_host ——
	// 它在入库前用双重校验挡掉 http://h/、http://http@/ 这类提取残留（host 变成 h/http/www），
	// 以及含 @ / / / 空格的脏值。我们这边原来只去空白，这些脏值会：
	//   ① 进考核池白跑一次抓取；② 更糟的是污染黑白名单与删除操作的 LIKE 匹配。
	// 中文域名特意放行（ Punycode 之前博客圈有人在用），但要求至少含一个点。
	if ( '' === $host || strlen( $host ) < 4 || strlen( $host ) > 191 ) {
		return '';
	}
	if ( false === strpos( $host, '.' ) ) {
		return '';
	}
	if ( preg_match( '#[^a-z0-9\-.\x{4e00}-\x{9fff}]#u', $host ) ) {
		return '';
	}
	if ( in_array( $host, array( 'http.com', 'https.com', 'www.com', 'ftp.com' ), true ) ) {
		return '';
	}
	return $host;
}

/**
 * 全站「域名 -> 最后一次被某位通过审核的访客填成自己网址」的时间戳。
 *
 * 这一维是补"人还在、旧域名早换了"的漏洞：博友 A 2011 年用 qinfei.info 留言，
 * 后来换成 qfsyj.com，人一直在。只看邮箱的话，那条 qinfei.info 永远不会被停掉。
 *
 * 全表扫一遍不便宜，所以结果进 transient（6 小时），有新评论或评论状态变化时作废。
 *
 * @return array host => timestamp
 */
function riven_link_guard_host_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$cached = get_transient( 'riven_link_guard_hostmap' );
	if ( is_array( $cached ) ) {
		$map = $cached;
		return $map;
	}

	global $wpdb;
	$map = array();

	$rows = $wpdb->get_results(
		"SELECT comment_author_url AS u, MAX(comment_date) AS d
		 FROM {$wpdb->comments}
		 WHERE comment_approved = '1' AND comment_author_url <> ''
		 GROUP BY comment_author_url"
	);
	if ( is_array( $rows ) ) {
		$site_host = riven_link_guard_host_key( wp_parse_url( home_url(), PHP_URL_HOST ) );
		foreach ( $rows as $r ) {
			$h = riven_link_guard_host_key( wp_parse_url( trim( (string) $r->u ), PHP_URL_HOST ) );
			if ( '' === $h || $h === $site_host ) {
				continue;
			}
			$ts = strtotime( $r->d . ' UTC' );
			if ( ! $ts ) {
				$ts = strtotime( (string) $r->d );
			}
			if ( ! $ts ) {
				continue;
			}
			if ( ! isset( $map[ $h ] ) || $ts > $map[ $h ] ) {
				$map[ $h ] = $ts;
			}
		}
	}

	set_transient( 'riven_link_guard_hostmap', $map, 6 * HOUR_IN_SECONDS );
	return $map;
}

/**
 * 「域名 -> 用过它的访客里最新的那位，最后一次评论时间」
 *
 * host_map 只回答"这个域名最后一次被人**填成作者网址**是什么时候"。时间闸门要的却是
 * **最后一次往来**（人、站两维取较新者，与 riven_link_guard_lapsed_ts 同口径）。
 *
 * 两者不等价的场景（2026-10-17 自查发现）：博友换了新域名，旧域名再没人填，但人还在
 * 用新域名留言 —— 只看 host_map 会把旧域名判成"多年没往来"直接归档、连体检都不做；
 * 而他本人其实还在活跃，旧域名被抢注成黄站也永远发现不了（这正是老张 10-14 提的漏洞）。
 *
 * 缓存 6 小时，评论新增/审核/删除时与 host_map 一起作废。
 */
function riven_link_guard_host_owner_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$cached = get_transient( 'riven_link_guard_hostowner' );
	if ( is_array( $cached ) ) {
		$map = $cached;
		return $map;
	}

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT LOWER(comment_author_email) AS e, comment_author_url AS u, MAX(comment_date) AS d
		 FROM {$wpdb->comments}
		 WHERE comment_approved = '1' AND comment_author_url <> '' AND comment_author_email <> ''
		 GROUP BY e, u"
	);

	$person    = array();   // email => 该邮箱最后一次评论（不限域名）
	$pairs     = array();   // [host, email]
	$site_host = riven_link_guard_host_key( wp_parse_url( home_url(), PHP_URL_HOST ) );

	foreach ( (array) $rows as $r ) {
		$e = trim( (string) $r->e );
		$h = riven_link_guard_host_key( (string) wp_parse_url( (string) $r->u, PHP_URL_HOST ) );
		if ( '' === $e || '' === $h || $h === $site_host || strlen( $h ) < 4 || false === strpos( $h, '.' ) ) {
			continue;
		}
		$ts = strtotime( (string) $r->d . ' UTC' );
		if ( ! $ts ) {
			$ts = strtotime( (string) $r->d );
		}
		if ( ! $ts ) {
			continue;
		}
		if ( ! isset( $person[ $e ] ) || $ts > $person[ $e ] ) {
			$person[ $e ] = $ts;
		}
		$pairs[] = array( $h, $e );
	}

	$map = array();
	foreach ( $pairs as $p ) {
		list( $h, $e ) = $p;
		if ( ! isset( $person[ $e ] ) ) {
			continue;
		}
		if ( ! isset( $map[ $h ] ) || $person[ $e ] > $map[ $h ] ) {
			$map[ $h ] = $person[ $e ];
		}
	}

	set_transient( 'riven_link_guard_hostowner', $map, 6 * HOUR_IN_SECONDS );
	return $map;
}

/**
 * 这个域名还算"有人在用"吗？
 *
 * @param string $host
 * @return bool|null true = 新鲜；false = 早没人用了；null = 无从判断
 *                   （从来没有人把它填成自己的网址，通常是正文里的引用链接 → 不参与判定）
 */
function riven_link_guard_host_fresh( $host ) {
	$h = riven_link_guard_host_key( $host );
	if ( '' === $h ) {
		return null;
	}
	$map = riven_link_guard_host_map();
	if ( ! isset( $map[ $h ] ) ) {
		return null;
	}
	return ( time() - $map[ $h ] ) <= riven_link_guard_days() * DAY_IN_SECONDS;
}

/**
 * 这条评论的外链要不要保留可点击（只看"人"这一维）
 *
 * @param object $comment
 * @return bool
 */
function riven_link_guard_allow( $comment ) {
	if ( ! riven_link_guard_on() ) {
		return true;
	}
	if ( riven_link_guard_bypass_now() ) {
		return true;
	}
	// 登录用户（含站长自己）发的评论一律放行
	if ( $comment && ! empty( $comment->user_id ) && (int) $comment->user_id > 0 ) {
		return true;
	}
	return riven_link_guard_active( isset( $comment->comment_author_email ) ? $comment->comment_author_email : '' );
}

/**
 * 这条评论里指向 $url 的链接要不要保留可点击
 *
 * 人、站两维都要过关：任一过期就停用。域名"无从判断"时（正文引用型）不拦。
 *
 * @param object $comment
 * @param string $url
 * @return bool
 */
/**
 * 这位访客跟本站**还有往来**吗（2026-10-16，1.8.0 起用途变了）
 *
 * ⚠️ 用途变更（重要）：它以前决定"要不要撤链接"，现在**只决定要不要严格审**
 * —— 也就是"要不要拿『已经不是个人站了』去卡它"。所以口径也放宽了：
 * **人和域名任一新鲜就算还有往来**。
 *
 *   人还在来   → 他本人在用这个网址，不可能是转手来的
 *   域名还有人在用 → 这个域名还活跃，也不像被卖掉
 *   引用型链接（从没人拿它当自己网址，如 github.com）→ 同样不怀疑
 *
 * 老张 2026-10-16 的质疑 + 实测：关系判定问的是"这个人还来不来"，而链接风险
 * 取决于"他的站现在是什么"——两者没有因果关系。实测 619 个被判"停更"的域名里
 * 站还好好的（32% 误伤），1942 个被降级的里 1121 个域名 DNS 活着。
 *
 * @param object $comment
 * @param string $url
 * @return bool
 */
function riven_link_guard_in_touch( $comment, $url ) {
	// 人：该邮箱最近 N 天内还有过审评论
	if ( riven_link_guard_allow( $comment ) ) {
		return true;
	}
	// 站：这个域名最近 N 天内还被任何已审访客填成过作者网址
	if ( riven_link_guard_host_on() ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( $host ) {
			// false = 早没人用了；true/null（引用型）都算"不怀疑"
			if ( false !== riven_link_guard_host_fresh( $host ) ) {
				return true;
			}
		}
	}
	return false;
}

/* ------------------------------------------- 时间闸门（2026-10-17，1.10.0） */

/**
 * 这位访客（邮箱）最后一次通过审核的评论时间戳；没有则 0
 *
 * 与 riven_link_guard_active() 共用同一份静态缓存，不多查库。
 */
function riven_link_guard_person_ts( $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return 0;
	}

	$map = riven_link_guard_last_map();
	if ( ! isset( $map[ $email ] ) ) {
		global $wpdb;
		$d = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(comment_date) FROM {$wpdb->comments}
				 WHERE comment_approved = '1' AND comment_author_email = %s",
				$email
			)
		);
		$GLOBALS['riven_link_guard_last'][ $email ] = $d ? (string) $d : '';
		$map[ $email ] = $d ? (string) $d : '';
	}

	$last = $map[ $email ];
	if ( '' === $last ) {
		return 0;
	}
	$ts = strtotime( $last . ' UTC' );
	if ( ! $ts ) {
		$ts = strtotime( $last );
	}
	return $ts ? $ts : 0;
}

/**
 * 这条链接的"最后一次往来"是什么时候（时间戳）
 *
 * 老张明确：**按最后一次评论时间算**。这里取两个维度里较新的那个 ——
 *   人（该邮箱最后一次过审评论） / 站（该域名最后一次被已审访客填成作者网址）
 * 口径与 riven_link_guard_in_touch() 保持一致，免得"判定说还在往来、
 * 归档说早没来往"这种自相矛盾。
 *
 * @return int 0 = 无从判断（例如正文里的引用型链接）
 */
function riven_link_guard_lapsed_ts( $comment, $url ) {
	$ts = 0;

	if ( $comment && ! empty( $comment->comment_author_email ) ) {
		$ts = riven_link_guard_person_ts( $comment->comment_author_email );
	}

	$host = riven_link_guard_host_key( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
	// 关闭「域名新鲜度」时，域名这一维整体退出判定（包括时间闸门）——
	// 否则站长关掉开关后，归档仍然按域名时间算，等于开关只关了一半（2026-10-17 自查）。
	if ( '' !== $host && riven_link_guard_host_on() ) {
		$map = riven_link_guard_host_map();
		if ( isset( $map[ $host ] ) && (int) $map[ $host ] > $ts ) {
			$ts = (int) $map[ $host ];
		}
	}

	return $ts;
}

/**
 * 这条链接是不是已经过了「人情味期」（久到不必再考核了）
 *
 * 无从判断的一律返回 false —— "我们不知道" 永远不能成为降级的理由。
 */
function riven_link_guard_beyond_mercy( $comment, $url ) {
	$mercy = riven_link_guard_mercy_days();
	if ( $mercy <= 0 ) {
		return false;
	}
	$ts = riven_link_guard_lapsed_ts( $comment, $url );
	if ( ! $ts ) {
		return false;
	}
	return ( time() - $ts ) > $mercy * DAY_IN_SECONDS;
}

/**
 * 统一判定：这条链接该怎么处理
 *
 * **所有**判定入口都必须走这里（以前 allow_url 和 scrub 各写了一遍，改一处漏一处）。
 *
 * @param object|null $comment
 * @param string      $url
 * @return array{allow:bool,kind:string} kind ∈ '' | risk | dead | archived | stale
 */
function riven_link_guard_verdict( $comment, $url ) {
	$pass = array(
		'allow' => true,
		'kind'  => '',
	);

	if ( ! riven_link_guard_on() ) {
		return $pass;
	}
	if ( riven_link_guard_bypass_now() ) {
		return $pass;
	}

	// ① 站长的人工白名单 —— **必须排在最前面**（1.3.3 修）。
	//    原来它排在 risk/junk 之后，等于白名单永远救不回已被判"风险/废弃"的域名：
	//    老张点「已废弃」表里的"误判·恢复"，前台照旧降级。
	//    人工意志高于机器判定 —— 站长亲手放行的站，机器不再翻案。
	//    注意：白名单管不到"人情味期归档"（那是时间问题不是站的问题）。
	if ( function_exists( 'riven_health_is_whitelisted' ) && riven_health_is_whitelisted( $url ) ) {
		return $pass;
	}

	// ② 已知实锤风险（黄赌/诈骗/配资/待售停靠）—— 任何情况降级。
	//    这里只查体检表与黑名单，**不触发抓取**：老张要的"免考核"是省抓取预算，
	//    不是把已经攥在手里的风险结论扔掉。
	if ( function_exists( 'riven_health_is_risk' ) && riven_health_is_risk( $url ) ) {
		return array(
			'allow' => false,
			'kind'  => 'risk',
		);
	}

	// ③ 已知死链（dns / http404 / http410）—— 同上，查表即可
	if ( function_exists( 'riven_health_is_dead' ) && riven_health_is_dead( $url ) ) {
		return array(
			'allow' => false,
			'kind'  => 'dead',
		);
	}

	// ③.5 域名已经是停靠页 / 待售页 / 未配置页（2026-10-17，1.3.1）
	//      客观事实，必须排在「免考核期」之前 —— 否则访客还在活跃的老域名，
	//      哪怕变成了"正在转让出售中 / Coming Soon"，也会被 ④ 直接放行。
	if ( function_exists( 'riven_health_is_parked' ) && riven_health_is_parked( $url ) ) {
		return array(
			'allow' => false,
			'kind'  => 'parked',
		);
	}

	// ④ 免考核期：近 N 天还有往来 → 直接放行，也不允许拿"非个人站"卡它
	if ( riven_link_guard_in_touch( $comment, $url ) ) {
		return $pass;
	}

	// ⑤ 人情味期：久到尽头了 → 归档（顺带从体检队列里摘出去，省一次抓取）
	if ( riven_link_guard_beyond_mercy( $comment, $url ) ) {
		return array(
			'allow' => false,
			'kind'  => 'archived',
		);
	}

	// ⑥ 只有夹在中间的（免考核期之外、人情味期之内）才走体检考核
	if ( function_exists( 'riven_health_is_nonpersonal' ) && riven_health_is_nonpersonal( $url ) ) {
		return array(
			'allow' => false,
			'kind'  => 'stale',
		);
	}

	// ⑦ 其余放行：停更的老博客、我们抓不到/看不清的站
	return $pass;
}

/** 强制按访客视角算一遍（站长视角下判断"访客那边看不看得到"） */
function riven_link_guard_guest_verdict( $comment, $url ) {
	// 1.5.0：改成**可嵌套**。原来无条件 unset，外层若已经设了访客模式
	// （域名速查要连续跑十几条判定），内层一退就把外层标记清了，
	// 剩下的调用又回到站长视角 —— 诊断结果会和前台对不上。
	$had  = array_key_exists( 'riven_link_guard_force_guest', $GLOBALS );
	$prev = $had ? $GLOBALS['riven_link_guard_force_guest'] : null;
	$GLOBALS['riven_link_guard_force_guest'] = true;
	$v = riven_link_guard_verdict( $comment, $url );
	if ( $had ) {
		$GLOBALS['riven_link_guard_force_guest'] = $prev;
	} else {
		unset( $GLOBALS['riven_link_guard_force_guest'] );
	}
	return $v;
}

/**
 * 这条评论的外链要不要保留可点击
 *
 * ===== 判据（2026-10-17 老张拍板，1.10.0：加时间闸门）=====
 *
 *   ① 站长人工白名单                            → 放行（**1.3.3 起排最前**，人工意志最高）
 *   ② 已知实锤风险（黄赌/诈骗/待售停靠）        → 降级（查表，不抓取）
 *   ②.5 域名停靠/待售/未配置页（看标题）         → 降级（客观事实，不看往来）
 *   ③ 已知死链（dns / 404 / 410）               → 降级（查表，不抓取）
 *   ④ 免考核期（近 N 天有往来，默认 1 年）       → 放行，不进体检
 *   ⑤ 人情味期（超过 Y 天毫无往来，默认 5 年）    → 归档，不进体检
 *   ⑥ 夹在中间的（N ~ Y 年）                     → 才走体检考核
 *   ⑦ 其余                                       → 放行
 *
 * 为什么加 ④⑤：老张 2026-10-17 的反思 —— 现存链接大多是十几年前留下的，
 * 要么打不开、要么早变成企业站/导航站/黄站，"相当于在垃圾里捡食材"。
 * 与其把服务器算力平摊在 2000 多个域名上，不如只考核真正还有可能来往的那批。
 * 实测考核池从 2086 缩到 489（23%），而 >5 年那批里 81% 本来就是死的/待售的/看不清的。
 *
 * @param object $comment
 * @param string $url
 * @return bool
 */
function riven_link_guard_allow_url( $comment, $url ) {
	$v = riven_link_guard_verdict( $comment, $url );
	return ! empty( $v['allow'] );
}

/**
 * 手上只有邮箱和网址时用（留言排行榜是自己查 SQL 拼的，没有 WP_Comment 对象）
 *
 * @param string $email
 * @param string $url
 * @param int    $user_id 0 = 游客
 * @return bool
 */
function riven_link_guard_allow_for( $email, $url, $user_id = 0 ) {
	$stub = (object) array(
		'comment_author_email' => (string) $email,
		'user_id'              => (int) $user_id,
	);
	return riven_link_guard_allow_url( $stub, $url );
}

/* ------------------------------------------------------- 降级后的提示 */

/**
 * 降级提示文案
 *
 * 2026-10-16 改：原来写「该站点长时间未更新」——那是在替对方下结论，
 * 我们其实不知道人家更不更新，只知道"他没有再来本站留言"。这句是在说假话，
 * 也冤枉了那些一直正常写博客、只是不来走动的老朋友。现在只陈述我们自己的判据。
 *
 * @param string $kind 'stale'（久无往来）| 'risk'（体检实锤风险）
 * @return string
 */
function riven_link_guard_muted_tip( $kind = 'stale' ) {
	if ( 'risk' === $kind ) {
		return '该链接已被本站安全检测标记为风险，暂时停用';
	}
	if ( 'dead' === $kind ) {
		return '该链接已失效（对方站点已无法访问），暂时停用';
	}
	if ( 'parked' === $kind ) {
		// 停靠/待售/未配置：不说"风险"，也不说"无法访问"（其实打得开），
		// 只陈述事实 —— 域名已经不在任何人手上了。
		return '该域名已停用（页面已是域名待售/停靠页），链接暂时停用';
	}
	if ( 'archived' === $kind ) {
		// 人情味期：不替对方下结论，只陈述"我们之间"的事实
		return sprintf( '此链接已归档（最后一次往来已是 %s前），暂时停用', riven_link_guard_mercy_text() );
	}
	if ( 'stale' === $kind ) {
		// 1.11.3：这是"体检没发现博客特征"，和"多少天没往来"是两回事。
		// 以前漏了这个分支，落到下面的默认文案，于是会出现
		// "该访客已超过 365 天未在本站往来"—— 而对方可能半年前刚来过，
		// 降级的真实原因是站点变成了企业站/导航站。文案说反了比不说更糟。
		return '此链接已停用（对方站点已不是个人博客）';
	}
	$days = riven_link_guard_days();
	return sprintf(
		'此链接已归档（该访客已超过 %d 天未在本站往来），暂时停用',
		$days
	);
}

/**
 * 人情味期的自然语言表达（"5 年" / "1.5 年" / "几个月"）
 *
 * 2026-10-17（1.10.1）后台改成按年填写，这里也统一按年说 ——
 * 否则填了 1.5 年（548 天）会在提示里变成"548 天"，跟后台对不上。
 */
function riven_link_guard_mercy_text() {
	$d = riven_link_guard_mercy_days();
	if ( $d <= 0 ) {
		return '很久';
	}
	$years = $d / 365;
	if ( $d >= 30 ) {
		// 5.00 → "5"、1.50 → "1.5"、0.50 → "0.5"
		return rtrim( rtrim( number_format( $years, 1, '.', '' ), '0' ), '.' ) . ' 年';
	}
	return $d . ' 天';
}

/**
 * 把降级后的文字包一层带提示的 span
 *
 * 只加提示，不引入任何 href —— 渲染后的 HTML 里依然没有那个域名。
 *
 * @param string $inner 原本的链接文字
 * @param string $kind  降级原因
 * @return string
 */
function riven_link_guard_muted( $inner, $kind = 'stale' ) {
	$tip = riven_link_guard_muted_tip( $kind );
	return '<span class="riven-link-muted" title="' . esc_attr( $tip ) . '"'
		. ' aria-label="' . esc_attr( $tip ) . '">' . $inner . '</span>';
}

/**
 * 按网址自动判断降级原因的版本（模板里用，省得在模板里写判断）
 *
 * 2026-10-17 改为走统一判定：这样"人情味期归档"的链接提示的是
 * 「最后一次往来已是 5 年前」，而不是笼统的"超过 365 天"。
 *
 * @param string      $inner
 * @param string      $url
 * @param object|null $comment 没有评论对象时只按域名维度算（留言排行榜就是这种情况）
 * @return string
 */
function riven_link_guard_muted_for( $inner, $url = '', $comment = null ) {
	$kind = 'stale';
	if ( '' !== $url ) {
		$v = riven_link_guard_guest_verdict( $comment, $url );
		if ( ! empty( $v['kind'] ) ) {
			$kind = $v['kind'];
		}
	}
	return riven_link_guard_muted( $inner, $kind );
}

/**
 * 只有邮箱、没有 WP_Comment 对象时用（留言排行榜是自己查 SQL 拼的）
 *
 * 2026-10-17 自查：排行榜原来调 muted_for() 时没传评论对象，导致"人情味期归档"
 * 这类判断丢掉"人"这一维，只能用域名时间算 —— 提示语可能跟实际判定对不上。
 *
 * @param string $inner
 * @param string $email
 * @param string $url
 * @return string
 */
function riven_link_guard_muted_by( $inner, $email, $url ) {
	$stub = (object) array(
		'comment_author_email' => (string) $email,
		'user_id'              => 0,
	);
	return riven_link_guard_muted_for( $inner, $url, $stub );
}

/* ------------------------------------------------------------ HTML 处理 */

/** 两个 host 是否算同一站点（忽略 www 与子域） */
function riven_link_guard_same_host( $a, $b ) {
	$a = strtolower( preg_replace( '/^www\./', '', (string) $a ) );
	$b = strtolower( preg_replace( '/^www\./', '', (string) $b ) );
	if ( '' === $a || '' === $b ) {
		return false;
	}
	return $a === $b || substr( $a, - strlen( $b ) - 1 ) === '.' . $b;
}

/** 给 a 标签补 rel，已有的不重复加 */
function riven_link_guard_add_rel( $attrs ) {
	$want = array( 'nofollow', 'ugc', 'noopener', 'external' );
	if ( preg_match( '/\brel\s*=\s*["\']([^"\']*)["\']/i', $attrs, $rm ) ) {
		$parts = preg_split( '/\s+/', trim( $rm[1] ) );
		foreach ( $want as $w ) {
			if ( ! in_array( $w, $parts, true ) ) {
				$parts[] = $w;
			}
		}
		$rel = implode( ' ', $parts );
		return preg_replace( '/\brel\s*=\s*["\'][^"\']*["\']/i', 'rel="' . $rel . '"', $attrs, 1 );
	}
	return $attrs . ' rel="' . implode( ' ', $want ) . '"';
}

/**
 * 处理一段 HTML 里的外链
 *
 * @param string      $html
 * @param bool        $allow  **已废弃**（1.10.0 起逐条走 verdict）。保留参数只为兼容
 *                            子主题/插件的旧调用，函数内部不再使用它。
 * @param object|null $comment 当前评论（用于判断"这条链接访客看不看得到"）
 * @return string
 */
function riven_link_guard_scrub( $html, $allow, $comment = null ) {
	if ( '' === (string) $html || false === strpos( $html, '<a' ) ) {
		return $html;
	}
	// 站长豁免的处理在 riven_link_guard_verdict() 里：它排在最前面直接放行，
	// 所以站长看到的永远是**全部**链接（视野不少于访客），差异只靠下面
	// riven_link_guard_admin_mark() 的彩色虚线表达。
	// （旧注释写的"风险与死链任何视角都降级"是 1.5.6 之前的语义，已作废 —— 2026-10-17 自查更正。）

	$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$home_host = $home_host ? $home_host : '';

	$out = preg_replace_callback(
		'/<a\b([^>]*)>(.*?)<\/a>/is',
		function ( $m ) use ( $allow, $home_host, $comment ) {
			$attrs = $m[1];
			$inner = $m[2];

			if ( ! preg_match( '/\bhref\s*=\s*["\']([^"\']*)["\']/i', $attrs, $hm ) ) {
				return $m[0];
			}
			$href = trim( html_entity_decode( $hm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			// 锚点、空链接、相对路径都不算外链
			if ( '' === $href || '#' === $href[0] ) {
				return $m[0];
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || ( '' !== $home_host && riven_link_guard_same_host( $host, $home_host ) ) ) {
				return $m[0];
			}

			// 判定唯一入口（1.10.0）：allow_url / scrub / 留言排行榜全部走 verdict，
			// 不再各写一遍 —— 以前改一处漏一处，出现"正文降级了、昵称还挂着"。
			$bypass = riven_link_guard_bypass_now();
			$v      = riven_link_guard_verdict( $comment, $href );
			$link_allow = ! empty( $v['allow'] );
			$mute_kind  = ! empty( $v['kind'] ) ? $v['kind'] : 'stale';

			if ( ! $link_allow ) {
				// 降级：整段 a 标签拿掉，只留文字。渲染后的 HTML 里不会再出现这个域名。
				// 外面套一层带 title 的 span，鼠标移上去说明为什么点不了（仍然没有 href）。
				return riven_link_guard_muted( $inner, $mute_kind );
			}
			$final = riven_link_guard_add_rel( $attrs );
			// 站长视角标记（2026-10-16 老张要求）：这条链接访客那边看不到，
			// 用不同颜色的虚线 + 提示区分原因，让站长一眼分清。
			if ( $bypass && $comment ) {
				$kind = riven_link_guard_admin_only_kind( $comment, $href );
				if ( '' !== $kind ) {
					$final = riven_link_guard_admin_mark( $final, $kind );
				}
			}
			return '<a' . $final . '>' . $inner . '</a>';
		},
		$html
	);

	return ( null === $out ) ? $html : $out;
}

/* ---------------------------------------------------------------- 挂载 */

// 有新评论、或评论被审核/驳回/删除，域名新鲜度与"域名->人"两张表都不准了，作废重算
foreach ( array( 'wp_insert_comment', 'transition_comment_status', 'deleted_comment', 'trashed_comment' ) as $_h ) {
	add_action(
		$_h,
		function () {
			delete_transient( 'riven_link_guard_hostmap' );
			delete_transient( 'riven_link_guard_hostowner' );
		}
	);
}

// 评论列表取出来时先批量把活跃度查好（一次 SQL，不是每条一次）
//
// 2026-10-17 自查：原来站长登录时（bypass=true）会跳过这里，但站长视角下每条链接
// 仍要按"访客视角"算一遍才能打标记（riven_link_guard_admin_only_kind）——
// 结果每个不同邮箱各触发一次 SQL。改成一律预查，站长页面反而更省。
add_filter(
	'comments_array',
	function ( $comments ) {
		if ( riven_link_guard_on() ) {
			riven_link_guard_prime( $comments );
		}
		return $comments;
	},
	10,
	1
);

/**
 * 评论正文里的外链
 * 挂两个钩子是因为主题模板用的是 get_comment_text()，而侧栏小工具/feed 走 comment_text()；
 * 这个处理是幂等的（重复跑不会叠加 rel，也不会二次删除），所以两个都挂没有副作用。
 */
function riven_link_guard_text( $content, $comment ) {
	if ( ! riven_link_guard_on() || ! $comment ) {
		return $content;
	}
	// 2026-10-17 自查：以前这里先 riven_link_guard_allow() 算一遍再传进 scrub，
	// 而 scrub 从 1.10.0 起改成逐条 verdict，这个参数根本没被读取 —— 纯浪费一次查询。
	return riven_link_guard_scrub( $content, true, $comment );
}
add_filter( 'get_comment_text', 'riven_link_guard_text', 25, 2 );
add_filter( 'comment_text', 'riven_link_guard_text', 25, 2 );

/* ------------------------------------------------------------ 前台样式（1.5.4） */

/**
 * 降级链接的视觉提示
 *
 * 为什么插件必须自带这段 CSS：这几个类原来只写在 riven-wp 主题的 main.css 里。
 * 换到别人的站、别人的主题，链接确实变成纯文本了，但看起来跟周围文字一模一样 ——
 * 没有任何"这里原本是个链接"的提示，访客不知道、站长也会以为插件没生效。
 * 发布版不能指望用户的主题里有这套样式。
 *
 * 想用主题自己的样式：`add_filter( 'riven_link_guard_print_css', '__return_false' );`
 */
function riven_link_guard_print_css() {
	if ( ! riven_link_guard_on() ) {
		return;
	}
	if ( ! apply_filters( 'riven_link_guard_print_css', true ) ) {
		return;
	}
	?>
	<style id="riven-link-guard-css">
	.riven-link-muted{cursor:help;border-bottom:1px dashed currentColor;opacity:.8}
	a.riven-link-admin-only{border-bottom:1px dashed #e08a3c;cursor:help}
	a.riven-link-admin-risk{border-bottom-color:#d63638}
	a.riven-link-admin-dead,a.riven-link-admin-unreachable{border-bottom-color:#9aa0a6}
	a.riven-link-admin-archived{border-bottom-color:#c9a227}
	</style>
	<?php
}
add_action( 'wp_head', 'riven_link_guard_print_css', 99 );
