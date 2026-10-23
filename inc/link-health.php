<?php
/**
 * 外链体检与持续监控（link health）
 *
 * 为什么要这个东西：
 *   评论外链分级（inc/comment-link-guard.php）解决的是"我认不认这个人 / 这个域名"，
 *   但它永远看不见"那个域名现在是什么"。一个老博友可能一直正常往来，域名却已经
 *   被卖掉挂了博彩 —— 光靠关系判定发现不了。
 *
 *   所以补一层"体检"：主动抓对方首页，看它现在是什么东西。
 *
 * 核心原则（2026-10-16 与老张逐轮讨论定下）：
 *   ① 体检不是"重新判一次"，而是**对关系判定的默认结果纠偏**：
 *        活跃域名 → 默认放行，体检查到风险才拉下来；
 *        沉寂域名 → 默认降级，体检确认"健康 + 是博客 + 近一年还在更新"才抬上去。
 *   ② 抓不到（DNS 死 / 403 / 超时）一律**不改变默认结果**：看不清就不给通行。
 *   ③ 站点性质是必需的一关 —— 域名转手成了企业站、导航站、停靠页，
 *      内容再健康也不该顶着老博友的名字留在前台。
 *   ④ 除了"换域名"，其余动作**一律只改输出、不碰数据库**。
 *
 * 持续监控（三路触发）：
 *   · 新评论入场 → 新域名进队列，下一批就检；
 *   · 周期轮转 → 每域名记 checked_at，每批取"最久没检的"优先，全站轮着过；
 *   · 拉黑生效 → 命中黑名单的域名直接 100 分，且前台提交时拦截。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 守卫模块没落地时，体检整体安静退出。
// 教训（2026-10-14）：任何可选模块缺失都不能让整站白屏 —— 这里直接不加载，
// 让它连"依赖未满足"的错误都没有机会发生。
if ( ! function_exists( 'riven_link_guard_host_key' ) ) {
	return;
}

/* ------------------------------------------------------------------ 开关 */

/** 写一个体检相关的设置项（对应 rcg_opt 的读） */
function riven_health_set( $key, $value ) {
	rcg_set( $key, $value );
	return $value;
}

function riven_health_on() {
	return rcg_opt( 'health_enable', 'on' ) !== 'off';
}

/** 体检结果要不要参与守卫判定（关掉就退回纯关系判定） */
function riven_health_verdict_on() {
	return riven_health_on() && rcg_opt( 'health_verdict_enable', 'on' ) !== 'off';
}

/** 沉寂域名体检过关后是否自动恢复可点击 */
function riven_health_auto_restore_on() {
	return riven_health_verdict_on() && rcg_opt( 'health_restore_enable', 'on' ) !== 'off';
}

/** 命中黑名单的域名，前台提交时是否直接拒绝 */
function riven_health_block_on() {
	return riven_health_on() && rcg_opt( 'health_block_enable', 'on' ) !== 'off';
}

/** "还在更新"的窗口（月），默认 12 */
function riven_health_fresh_months() {
	$m = absint( rcg_opt( 'health_fresh_months', 12 ) );
	return $m >= 1 && $m <= 60 ? $m : 12;
}

/** 风险阈值，默认 50 */
function riven_health_threshold() {
	$t = absint( rcg_opt( 'health_threshold', 50 ) );
	return $t >= 10 && $t <= 100 ? $t : 50;
}

/** 抓取超时（秒），默认 12 —— 比插件那套的 8 秒宽一些，能多捞回一些慢站 */
function riven_health_timeout() {
	$t = absint( rcg_opt( 'health_timeout', 12 ) );
	return $t >= 5 && $t <= 30 ? $t : 12;
}

/**
 * 每批扫描多少个。
 *
 * 2026-10-17（1.1.8）：改回**用户可调 5~60**（默认 15）。
 *
 * 之前 1.1.6 之所以把上限焊死在 15，是因为一批 60 个会撞 php-fpm 的 100 秒
 * 时限被杀、浏览器白屏。现在不是靠"调小"来避免，而是靠**单请求时间预算**：
 * scan_batch 给整批请求 62 秒的墙钟预算，取不完的域名不写库、留在队首，
 * 下一批接着来（`riven_health_fetch_many()` 的 deadline 参数）。
 * 所以批大小只影响"一次想推进多少"，不再影响"会不会超时被杀"。
 */
function riven_health_batch() {
	$n = absint( rcg_opt( 'health_batch', 15 ) );
	if ( $n < 5 ) {
		$n = 5;
	}
	return min( $n, 60 );
}

/**
 * 单批请求的墙钟预算（秒）。
 *
 * 宝塔 php-fpm 默认 request_terminate_timeout=100s，留 45 秒给 WP 启动、
 * 判定、落库和响应输出。预算用完的域名不会被写成 fail —— 它们直接跳过、
 * 不碰 checked_at，下一批仍然在队首。
 */
function riven_health_request_budget() {
	return 55;
}

/**
 * 轮转间隔（天）：同一个域名两次体检之间至少隔这么多天，默认 7
 *
 * 2026-10-16 加。之前没有这个门槛，队列是"扫完一轮从头再来"——配上自动调度后
 * 会变成对每个域名每天抓一次，既不礼貌也没必要。有了 TTL，队列里只会出现
 * "还没检过的"和"超过 N 天没检的"，其余空跑。
 */
function riven_health_ttl_days() {
	$d = absint( rcg_opt( 'health_ttl_days', 7 ) );
	return ( $d >= 1 && $d <= 90 ) ? $d : 7;
}

/** 自动轮转（定时后台自动扫一批）是否开启 */
function riven_health_auto_on() {
	return riven_health_on() && rcg_opt( 'health_auto_enable', 'on' ) !== 'off';
}

/** 用站点本地时间算 TTL 截止点（checked_at 是 current_time('mysql') 存进去的，两者同源） */
function riven_health_ttl_cut() {
	return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - riven_health_ttl_days() * DAY_IN_SECONDS );
}

/**
 * 活跃域名的复查间隔（天），默认 30
 *
 * 2026-10-17（1.10.0）加。免考核期内的域名（近一年还在往来的）不再参与
 * 「非个人站」考核，但**不能连风险都不看** —— 最容易出事的恰恰是"人还常来、
 * 域名却被抢注"这种。所以给它们一个很长的复查间隔（30 天），
 * 只用来抓 risk / 待售 / 死链，抓到也不拿"不是博客"去卡他。
 *
 * 返回 0 = 完全不复查（省到极致，但风险也看不见）。
 */
function riven_health_active_ttl_days() {
	$raw = rcg_opt( 'health_active_ttl_days', 30 );
	if ( 0 === (int) $raw && '30' !== (string) $raw ) {
		return 0; // 显式设成 0 = 不复查（默认值 30 走下面的分支）
	}
	$d = absint( $raw );
	return ( $d >= 1 && $d <= 365 ) ? $d : 30;
}

function riven_health_active_ttl_cut() {
	return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - riven_health_active_ttl_days() * DAY_IN_SECONDS );
}

/**
 * 「人还在、域名大概已经不是他的」的复查间隔（天），默认 7
 *
 * 2026-10-17（1.11.0，老张拍板的 C 方案）加。
 *
 * 这类域名的特征是**两维劈叉**：人这一维很新（近一年还来留言），域名这一维很旧
 * （至少一年没人把它填成自己的网址）。典型就是 qinfei.info —— 博友早换了新域名，
 * 旧域名十五年没人用，人却一直活跃。
 *
 * 为什么不能按"人新"一刀切给 30 天低频复查：这种域名最可能的结局就是被抢注，
 * 而"抢注 + 挂上黄站"恰恰是最该在最短时间内抓到的事。所以单独压到 7 天。
 *
 * 注意这里只调**抓取频率**，不动判定：抓到 risk/junk/dead 一样降级（那几条
 * 在判定链最前面，跟关系无关）；抓到健康结论也仍然放行（人还在，不因"域名旧"降级）。
 *
 * 返回 0 = 不启用这项加速（退回普通的活跃域名复查间隔）。
 */
function riven_health_urgent_ttl_days() {
	$raw = rcg_opt( 'health_urgent_ttl_days', 7 );
	// 输入框被清空 → 当成没设过，用默认 7（别让"手滑清空"把这项悄悄关掉）
	if ( '' === trim( (string) $raw ) ) {
		return 7;
	}
	// 显式填 0 = 关闭加速
	if ( 0 === (int) $raw && '7' !== (string) $raw ) {
		return 0;
	}
	// 这里不能用 absint：absint(-5) 会把负号抹掉变成 5 天，
	// 而负数本该按"填错了"回退默认（2026-10-17 实测发现）。
	$d = (int) $raw;
	return ( $d >= 1 && $d <= 30 ) ? $d : 7;
}

function riven_health_urgent_ttl_cut() {
	return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - riven_health_urgent_ttl_days() * DAY_IN_SECONDS );
}

/**
 * 这个域名该用哪个 TTL 截止点来判"到期没"
 *
 * 老张 2026-10-17 的「时间闸门」：体检预算只花在夹在中间的那批域名上。
 *
 *   ≤ 免考核期（默认 1 年）      → 低频复查（默认 30 天），只抓风险/死链
 *   免考核期 ~ 人情味期（1~5 年）→ 正常轮转（默认 7 天），走完整考核
 *   > 人情味期（默认 5 年）      → 不检了（前台已经按"归档"降级）
 *
 * @param string $host
 * @return string|null 截止点；null = 这个域名不用检
 */
function riven_health_cut_for_host( $host ) {
	$host = riven_link_guard_host_key( $host );
	if ( '' === $host ) {
		return riven_health_ttl_cut();
	}

	$mercy = function_exists( 'riven_link_guard_mercy_days' ) ? riven_link_guard_mercy_days() : 0;
	$grace = function_exists( 'riven_link_guard_days' ) ? riven_link_guard_days() : 0;

	// 关掉"人情味期"或"外链分级"时，这一层不生效（退回全量轮转）
	if ( $mercy <= 0 || ! function_exists( 'riven_link_guard_host_map' ) ) {
		return riven_health_ttl_cut();
	}

	// 时间闸门的"最后一次往来"必须与判定层同口径：**人、站两维取较新者**。
	//
	// 2026-10-17 自查修正：原来这里只读 host_map（域名最后一次被填成作者网址的时间）。
	// 博友换了新域名、旧域名没人再填、但人还在新域名下留言时，旧域名会被判成
	// "多年没往来"→ 直接归档、连体检都不做；而他本人其实还活跃，旧域名若被抢注
	// 成黄站就永远发现不了（正是老张 10-14 提的那个漏洞）。
	// host_owner_map 补上"用过这个域名的访客，最后一次评论是什么时候"。
	$hts = 0;
	$map = riven_link_guard_host_map();
	if ( isset( $map[ $host ] ) ) {
		$hts = (int) $map[ $host ];
	}

	$pts = 0;
	if ( function_exists( 'riven_link_guard_host_owner_map' ) ) {
		$omap = riven_link_guard_host_owner_map();
		if ( isset( $omap[ $host ] ) ) {
			$pts = (int) $omap[ $host ];
		}
	}

	// 关闭「域名新鲜度」时，域名这一维整体退出判定，只剩"人"这一维。
	// 否则站长关掉开关后，归档仍然按域名时间算，等于开关只关了一半。
	$host_on = function_exists( 'riven_link_guard_host_on' ) ? riven_link_guard_host_on() : true;
	$last    = $host_on ? max( $hts, $pts ) : $pts;

	if ( ! $last ) {
		// 从没被任何已审访客填成作者网址（只在待审评论里出现）→ 无从判断，正常轮转
		return riven_health_ttl_cut();
	}

	$now       = time();
	$age       = $now - $last;
	$grace_sec = $grace * DAY_IN_SECONDS;

	// ===== C 方案（2026-10-17，1.11.0 老张拍板）=====
	// 人这一维很新（近一年还来留言）、域名这一维很旧（至少一年没人把它当自己网址填过）
	// —— 大概率是博友换了域名，旧域名已经跟他没关系了，随时可能被抢注。
	//
	// 这类域名原来落在下面的 grace 分支里，跟着"活跃域名"一起 30 天才复查一次，
	// 等于把最危险的一批放到最慢的通道上。现在单独压到紧急间隔（默认 7 天）。
	//
	// 必须放在 grace 分支**之前**：人新的时候 $age 取的就是人的时间，
	// 一定会落进那个分支，晚了就永远进不来。
	if ( $host_on && $pts > 0 && ( $now - $pts ) <= $grace_sec && $hts > 0 && ( $now - $hts ) > $grace_sec ) {
		$ut = riven_health_urgent_ttl_days();
		if ( $ut > 0 ) {
			return riven_health_urgent_ttl_cut();
		}
	}

	if ( $age > $mercy * DAY_IN_SECONDS ) {
		return null; // 过了人情味期：前台已归档，不必再抓
	}
	if ( $age <= $grace_sec ) {
		$at = riven_health_active_ttl_days();
		return $at > 0 ? riven_health_active_ttl_cut() : null; // 免考核期内：低频只盯风险
	}
	return riven_health_ttl_cut();
}

/* ------------------------------------------------------------------ 数据表 */

function riven_health_table() {
	global $wpdb;
	return $wpdb->prefix . 'riven_link_health';
}

/** 表在不在（不存在时整个体检功能安静地失效，绝不 fatal） */
function riven_health_table_exists() {
	static $exists = null;
	if ( null !== $exists ) {
		return $exists;
	}
	// 先用 option 短路，避免每次前台请求都跑一次 SHOW TABLES
	if ( '1' === get_option( 'riven_health_db_ready' ) ) {
		$exists = true;
		return $exists;
	}
	global $wpdb;
	$t      = riven_health_table();
	$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
	$exists = ( $found === $t );
	if ( $exists ) {
		update_option( 'riven_health_db_ready', '1', false );
	}
	return $exists;
}

/**
 * 建表。整站升级后由后台面板或 wp_loaded 兜底调用一次，幂等。
 */
function riven_health_install() {
	global $wpdb;
	$t       = riven_health_table();
	$charset = $wpdb->get_charset_collate();

	// 用 IF NOT EXISTS 直接建，不依赖 wp-admin/includes/upgrade.php（前台也能安全跑）
	$sql = "CREATE TABLE IF NOT EXISTS {$t} (
		host varchar(191) NOT NULL,
		status varchar(16) NOT NULL DEFAULT 'unknown',
		score smallint NOT NULL DEFAULT 0,
		is_blog tinyint(1) NOT NULL DEFAULT 0,
		signals varchar(64) NOT NULL DEFAULT '',
		updated_at datetime NULL,
		checked_at datetime NOT NULL,
		hits text NULL,
		title varchar(255) NOT NULL DEFAULT '',
		fail_reason varchar(64) NOT NULL DEFAULT '',
		PRIMARY KEY  (host),
		KEY status_idx (status),
		KEY checked_idx (checked_at)
	) {$charset};";

	$wpdb->query( $sql );
	update_option( 'riven_health_db_version', RCG_VERSION, false );
}

/** 后台/前台兜底：表不在就建（只在版本变化时尝试，避免每次都查） */
function riven_health_maybe_install() {
	if ( get_option( 'riven_health_db_version' ) === RCG_VERSION ) {
		return;
	}
	if ( ! function_exists( 'rcg_opt' ) ) {
		return;
	}
	riven_health_install();
}

/* ------------------------------------------------------------------ 词库 */

/**
 * 风险词库（后台可编辑，存 option；这里只给默认值）
 *
 * 与插件那份的区别：补了 2026-10 实测漏掉的平台名（美高梅 / 秋霞电影 / 麻豆传媒 等），
 * 高歧义词（银河、快三、赛车）一律不收 —— 宁可漏，不可冤。
 *
 * @return array cat => words[]
 */
function riven_health_default_lexicon() {
	return array(
		'gamble'  => array(
			'博彩', '开户', '返水', '棋牌', '真人娱乐', '六合彩', '时时彩', '百家乐',
			'老虎机', '下注', '投注', '赔率', '私彩', '皇冠体育', '沙巴体育', '外围',
			'黑彩', '杀猪盘', '出金入金', '代理加盟', 'AG真人', 'PG电子', '电竞竞猜',
			'彩票代购', '美高梅', '新葡京', '威尼斯人', '澳门银河', '乐天堂', '亚洲城',
			'幸运飞艇', 'pk10', '百家乐代理', '棋牌室', '真人视讯', '出款',
			'体育直播', '娱乐城', '线上娱乐', '博彩公司', '体育投注', '电子游艺',
		),
		'adult'   => array(
			'裸聊', '约炮', '成人', '色情', '大秀', '一对一视频', '深夜福利', '同城约',
			'黄色', '情色', '裸体', '裸贷', 'AV资源', '色情直播', '秋霞电影', '麻豆传媒',
			'蜜桃视频', '天美传媒', '星空传媒', '国产精品', '无码', '成人视频', '成人影片',
			'看片', '电影网', '成人片', '激情视频', '大尺度', '亚洲欧美',
		),
		'fortune' => array(
			'算命', '八字', '风水', '起名', '开光', '法事', '转运', '改运', '看相',
			'占卜', '塔罗', '灵符', '化解', '超度', '童子命', '破财消灾', '招财',
			'辟邪', '改命', '命理', '佛牌', '免费算命', '生辰八字', '姓名打分',
			'风水大师', '算命网',
		),
		'scam'    => array(
			'刷单', '兼职', '中奖', '解冻', '保证金', '钓鱼', '假冒客服', '征信修复',
			'无抵押贷款', '秒批', '冒充客服', '客服退款', '稳赚不赔', '内部名额',
			'高收益', '股票配资', '荐股', '代操盘', '日赚', '月入过万',
		),
		// 高置信词（2026-10-16 加）：命中一个就算 30 分
		//
		// 为什么需要这一类：实测发现这批色情/赌博站的标题往往只命中 1 个普通词（10 分），
		// 离阈值 50 分差得远，于是一路放行 —— 例如 zikaotisheng.com 的标题
		// 「传媒在线观看免费入口-传煤黄色影院-大香蕉91三级片」, 3 个确凿特征只算 30 分。
		//
		// 入选标准：**组合词或平台名，正常内容几乎不可能出现**。
		// 单个词（如"成人"、"看片"）绝不放进这里 —— 那是为了不误伤。
		// 词表全部来自 w 站实测（对 708 个已抓到的标题逐个验证过命中对象）。
		'strong'  => array(
			'黄色影院', '大香蕉', '三级片', '看片神器', '免费看片', '久久久久', '久久精品',
			'99久久', '一区二区', '精品熟女', '熟女专区', '国产熟女', '看片狂人',
			'污片', '黄片', '色虎', '日本av', 'av在线', '偷拍自拍',
			'555win', '糖心vlog', '糖心视频', '旗舰厅', '开云在线', 'pg模拟器',
			'KY开元', '永利集团', '925体育', '太阳城娱乐', '银河娱乐城', 'mgm1888', '美高梅mgm',
			// 2026-10-17 老张报的两个漏网赌站（xuefuzi.com 加拿大28、92qq.net 亚搏）
			// 带出来的：这些平台的"玩法名/平台名"以前词库里没有，0 分就放行了。
			'加拿大28', '加拿大预测', 'pc28', '北京赛车', '极速赛车', '幸运农场',
			'亚搏', 'yabo', '趣投', '真人荷官', '澳门赌场', '新澳门', '博彩论坛',
			'配资平台', '股指期货', '黄金外汇',
			// 2026-10-17 补：修"裸域 404、www 正常"时翻出来的两个赌站 ——
			// 18yqm.com「欧博游戏官网_www.aabbgg77.net」、shamas.me「AO88 … Trang Chủ」。
			// 它们原来靠"死链"降级，理由错但结果对；抓取修好后裸奔成 ok，
			// 说明词库对"境外博彩品牌名 + 越南语"这一层是空的。
			'欧博', 'aabbgg', 'ao88', 'nhà cái', 'nha cai',
			// 2026-10-17（1.3.0）老张报 58yqm.com 时顺带扫出来的博彩品牌：
			// 它们的标题往往只有"品牌名 + 网址"，一个 strong 词就够 50 分。
			// 实测对象：kaygb.top「皇冠网址_皇冠体育-新2网址」、
			// sapapo22.com「long8|龙8|龙八(头号玩家)-唯一官方网站」、
			// lumengde.com「开云中国 - …文化传媒」。
			'皇冠网址', '新2网址', '新2娱乐', 'long8', '龙8', '龙八',
			'开云中国', '开云体育', '沙龙365', '太阳城娱乐', '银河娱乐',
			// 2026-10-17（1.3.1）自查发现：yiws.net 的标题
			// 「皇冠足彩:合法足球彩票的理性投注与风险防范指南」只在正文命中一个"投注"，
			// 10 分放行 —— 而它正文里写着"真人,棋/牌 体育,彩/票 电子●贵宾官方旗舰站"。
			// "皇冠足彩"是品牌+玩法组合词，个人博客不会写进标题。
			'皇冠足彩',
			// 2026-10-18（1.3.4）放宽"缺证据就定罪"时补的三个：
			// blogzsh.com「在线娱乐」、goufanr.com「yh0612cc银河·(中国区) - 首页」、
			// dolingou.com「ODDIGO - Platform Akses Bermain Link Situs Slot」。
			// 它们原来靠"没有任何博客特征"被降级，判据废除后必须由词库接住 ——
			// 标题里带着这些词就是赌站在自我介绍，和个人站标题是两回事。
			'在线娱乐', 'yh0612cc', 'ODDIGO',
			// 2026-10-17（1.3.2）按"标题为准"重查时揪出来的：
			// qijiwiki.com 标题「英国·威廉希尔(williamhill)体育公司-中文官网」——
			// 这是境外博彩公司，词库缺品牌名，原来 0 分放行。它标题不含"官网"以外的
			// 商业句式（weak=1，没到 3 个），所以连"已不是个人站"都没碰到。
			'威廉希尔', 'williamhill',
			// 2026-10-18（1.3.5）复查「暂无指纹·待观察」时揪出的赌站：
			// mp4wang.cc 标题「K8凯发·一触即发」—— 凯发是境外博彩品牌，
			// 标题一个人名/意境词都不带，原来 0 分放行。
			'凯发', 'K8凯发',
		),
		// 废弃域名（2026-10-16 从 strong 里分出来）：
		// 命中这些词说明这个域名已经变成"域名待售/停靠页"了 —— 不是黄赌，
		// 但也早就不是原来那个博客。实测 w 站上有一批（zhangbo.org、ludou.org、
		// taizihong.com…），前台一律显示为域名商模板页。
		// 单独一类是为了后台能把"黄赌风险"和"域名废弃"分开列 —— 语义不同，
		// 老张看清单时不会把"自己的旧域名"误当成黄赌站。
		//
		// 2026-10-17（1.3.1）：词表与 riven_health_parked_words() 合并 ——
		// 原来是手写两处，加了新词只改一处的话，重扫又会把它们判回 ok（itlu.org 就是这么漏的）。
		'junk'    => array_values(
			array_unique(
				array_merge(
					array( '域名出售', '正在出售', 'GetDomain', 'for sale', '域名转让', '域名购买' ),
					riven_health_parked_words()
				)
			)
		),
	);
}

/**
 * 「站点性质词」—— 判断这个域名现在还是不是一个个人站（1.3.0）
 *
 * 与风险词库**分开存放**，两个原因：
 *   ① 它们不参与风险打分 —— 博客里写"我在交易所上班"不该被扣分；
 *   ② 只在**标题**里统计 —— 正文出现"官网/下载/登录"太正常了。
 *
 * 判据不是"它坏"，而是"它已经不是个人站了"。对应老张 2026-10-17 报的 58yqm.com：
 * 标题「ZB中币网 | 中币网官网入口_中币区块链_交易所_交易平台」，
 * 老评论是 2025-09 的"车牌吉凶"，原文章 /post/46.html 早已 404 —— 域名被买走改成了交易所。
 * 用风险词打分它只有 20 分（阈值 50），永远放行；但它**一眼就不是个人站**。
 *
 * 分两档，是因为这些词的出现概率差别很大：
 *   · strong：正常个人博客标题几乎不可能出现（交易所 / 官网入口 / 直播网…）
 *   · weak  ：单独出现很正常（官网 / 平台 / 下载…），要凑够数才算
 *
 * @return array strong / weak
 */
function riven_health_platform_terms() {
	return array(
		'strong' => array(
			'交易所', '交易平台', '官网入口', '唯一官方网站', '官方网站', '官方网址',
			'数字货币', '虚拟货币', '虚拟币', 'USDT', '合约交易', '永续合约',
			'资金盘', '配资平台', '娱乐城', '博彩', '走势图', '开奖',
			'直播网', '无插件', '首码', '项目网', '软件库', '资源站',
			'导航网', '网址导航', '在线观看', '免费观看', 'APP下载', '官方下载',
		),
		'weak'   => array(
			'官网', '网址', '平台', '首页', '下载', '注册', '登录', '充值', '提现',
			'客服', '招商', '代理', '赚钱', '高清直播', '在线直播', '免费在线',
			'入口', '最新地址', '备用网址', '币圈', '挖矿', '空投', '代币', '区块链',
			'开户', '返佣',
		),
	);
}

/**
 * 标题里命中多少「站点性质词」
 *
 * 去重规则：**长词优先、被覆盖的位置不重复计数**。
 * 否则「唯一官方网站」会顺带把「官方网站」也算一次 —— 一个词虚报成两个，
 * 正好够到判定线，那就变成新的误伤源了。
 *
 * @param string $title
 * @return array strong / weak / hits
 */
function riven_health_title_platform( $title ) {
	$title = trim( (string) $title );
	$out   = array( 'strong' => 0, 'weak' => 0, 'hits' => array() );
	if ( '' === $title ) {
		return $out;
	}

	$terms = riven_health_platform_terms();
	$tlen  = mb_strlen( $title );
	$taken = array(); // 已被占用的字符区间 array( start, end )

	foreach ( array( 'strong', 'weak' ) as $cat ) {
		$words = $terms[ $cat ];
		// 长词优先：「唯一官方网站」先占位，短词就吃不到同一段文字了
		usort(
			$words,
			function ( $a, $b ) {
				return mb_strlen( (string) $b ) - mb_strlen( (string) $a );
			}
		);
		foreach ( $words as $w ) {
			$w = (string) $w;
			if ( '' === $w ) {
				continue;
			}
			$wlen    = mb_strlen( $w );
			$matched = 0;
			$off     = 0;
			while ( $off < $tlen ) {
				$pos = mb_stripos( $title, $w, $off );
				if ( false === $pos ) {
					break;
				}
				$end     = $pos + $wlen;
				$covered = false;
				foreach ( $taken as $seg ) {
					if ( $pos >= $seg[0] && $end <= $seg[1] ) {
						$covered = true;
						break;
					}
				}
				if ( ! $covered ) {
					$matched++;
					$taken[] = array( $pos, $end );
				}
				$off = $pos + 1;
			}
			if ( $matched > 0 ) {
				$out[ $cat ] += $matched;
				$out['hits'][] = $w;
			}
		}
	}
	return $out;
}

/**
 * 标题是不是「商业站点句式」—— 是则说明它已经不是个人站
 *
 * 判定线（2026-10-17 1.3.1 收紧，用全库实测重标）：
 *   · strong ≥ 1                        —— 交易所 / 官方网站 / 直播网 / 无插件…
 *   · weak  ≥ 3                         —— 官网 + 下载 + 客服
 *
 * 为什么从「strong ≥ 2」降到「strong ≥ 1」：
 *   拿到真实数据一算，全库 651 个 ok 记录里，**strong 只命中 1 个却没被改判的只有 6 个**，
 *   而这 6 个全是商业站：
 *     boayuan.com     伟德国际(bevictor)官方网站
 *     dianzubuluo.com 8090影院-在线视频网站-1080P电影在线观看
 *     japanren.com    足球比分直播_竞彩足球比分直播_足彩即时比分直播网-500彩票网
 *     mubwx.com       飞速直播 - nba免费播放观看_飞速直播nba无插件网
 *     sapapo22.com    long8|龙8|龙八(头号玩家)-唯一官方网站
 *     ligonggong.com  软件库-分享互联网优质资源！（资源站，is_blog 原本给 1）
 *   而 428 个「算作博客」的站点里，只有 ligonggong.com 这一个会被这条改动碰到，
 *   真博客（小徐的流水时光、水拍石…）一个都不含这些词 —— 收紧是安全的。
 *
 * 之前留「strong ≥ 2」是怕误伤，结果恰恰是这条线让 58yqm 的邻居们继续漏网：
 * 一个 strong 词就足以说明这个标题不是人写的博客标题了。
 *
 * @param string $title
 * @param string $why   回传命中的词，便于后台显示"为什么判它"
 * @return bool
 */
function riven_health_title_is_commercial( $title, &$why = null ) {
	$p   = riven_health_title_platform( $title );
	$hit = ( $p['strong'] >= 1 )
		|| ( $p['weak'] >= 3 );
	$why = $hit ? implode( '·', array_slice( $p['hits'], 0, 5 ) ) : '';
	return $hit;
}

/**
 * 停靠页 / 待售页 / 未配置页的模板特征（2026-10-17 1.3.1）
 *
 * 为什么单独一份：这些是**客观事实**——页面标题就是域名商的模板文案，
 * 不存在"我看不清"的问题，也不需要等重扫。前台判定时直接读库里的标题就能用。
 *
 * 实测来源（w 站真实漏网）：
 *   itlu.org          「itlu.org 正在转让出售中 - itlu_域名交易_售卖_卖家信息 - 免费域名停靠 Domain Parking」
 *   pingdingshan.com  「FUMI.com」正文「精选域名 -- 您正在访问的域名可以转让…提交报价」
 *   stylefanr.org     「Registrant WHOIS contact information verification | Namecheap.com」
 *   eirms.com         「Coming Soon」
 * 这几个原来都判 ok：junk 词表里只有「域名出售/正在出售/域名转让」，一个都对不上。
 *
 * @return array
 */
function riven_health_parked_words() {
	return array(
		// 域名交易 / 停靠商模板
		// ⚠️ 刻意**不收**「域名交易」四个字：dalao.net / qq.md 的标题是
		// 「大佬论坛 - 全球域名论坛、域名交易与投资交流社区」—— 域名论坛本身就在聊这个，
		// 收了它等于把博客圈的友站判成停靠页（1.3.1 实测踩到，32 条评论一度被误降）。
		// 停靠页的真实特征一定是"出售/转让/停放"这类动作词，不是行业名词。
		'域名出售', '正在出售', '出售中', '域名转让', '域名停靠',
		'精选域名', '域名可以转让', '提交报价', '域名购买', '域名求购',
		// 1.3.3 补：hegu.cn / hgwd.cn / jfss.4596.cn 的标题就是「域名售卖」
		'域名售卖',
		'domain parking', 'this domain is for sale', 'domain is for sale',
		'buy this domain', 'for sale', 'parked domain',
		// 未配置 / 未备案 / 建设中
		'registrant whois contact information', 'coming soon', 'site not configured',
		'welcome to nginx', 'apache2 default page', 'iis windows server',
		'网站建设中', '站点未配置', '默认站点',
	);
}

/**
 * 标题是不是域名停靠商/未配置页的模板
 *
 * 保守约束：标题里带"博客/个人/日志…"的一律不算 —— 宁可漏，不可冤。
 *
 * @param string $title
 * @return bool
 */
function riven_health_title_is_parked( $title ) {
	$title = trim( (string) $title );
	if ( '' === $title ) {
		return false;
	}
	if ( riven_health_title_looks_personal( $title ) ) {
		return false;
	}
	foreach ( riven_health_parked_words() as $w ) {
		if ( '' !== $w && mb_stripos( $title, $w ) !== false ) {
			return true;
		}
	}
	return false;
}

/**
 * 空壳页的标题是不是"域名商 / 未配置 / 错误页"模板风格（2026-10-17 1.3.3）
 *
 * 为什么需要它：原来只要"正文 <30 字 + 整页 <6000 + 无 SPA 特征"就判 junk（域名废弃），
 * 结果把一批**正常的 JS 博客**也判成了待售页 —— 老张报的误判：
 *   airy.ink        标题「瓦匠不舟」 1130 字节、内容全靠 JS 渲染
 *   hahaha.cc       标题「好事发生」 3293 字节，还 301 跳到 hahaha.me
 *   blog.rain888.cn 「@加载中。。。。」 / 029shouji.com「@」 / 520aa.com「Loading..」
 * 这些标题是站主自己写的，域名商模板页写不出「瓦匠不舟」这种标题。
 * 现代静态博客（Astro / VitePress / Vue 主题）HTML 里就是没有正文，
 * 体量还特别小 —— 用"页面小"当废弃证据本来就不可靠。
 *
 * 判据（保守：只有"一看就不是人写的标题"才算模板）：
 *   · 标题为空，或只剩 1~2 个字符（SPA 根节点留下的"@"）
 *   · 标题含 parked_words（域名出售 / for sale / Loading / Coming Soon…）
 *   · 标题是 HTTP 错误页（404 / 502 / 403…）—— 这是"站点出问题"，更不是废弃
 *   · 标题就是域名本身（"123ping.com is for sale" 那种）
 *
 * @param string $title
 * @param string $host  域名（裸域），用于"标题就是域名本身"的比对
 * @return bool
 */
function riven_health_title_is_shell_template( $title, $host = '' ) {
	$title = trim( (string) $title );
	// ⚠️ 库里的占位符：finalize 把"没有标题的空壳页"写成 '[空壳/停靠页]'。
	// 它本身就是"无标题"的意思，**不能当成站主写的标题** ——
	// 第一版 dry-run 就在这翻了车：40 多个 [空壳/停靠页] 差点被集体恢复成可点。
	if ( '' === $title || '[空壳/停靠页]' === $title ) {
		return true;
	}
	if ( mb_strlen( $title ) <= 1 ) {
		return true;
	}
	if ( riven_health_title_is_parked( $title ) ) {
		return true;
	}
	if ( riven_health_title_is_error_page( $title ) ) {
		return true;
	}
	// 服务器默认页 / 欢迎页："Welcome to nginx!" 常被截成 "Welcome"、
	// 宝塔默认页 "欢迎使用ChineseStack"
	if ( preg_match( '#^(welcome\b|欢迎使用|欢迎来到|欢迎访问)#i', $title ) ) {
		return true;
	}
	// 标题就是域名本身（可带 " is for sale" 之类的**英文**模板尾巴）
	// ⚠️ 2026-10-18 修：原来写成 `strpos($tt, $h.' ') === 0`，结果
	// "Jeray.Wang | 热爱生活 步履不停"（个人站）也被命中 —— 域名后面跟个空格太常见了。
	// 现在要求整条标题是纯 ASCII（域名 + 英文模板词），带中文的一律不算。
	$tt = mb_strtolower( $title );
	$tt = preg_replace( '#^https?://#', '', $tt );
	$tt = rtrim( (string) preg_replace( '#^www\.#', '', $tt ), '/' );
	if ( '' !== $host ) {
		$h = mb_strtolower( trim( (string) $host ) );
		if ( $tt === $h ) {
			return true;
		}
		if ( ( 0 === strpos( $tt, $h . ' ' ) || 0 === strpos( $tt, $h . '.' ) )
			&& preg_match( '#^[\x21-\x7e\s]+$#', $tt ) ) {
			return true;
		}
	}
	return false;
}

/**
 * 标题是不是 HTTP 错误页 / 服务器默认页（404 / 502 / 403…）
 *
 * 这类页面说明的是"站点此刻出问题"（nginx 挂了、CDN 拦截、服务器 502），
 * 不是"域名被卖掉"。判成 junk 会白降级 —— 老张 2026-10-18 报的 buuke.com
 * 「404 Not Found」、dahuoguo.com「502 Bad Gateway」就是这一类。
 *
 * @param string $title
 * @return bool
 */
function riven_health_title_is_error_page( $title ) {
	$title = trim( (string) $title );
	if ( '' === $title ) {
		return false;
	}
	return (bool) preg_match(
		'#^(404|403|500|502|503|504)\b|\bnot\s*found\b|\bbad\s*gateway\b|\bservice\s+(temporarily\s+)?unavailable\b|\bforbidden\b|\binternal\s+server\b|account\s+is\s+disabled|account\s+disabled|没有找到站点|页面不存在|页面未找到|站点已暂停|网站已暂停|访问异常|系统维护|系统繁忙|账户已禁用|账号已禁用|进入工单|提交工单#i',
		$title
	);
}

/**
 * 「商业 / 机构性质词」—— 标题里出现这些，说明这是机构站不是个人站（1.3.4）
 *
 * 为什么需要它：1.3.4 把"没有任何博客特征"这条判据废掉之后，
 * 一批**标题就写着自己是商业机构**的站没了凭据，只能进「暂无指纹·待观察」靠人看 ——
 * 老张批评得对："插件该做的事又交给人工了"。
 * 他举的例子：小芒信息网、草根综合搜索、豆沙工作室、
 * 谷歌SEO优化公司｜外贸网站建设与推广、山东凯瑞化学有限公司。
 *
 * 收录标准：**个人博客不会用这些词来自称**。
 * 刻意**不收**这些（个人博客也在用）：
 *   · 商店 / 数据库 / 技术 / 分享 / AI / 测评 / 工具 / 教程 / 赚钱
 *   · 官网 —— 「流水拾音--官网」是真博客；而"www.6e6.org-官网首页"那类
 *     已经由 riven_health_title_starts_with_domain 拦住了，不需要它
 *   · 开发 —— obaby 的博客标题写着"程序媛 / 独立开发者"
 *   · 服务 —— "为人民服务"这类词个人也写；改用"服务商"
 *   · 广告 / 运营 —— 个人博客会写"广告过滤""运营成长笔记"
 *
 * 与 riven_health_platform_terms() 的分工：那份判"站点性质像不像商业平台"，
 * 这份判"标题自称是不是商业机构"。两条独立，互不替代。
 *
 * @return array
 */
function riven_health_biz_title_words() {
	return array(
		// 机构类型后缀
		'公司', '有限公司', '集团', '工作室', '信息网', '搜索', '导航', '平台',
		'网站建设', '网页制作', '网站优化', '网站推广', '网站设计', '网站开发',
		'网络营销', '全网营销', '电子商务', '跨境电商', '网络公司', '网络科技',
		'信息科技', '信息技术', '软件开发', '文化传媒', '公关', '策划', '服务商',
		'代理', '加盟', '批发', '零售', '电商', '商务', '贸易', '实业',
		// 商业服务动词（这些词个人博客标题里几乎不出现）
		'优化', '推广', '建设', '制作',
	);
}

/**
 * 「博客行为词」—— 标题里出现这些，说明站主是在记录/分享（1.3.4）
 *
 * 有了它们，"工作室""官网"这类词只是他的名义，不是机构自我介绍。
 * 实测依据：「小姚工作室_记录分享，传播正能量！」是真博客，
 * 而「豆沙工作室」（光秃秃一个机构名）不是。
 *
 * @return array
 */
function riven_health_title_blog_deed_words() {
	return array(
		'记录', '分享', '博客', '日志', '随笔', '笔记', '生活', '成长',
		'学习', '思考', '折腾', '日记', '文集', '自留地', '小站', '网志', '博客园',
	);
}

/**
 * 标题是不是"商业 / 机构站在自我介绍"（1.3.4）
 *
 * @param string $title
 * @return bool
 */
function riven_health_title_is_biz_site( $title ) {
	$title = trim( (string) $title );
	if ( '' === $title ) {
		return false;
	}
	// 标题里写着"记录/分享/博客…" → 是个人站在写作，机构词只是名义
	foreach ( riven_health_title_blog_deed_words() as $d ) {
		if ( false !== mb_stripos( $title, $d ) ) {
			return false;
		}
	}
	foreach ( riven_health_biz_title_words() as $w ) {
		if ( false !== mb_stripos( $title, $w ) ) {
			return true;
		}
	}
	return false;
}

/**
 * 标题是不是"人写的个人站标题"—— 是则**本身就是博客特征**（2026-10-18 1.3.4）
 *
 * 为什么需要它：1.9.3 的判据是"**没有任何博客特征** → 认为它已经不是个人站"。
 * 这个假设实测不成立 —— 一批真博客的首页什么机器指纹都不带：
 *   mcoo.cc       猫客印象            Z-BlogPHP 变体，无 rss link、无 wp-content
 *   lushaojun.com 随望淡思            正文写着"阿均的个人网志"，标题里却没"博客"二字
 *   laomai.org    老麦                Next.js 静态导出，cms_re 刻意不收 next/nuxt
 *   imzm.im       仿生猫梦见电子猫粮   自研前端，源码里只有 JSON 索引
 *   srsc.cc       花非花 雾非雾
 * 它们全被判 is_blog=0 → 进「已不是个人站」，而 lushaojun.com / laomai.org /
 * imzm.im 最后一条评论都超过一年，前台**真的被降级了**（老张 2026-10-18 报）。
 *
 * 个人博客的标题有两种：一种是「XX的博客」，另一种就是**网名/意境词**
 * （猫客印象、随望淡思、花非花雾非雾）。后一种在博客圈极其常见。
 * 而域名商模板页、企业站、商业站的标题必然带功能词或公司名 ——
 * 所以"标题像人写的"本身就是比 HTML 指纹更可靠的博客特征。
 *
 * 判据（全部满足）：
 *   · 长度 2~30 字
 *   · 不是域名商模板 / 错误页 / 域名本身
 *   · 不含"交易所 / 官网入口 / 直播网 / 软件库…"这类强特征词
 *   · **一个弱特征词都不含**（官网 / 首页 / 下载 / 注册 / 登录 / 平台…）。
 *     个人站标题不会带这些功能词；带上就是商业站在自我介绍。
 *     「个人博客 | 首页」这种由 title_looks_personal 先接住，不受影响。
 *   · 标题不以"www."开头、不含裸域名（"www.6e6.org-官网首页"是企业站句式）
 *
 * ⚠️ 代价（老张确认"宁可漏不可冤"）：纯业务名的行业站（消防天下、招标、
 * 旅行社、加密软件）也会被这条放过 → 前台仍可点。但它们不再是"猜它转手了"，
 * 而是"没有证据它转手了"，且仍会出现在后台「暂无指纹·待观察」清单里，
 * 站长想拉黑随时能拉黑。
 *
 * @param string $title
 * @param string $host  域名（裸域）
 * @return bool
 */
function riven_health_title_is_personal_site( $title, $host = '' ) {
	$title = trim( (string) $title );
	$len   = mb_strlen( $title );
	if ( $len < 2 || $len > 30 ) {
		return false;
	}
	// 域名商模板 / 错误页 / 域名本身 → 不是个人站
	if ( riven_health_title_is_shell_template( $title, $host ) ) {
		return false;
	}
	// 标题写着"博客 / 日志 / 网志 / 自留地…" → 铁定是个人站
	if ( riven_health_title_looks_personal( $title ) ) {
		return true;
	}
	// 商业站点句式（strong≥1 或 weak≥3）→ 不是
	if ( riven_health_title_is_commercial( $title ) ) {
		return false;
	}
	// 1.3.4：标题自称是商业机构（公司 / 工作室 / 信息网 / SEO优化公司…）→ 不是
	if ( riven_health_title_is_biz_site( $title ) ) {
		return false;
	}
	$p = riven_health_title_platform( $title );
	if ( $p['strong'] > 0 || $p['weak'] > 0 ) {
		return false;
	}
	// "Jeray.Wang | 热爱生活 步履不停" 这种"域名 + 人名口号"的个人站标题：
	// 把自己域名顶在最前面，但后面跟的是人话 → 剥掉域名再判。
	$stripped = riven_health_title_strip_domain( $title, $host );
	if ( '' !== $stripped ) {
		if ( mb_strlen( $stripped ) < 2 ) {
			return false; // 剥完就剩个符号，说明原标题就是"域名-功能词"那一路
		}
		if ( riven_health_title_is_shell_template( $stripped, $host ) ) {
			return false;
		}
		if ( riven_health_title_looks_personal( $stripped ) ) {
			return true;
		}
		$p2 = riven_health_title_platform( $stripped );
		return ( $p2['strong'] === 0 && $p2['weak'] === 0 );
	}
	// "www.6e6.org-官网首页" / "6e6.org-官网首页" / "http://xxx.com" 这类
	// 剥不掉（或剥完只剩功能词）的，是企业站/导航站的典型句式
	if ( riven_health_title_starts_with_domain( $title, $host ) ) {
		return false;
	}
	return true;
}

/**
 * 把标题开头的域名剥掉，返回剩余部分（2026-10-18 1.3.4）
 *
 * "Jeray.Wang | 热爱生活 步履不停" → "热爱生活 步履不停"
 * 剥不出来返回空串。
 *
 * @param string $title
 * @param string $host
 * @return string
 */
function riven_health_title_strip_domain( $title, $host ) {
	$t  = trim( (string) $title );
	$h  = mb_strtolower( trim( (string) $host ) );
	$lt = mb_strtolower( $t );
	if ( '' === $t || '' === $h || strlen( $h ) < 4 ) {
		return '';
	}
	foreach ( array( 'https://' . $h, 'http://' . $h, 'www.' . $h, $h ) as $pre ) {
		if ( 0 === strpos( $lt, $pre ) ) {
			$rest = mb_substr( $t, mb_strlen( $pre ) );
			$rest = ltrim( $rest, " \t\r\n-–—|·:：,，" );
			return trim( $rest );
		}
	}
	return '';
}

/**
 * 标题是不是以域名本身开头（2026-10-18 1.3.4）
 *
 * "www.6e6.org-官网首页"、"6e6.org-官网首页"、"www.95ie.com-官网首页"
 * —— 实测 w 站「已不是个人站」里有一批这种，全是企业站/导航站。
 * 个人博客不会把自己域名顶在标题最前面。
 *
 * @param string $title
 * @param string $host
 * @return bool
 */
function riven_health_title_starts_with_domain( $title, $host ) {
	$t = mb_strtolower( trim( (string) $title ) );
	$h = mb_strtolower( trim( (string) $host ) );
	if ( '' === $t || '' === $h || strlen( $h ) < 4 ) {
		return false;
	}
	if ( 0 === strpos( $t, 'www.' ) ) {
		return true;
	}
	if ( 0 === strpos( $t, $h ) ) {
		return true;
	}
	if ( 0 === strpos( $t, 'http://' . $h ) || 0 === strpos( $t, 'https://' . $h ) ) {
		return true;
	}
	return false;
}

/** 词库（默认 + 后台自定义追加 － 后台停用词） */
function riven_health_lexicon() {
	static $lex = null;
	if ( null !== $lex ) {
		return $lex;
	}
	$lex = riven_health_default_lexicon();

	$extra = (string) rcg_opt( 'health_extra_words', '' );
	if ( '' !== trim( $extra ) ) {
		foreach ( preg_split( '/[\r\n]+/', $extra ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$words = preg_split( '/[\s,，、]+/u', $line );
			foreach ( $words as $w ) {
				$w = trim( $w );
				if ( '' !== $w && ! in_array( $w, $lex['gamble'], true )
					&& ! in_array( $w, $lex['adult'], true )
					&& ! in_array( $w, $lex['fortune'], true )
					&& ! in_array( $w, $lex['scam'], true ) ) {
					$lex['scam'][] = $w; // 自定义词一律归入"诈骗"类（分类只影响展示）
				}
			}
		}
	}

	// 后台"停用词"最后剔除（2026-10-16 加）——内置词也不是每条都合用，
	// 例如"成人"会误伤成人教育/"兼职"会误伤正常招聘，得给站长一个关掉的开关。
	$ignore = riven_health_ignore_words();
	if ( ! empty( $ignore ) ) {
		foreach ( $lex as $cat => $words ) {
			$lex[ $cat ] = array_values( array_diff( $words, $ignore ) );
		}
	}

	return $lex;
}

/**
 * 后台"停用词"：从词库里剔除不再参与打分的词（内置词、自定义词都可以）
 *
 * @return array
 */
function riven_health_ignore_words() {
	static $ig = null;
	if ( null !== $ig ) {
		return $ig;
	}
	$ig  = array();
	$raw = (string) rcg_opt( 'health_ignore_words', '' );
	if ( '' !== trim( $raw ) ) {
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			foreach ( preg_split( '/[\s,，、]+/u', trim( $line ) ) as $w ) {
				$w = trim( $w );
				if ( '' !== $w && ! in_array( $w, $ig, true ) ) {
					$ig[] = $w;
				}
			}
		}
	}
	return $ig;
}

/** 一段文本打分：每命中一词 +10，上限 80 */
function riven_health_score( $text, $title = '', $skip_junk = false ) {
	$score = 0;
	$hits  = array();
	foreach ( riven_health_lexicon() as $cat => $words ) {
		// 1.4.2：复判存量时要**排除 junk（域名待售）类** —— 待售页就是待售页，
		// 不能因为标题里有"出售/转让"就当成黄赌风险（那是两个语义，后台分开列）。
		if ( $skip_junk && 'junk' === $cat ) {
			continue;
		}
		// junk（域名待售）只在**标题**里找：正文里出现"域名出售"太正常了
		// ——域名论坛、博客都在讨论这些词，实测 dalao.net 就是这么被误伤的。
		// 只有"标题本身"就是待售页才说明这个域名废弃了（2026-10-16）。
		$hay = ( 'junk' === $cat ) ? $title : $text;
		if ( '' === $hay ) {
			continue;
		}
		// 高置信词（strong）权重更高（2026-10-16）：它们是组合词/平台名，
		// 正常内容几乎不会出现，**命中一个就直接够到阈值** —— 否则
		// 「黄色影院 + 大香蕉 + 三级片」这种铁证也只算 30 分，卡在阈值下面被放行；
		// 而像「555WIN」「KY开元」「开云在线」这种站，标题里往往只有一个特征词。
		$per = ( 'strong' === $cat ) ? 50 : 10;   // junk 类走普通权重，是否废弃由下面的专门判断决定
		foreach ( $words as $w ) {
			if ( $score >= 80 ) {
				break 2;
			}
			// 用 stripos：词表里有 555WIN / GetDomain / mgm1888 这类英文词，
			// 站点标题里的大小写不固定，mb_strpos 会漏（2026-10-16 实测踩到）。
			if ( '' !== $w && false !== mb_stripos( $hay, $w ) ) {
				$score += $per;
				$hits[] = $cat . ':' . $w;
			}
		}
	}
	return array( min( $score, 80 ), $hits );
}

/* -------------------------------------------------------- 状态查询（守卫用） */

/**
 * 把这一页评论涉及的域名一次性查出来（性能关键：禁止逐条查库）
 *
 * @param array $comments WP_Comment 列表
 */
function riven_health_prime( $comments ) {
	if ( ! riven_health_verdict_on() || ! riven_health_table_exists() || empty( $comments ) || ! is_array( $comments ) ) {
		return;
	}

	global $wpdb;

	$hosts = array();
	foreach ( $comments as $c ) {
		$u = isset( $c->comment_author_url ) ? (string) $c->comment_author_url : '';
		if ( '' === $u ) {
			continue;
		}
		$h = riven_link_guard_host_key( (string) wp_parse_url( $u, PHP_URL_HOST ) );
		if ( '' !== $h ) {
			$hosts[ $h ] = true;
		}
	}
	$hosts = array_keys( $hosts );
	if ( empty( $hosts ) ) {
		return;
	}

	$cache = riven_health_cache();
	$t     = riven_health_table();

	foreach ( array_chunk( $hosts, 300 ) as $chunk ) {
		$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT host, status, score, is_blog, updated_at, checked_at, hits, title, fail_reason
				 FROM {$t} WHERE host IN ( $ph )",
				$chunk
			)
		);
		foreach ( (array) $rows as $r ) {
			$cache->rows[ $r->host ] = $r;
		}
		// 没记录的域名标记为"没体检过"，避免后续再查一遍
		foreach ( $chunk as $h ) {
			if ( ! isset( $cache->rows[ $h ] ) ) {
				$cache->rows[ $h ] = null;
			}
		}
	}
}

/** 请求内缓存容器 */
function riven_health_cache() {
	static $cache = null;
	if ( null === $cache ) {
		$cache         = new stdClass();
		$cache->rows   = array();
		$cache->loaded = array();
	}
	return $cache;
}

/**
 * 取某域名的体检记录（优先走预查缓存；没有就单查一次并记住）
 *
 * @param string $host
 * @return object|null null = 没体检过
 */
function riven_health_row( $host ) {
	if ( ! riven_health_table_exists() ) {
		return null;
	}
	$h = riven_link_guard_host_key( $host );
	if ( '' === $h ) {
		return null;
	}

	$cache = riven_health_cache();
	if ( array_key_exists( $h, $cache->rows ) ) {
		return $cache->rows[ $h ];
	}

	global $wpdb;
	$t   = riven_health_table();
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT host, status, score, is_blog, updated_at, checked_at, hits, title, fail_reason
			 FROM {$t} WHERE host = %s",
			$h
		)
	);
	$cache->rows[ $h ] = $row ? $row : null;
	return $cache->rows[ $h ];
}

/**
 * 这个域名现在被判为风险吗（体检实锤）
 *
 * @param string $url_or_host
 * @return bool
 */
function riven_health_is_risk( $url_or_host ) {
	$raw = (string) $url_or_host;
	$h   = riven_link_guard_host_key(
		false === strpos( $raw, '/' ) ? $raw : (string) wp_parse_url( $raw, PHP_URL_HOST )
	);
	if ( '' === $h ) {
		return false;
	}
	// 黑名单独立生效：站长明确拉黑的域名，即使体检表里没有记录、甚至体检总开关/
	// 判定开关都关着，也一律算风险（外链分级总开关由调用方把关）。
	//
	// 2026-10-17 自查修正：原条件写成 `riven_health_on() && is_blacklisted()`，
	// 结果站长一关体检（想省服务器），36 个实锤黄赌站的黑名单**整条失效** ——
	// 只剩体检表里的 risk 记录，而关掉 verdict 后连它也读不到，链接全部变回可点。
	// 黑名单是"结论被任何情况覆盖"时的最后一道保险，不该挂在体检开关下面。
	if ( riven_health_is_blacklisted( $h ) ) {
		return true;
	}
	if ( ! riven_health_on() || ! riven_health_verdict_on() ) {
		return false;
	}
	$row = riven_health_row( $h );
	// risk = 黄赌诈骗；junk = 域名待售/停靠页。
	// 两者前台一律降级（都是一定不该留的链接），所以这里统一返回 true；
	// 后台清单才区分展示（2026-10-16）。
	return ( $row && in_array( $row->status, array( 'risk', 'junk' ), true ) );
}

/**
 * 这个域名现在是不是**域名商的停靠页 / 待售页 / 未配置页**（2026-10-17，1.3.1）
 *
 * 与"是不是个人站"分开放，因为它是**客观事实**，不是策略判断：
 * 标题就写着"正在转让出售中 / 免费域名停靠 Domain Parking / Coming Soon"，
 * 说明这个域名已经不属于任何人 —— 谁填的都一样，不该看"这个人还来不来"。
 *
 * 为什么必须单独一层：1.3.1 一开始把它塞进「非个人站」判定（第 ⑥ 步），
 * 实测 itlu.org 的 38 条、stylefanr.org 的 4 条、eirms.com 的 2 条**仍然放行** ——
 * 因为它们卡在第 ④ 步「免考核期」，而那几位访客一直活跃。
 * 客观事实必须排在"人情"之前（免考核 ≠ 免风险）。
 *
 * @param string $url_or_host
 * @return bool
 */
function riven_health_is_parked( $url_or_host ) {
	if ( ! riven_health_on() || ! riven_health_verdict_on() ) {
		return false;
	}
	$raw = (string) $url_or_host;
	$h   = riven_link_guard_host_key(
		false === strpos( $raw, '/' ) ? $raw : (string) wp_parse_url( $raw, PHP_URL_HOST )
	);
	if ( '' === $h ) {
		return false;
	}
	$row = riven_health_row( $h );
	if ( ! $row || 'ok' !== $row->status ) {
		return false;   // 已经是 risk/junk 的走 is_risk；fail 的是看不清，不判
	}
	return riven_health_title_is_parked( (string) $row->title );
}

/**
 * 这个域名是不是"已经死了"（域名都解析不了，点开必然是空）
 *
 * 与"风险"分开：风险是"有害"，这里是"没用"。
 * 老张 2026-10-16 反馈：有些链接点开对方博客已经不在了，但链接还挂着。
 * 域名解析不了就是这种情况 —— 除非关系判定本来就把它降级了，否则前台还是可点的。
 *
 * @param string $url_or_host
 * @return bool
 */
function riven_health_is_dead( $url_or_host ) {
	if ( ! riven_health_on() || ! riven_health_verdict_on() ) {
		return false;
	}
	$raw = (string) $url_or_host;
	$h   = riven_link_guard_host_key(
		false === strpos( $raw, '/' ) ? $raw : (string) wp_parse_url( $raw, PHP_URL_HOST )
	);
	if ( '' === $h ) {
		return false;
	}
	$row = riven_health_row( $h );
	if ( ! $row || 'fail' !== $row->status ) {
		return false;
	}
	// 2026-10-17 扩展（老张确认）：404 / 410 也算"站点已失效" ——
	// 服务器还活着、但首页已经没内容了，点开是错误页，本质上和"域名解析不了"一样
	// （老张报的 imzhang.com 就是 http404 却仍可点）。
	// 注意：403 与超时（noresponse）属于"我们看不见"，**仍然放行**，不作为降级理由 ——
	// 那是我们自己的短板，不该让博友承担。
	return in_array( (string) $row->fail_reason, array( 'dns', 'http404', 'http410' ), true );
}

/**
 * 体检是不是"抓不到"（任何原因：超时 / 403 / 连不上 / 空页）
 *
 * 与 is_dead 的区别：is_dead 只指"域名都解析不了"（确定失效）；
 * 这个更宽，包含"域名还在、但从本站服务器够不着"。
 *
 * 用途：**站长视角**下把这些也降级 —— 老张 2026-10-16 反馈：
 * 他登录着看文章，一堆链接显示可点、点开却是空。站长看页面的目的是
 * 确认"哪些链接真的能用"，抓不到的在他这里没有价值。
 * 访客视角不受影响（避免误伤"海外连不上、国内正常"的站）。
 *
 * @param string $url_or_host
 * @return bool
 */
function riven_health_is_unreachable( $url_or_host ) {
	if ( ! riven_health_on() ) {
		return false;
	}
	$raw = (string) $url_or_host;
	$h   = riven_link_guard_host_key(
		false === strpos( $raw, '/' ) ? $raw : (string) wp_parse_url( $raw, PHP_URL_HOST )
	);
	if ( '' === $h ) {
		return false;
	}
	$row = riven_health_row( $h );
	return ( $row && 'fail' === $row->status );
}

/**
 * 这个域名是不是**已经不是个人站了**（2026-10-16 新增，1.8.0 的新判据）
 *
 * 这是取代"久未往来就撤链接"的那一条。判据从"这个人还来不来"改成
 * "这个域名现在还是不是一个个人站" —— 只有**查明它变成了企业官网、
 * 商城、导航站、空壳页**才撤，其余（停更、抓不到、看不清）一律放行。
 *
 * 三条保守约束（宁可不判，不可冤枉）：
 *   ① 抓不到的一律返回 false —— "我看不见"不等于"它不是个人站"
 *   ② 有博客特征（is_blog）的直接返回 false —— 那就是个人站
 *   ③ 只在**标题**里找特征词 —— 正文里出现"有限公司"太正常（招聘、友链页都有）
 *
 * @param string $url_or_host
 * @return bool
 */
function riven_health_is_nonpersonal( $url_or_host ) {
	if ( ! riven_health_on() || ! riven_health_verdict_on() ) {
		return false;
	}
	$raw = (string) $url_or_host;
	$h   = riven_link_guard_host_key(
		false === strpos( $raw, '/' ) ? $raw : (string) wp_parse_url( $raw, PHP_URL_HOST )
	);
	if ( '' === $h ) {
		return false;
	}
	return riven_health_row_is_nonpersonal( riven_health_row( $h ) );
}

/**
 * 「这行记录是不是已经不是个人站」—— 纯数据判定，不查库、不看开关
 *
 * 抽出来是为了让**后台清单**和**前台判定**用同一段代码：
 * 以前清单自己写 SQL 条件（is_blog=0 且标题含商业词），改判据后两边分叉，
 * 前台降级了的域名在后台看不见，反过来也一样。
 *
 * @param object|null $row 体检表的一行
 * @return bool
 */
function riven_health_row_is_nonpersonal( $row ) {
	if ( ! $row || 'ok' !== $row->status ) {
		return false; // 抓不到 = 看不清，放行；风险/待售在前面已经拦过了
	}
	$title = (string) $row->title;
	// 标题写着"博客/随笔/笔记…" → 个人站，不动它。
	// 1.3.0 把它提到 is_blog 判断**之前**：标题里明摆着"博客"两个字，
	// 就不该因为标题里同时有个"官网"被商业句式抢走。
	if ( riven_health_title_looks_personal( $title ) ) {
		return false;
	}
	// 1.3.4：HTTP 错误页 / 服务器默认页的标题 → 这是"站点此刻出问题"
	// （nginx 挂了、CDN 拦截、服务器 502），**不是"域名转手了"**。
	// 存量里有一批这种（356688.com「404 Not Found」、mom1.cn「404」…），
	// 原来是靠"缺博客特征"顺带降级的，理由根本不对。放在最前：看不清就不降级。
	if ( riven_health_title_is_error_page( $title ) ) {
		return false;
	}
	// 1.3.1：标题本身就是域名商/未配置页的模板 → 客观事实，直接算"已不是个人站"。
	// 放在商业句式之前：这类页面的标题往往没有"交易所/官网入口"这些词
	// （itlu.org 的原标题只有"正在转让出售中 / 免费域名停靠 Domain Parking"）。
	// 读库即生效，不必等重扫 —— 和 58yqm.com 的处理方式一致。
	if ( riven_health_title_is_parked( $title ) || riven_health_title_is_shell_template( $title, (string) $row->host ) ) {
		return true;
	}
	// 1.3.0：标题是商业站点句式 → 不管 is_blog 写的是什么，都算"不是个人站"。
	// 这条同时兜住**改判据之前写进库的旧数据**（is_blog 还是按"有 cms 就算博客"存的），
	// 老张不必再等一次全量重扫才看到效果。
	if ( riven_health_title_is_commercial( $title ) ) {
		return true;
	}
	// 1.3.4：标题把自己域名顶在最前面 → 要分两种：
	//   "Jeray.Wang | 热爱生活 步履不停"  → 域名 + 人名口号，是个人站
	//   "www.6e6.org-官网首页"             → 域名 + 功能词，是企业站/导航站句式
	// 所以先把域名剥掉再判，剥完只剩功能词或空的才算"不是个人站"。
	if ( riven_health_title_starts_with_domain( $title, (string) $row->host ) ) {
		$stripped = riven_health_title_strip_domain( $title, (string) $row->host );
		if ( '' === $stripped || mb_strlen( $stripped ) < 2 ) {
			return true;
		}
		return ! riven_health_title_is_personal_site( $stripped, (string) $row->host );
	}
	// 1.3.4：**标题是人写的个人站标题，本身就是博客特征**（老张报的一批误判）——
	// 博客的标题有两种，「XX的博客」和「猫客印象 / 随望淡思」这种网名意境词，
	// 后者在博客圈极其常见，而它们的首页什么机器指纹都不带
	// （mcoo.cc 是 Z-BlogPHP 变体、laomai.org 是 Next.js 静态导出）。
	// 必须排在 is_blog 之前：这些站 is_blog 都是 0，排在后面就轮不到它说话。
	if ( riven_health_title_is_personal_site( $title, (string) $row->host ) ) {
		return false;
	}
	// 1.3.4（老张："插件该做的事别交给人工"）：标题自称是商业机构
	// （公司 / 工作室 / 信息网 / 搜索 / 导航 / 平台 / SEO优化…）→ 不是个人站。
	// 放在 personal_site 之后：个人站标题先被放走，剩下的才是真机构站。
	// 实测拦回 65 个，其中只有 2 个是个人工作室（王宜楷 / 张俊），
	// 而 obaby 的"独立开发者"和小姚的"记录分享"都因豁免规则保住了。
	if ( riven_health_title_is_biz_site( $title ) ) {
		return true;
	}
	// 有博客特征 = 个人站 → 不动它
	if ( (int) $row->is_blog ) {
		return false;
	}
	/*
	 * 2026-10-17 改判据（老张确认）→ **2026-10-18 1.3.4 废除**：
	 * 原来是"只要没有任何博客特征，就认为它已经不是原来那位博主的站"。
	 * 实测这个假设错得厉害：一批真博客的首页就是不带任何指纹
	 * （见 riven_health_title_is_personal_site 的注释），
	 * lushaojun.com / laomai.org / imzm.im 因此被真的降级了。
	 * **缺证据不等于有反证** —— 抓不到指纹只说明我们看不全，
	 * 不能反过来当成"域名转手了"的证据。
	 * 现在：没有正面证据表明"它现在是商业/机构/平台/停靠页"就一律放行，
	 * 代价是纯业务名的行业站（消防天下、招标、旅行社）也不再降级
	 * —— 老张确认"宁可漏不可冤"，且这些站仍列在后台「暂无指纹·待观察」里，
	 * 想拉黑随时能拉黑。
	 */
	return false;
}

/** 标题里带"个人站"味道的词（兜底，避免误伤没抓到特征的个人站） */
function riven_health_personal_title_words() {
	return array( '博客', 'blog', '个人', '日志', '随笔', '笔记', '自留地', '小站', '折腾', '日记', '文集' );
}

/** 标题是否像个人站（有则不动它） */
function riven_health_title_looks_personal( $title ) {
	$title = (string) $title;
	if ( '' === trim( $title ) ) {
		return false;
	}
	foreach ( riven_health_personal_title_words() as $w ) {
		if ( mb_stripos( $title, $w ) !== false ) {
			return true;
		}
	}
	return false;
}

/**
 * 后台清单：体检抓到了、但"已经不是个人站"的域名（1.8.0 新判据的产出）
 *
 * @param int $limit
 * @return array
 */
function riven_health_nonpersonal_list( $limit = 100, &$hidden = null ) {
	global $wpdb;
	$hidden = 0;
	if ( ! riven_health_table_exists() ) {
		return array();
	}
	// 判定没接入时前台不降级，这里就不该列（否则清单和实际表现对不上）
	if ( ! riven_health_on() || ! riven_health_verdict_on() ) {
		return array();
	}
	$t    = riven_health_table();
	$arch = riven_health_archived_map();
	// 2026-10-17（1.3.0）：这张表以前自己写了套 SQL 口径（is_blog=0 且标题含商业词），
	// 跟**前台实际判定**（riven_health_is_nonpersonal）早就分叉了 ——
	// 1.9.3 把判据改成"没有博客特征就算了"之后，这里还在按老的商业词筛，
	// 于是 58yqm.com 这种前台已经降级的域名，后台清单里一个都看不到。
	// 现在直接调前台那个函数，口径必然一致。
	$rows = $wpdb->get_results(
		"SELECT * FROM {$t} WHERE status='ok' AND title <> '' ORDER BY host"
	);
	$out = array();
	foreach ( (array) $rows as $r ) {
		if ( ! riven_health_row_is_nonpersonal( $r ) ) {
			continue;
		}
		if ( isset( $arch[ $r->host ] ) ) {
			// 已过人情味期：前台本来就按归档降级了，这里再列一遍没有可操作价值（1.11.1）
			$hidden++;
			continue;
		}
		if ( count( $out ) < $limit ) {
			$out[] = $r;
		}
	}
	return $out;
}

/**
 * "已经不是个人站"的标题特征词
 *
 * 只收**明确的商业 / 机构 / 目录站**标志 —— 像"科技""网络""工作室""资源网"
 * 这类个人站也在用的词一律不收（宁可放过）。
 *
 * @return array
 */
function riven_health_nonpersonal_words() {
	return array(
		// 企业 / 商业
		'有限公司', '有限责任公司', '股份', '集团', '官方网站', '旗舰店', '商城',
		'网上商城', '工厂', '制造', '批发', '零售', '供应商', '服务商', '解决方案',
		'产品中心', '技术支持', '客服电话', '营销中心', '加盟', '招商',
		// 机构
		'医院', '学校', '学院', '研究院', '事务所', '律师事务所', '会计师',
		'协会', '商会', '基金会',
		// 目录 / 导航站
		'网址导航', '网址大全', '导航网', '目录站', '收录网', '黄页',
		// 空壳 / 占位页（这类页面标题往往就是"域名-官网首页"）
		'-官网首页', '官网首页-', '首页-欢迎您',
	);
}

/**
 * 这个域名能不能"恢复可点击"：
 *   体检确认 健康 + 是博客 + 近 N 个月还在更新
 *
 * @param string $url_or_host
 * @return bool
 */
function riven_health_can_restore( $url_or_host ) {
	if ( ! riven_health_auto_restore_on() ) {
		return false;
	}
	$h = riven_link_guard_host_key(
		false === strpos( (string) $url_or_host, '/' ) ? $url_or_host : (string) wp_parse_url( $url_or_host, PHP_URL_HOST )
	);
	$row = riven_health_row( $h );
	if ( ! $row || 'ok' !== $row->status || ! (int) $row->is_blog ) {
		return false;
	}
	// 1.3.0：标题是商业站点句式的，永远不"自动恢复可点击"。
	// 它的 is_blog 只是"抓到了 cms 特征"，不代表它还是个人站 ——
	// 否则交易所改个日期就能把自己恢复成蓝色链接。
	if ( riven_health_title_is_commercial( (string) $row->title ) ) {
		return false;
	}
	if ( empty( $row->updated_at ) ) {
		return false;
	}
	$ts = strtotime( (string) $row->updated_at );
	if ( ! $ts ) {
		return false;
	}
	$window = riven_health_fresh_months() * MONTH_IN_SECONDS;
	// 给一点冗余（一个月），避免卡在边界来回变
	return ( time() - $ts ) <= $window + MONTH_IN_SECONDS;
}

/* -------------------------------------------------------------- 黑名单 */

function riven_health_blacklist() {
	static $healed = false;
	$list = rcg_opt( 'health_blacklist', array() );
	if ( ! is_array( $list ) ) {
		$list = array();
	}
	/*
	 * 1.4.1 自愈：名单为空、而主题时代的旧 option 里有值 → 自动回填。
	 *
	 * 为什么需要（血的教训）：1.4.0 发版时我自己的一个测试脚本里写了
	 * `update_option( 'rcg_settings', array_merge( (array) rcg_opt( 'rcg_settings', array() ), array() ) )`
	 * —— `rcg_opt` 是"取某个键的值"，键名传成 'rcg_settings' 只会拿到空数组，
	 * 结果把整个设置 option 清空了，**黑名单从 37 个变成 0 个**，前台拦截面直接消失。
	 * 黑名单是这座站最后一道防线（独立于体检开关、独立于判定开关），
	 * 它静默变空等于全线失守。这里加一道自愈：只要旧 option 还有值就补回来。
	 */
	if ( empty( $list ) && ! $healed ) {
		$healed = true;
		$legacy = get_option( 'riven_health_blacklist', array() );
		if ( is_array( $legacy ) && ! empty( $legacy ) ) {
			$list = array_values( array_map( 'strval', $legacy ) );
			rcg_set( 'health_blacklist', $list );
		}
	}
	return $list;
}

/** 命中黑名单（含子域名匹配） */
function riven_health_is_blacklisted( $url_or_host ) {
	$list = riven_health_blacklist();
	if ( empty( $list ) ) {
		return false;
	}
	$raw = (string) $url_or_host;
	$h   = riven_link_guard_host_key( false === strpos( $raw, '/' ) && false === strpos( $raw, '.' ) ? $raw : (string) wp_parse_url( false === strpos( $raw, '://' ) ? 'http://' . $raw : $raw, PHP_URL_HOST ) );
	if ( '' === $h ) {
		return false;
	}
	foreach ( $list as $b ) {
		$b = riven_link_guard_host_key( (string) $b );
		if ( '' === $b ) {
			continue;
		}
		if ( $h === $b || substr( $h, - strlen( $b ) - 1 ) === '.' . $b ) {
			return true;
		}
	}
	return false;
}

/** 拉黑一个域名 */
function riven_health_blacklist_add( $host ) {
	$h = riven_link_guard_host_key( $host );
	if ( strlen( $h ) < 4 || false === strpos( $h, '.' ) || in_array( $h, array( 'http', 'https', 'www' ), true ) ) {
		return false;
	}
	$list = riven_health_blacklist();
	if ( ! in_array( $h, $list, true ) ) {
		$list[] = $h;
		riven_health_set( 'health_blacklist', $list );
	}
	// 黑名单就地生效：体检记录改判 100 分，免得下一轮扫描又"放行"
	if ( riven_health_table_exists() ) {
		global $wpdb;
		$wpdb->update(
			riven_health_table(),
			array( 'status' => 'risk', 'score' => 100, 'fail_reason' => 'blacklist' ),
			array( 'host' => $h ),
			array( '%s', '%d', '%s' ),
			array( '%s' )
		);
	}
	return true;
}

/** 取消拉黑 */
function riven_health_blacklist_remove( $host ) {
	$h    = riven_link_guard_host_key( $host );
	$list = riven_health_blacklist();
	$new  = array();
	foreach ( $list as $b ) {
		if ( riven_link_guard_host_key( $b ) !== $h ) {
			$new[] = $b;
		}
	}
	riven_health_set( 'health_blacklist', $new );
	return true;
}

/**
 * 黑名单导出（JSON）
 *
 * 为什么需要它（2026-10-17）：老张两个站用的是同一份数据库，w 站扫出 39 个风险、
 * laozhang.org 只有 7 个。查下来 39 里有 38 个是 w 站自己攒的黑名单实锤 ——
 * 黑名单是**每站独立台账**，从不自动同步。所以搬站/多站运营时必须有搬运手段，
 * 否则新站会"重新认识"一遍这些早该拉黑的域名，中间那段时间外链是能点的。
 */
function riven_health_blacklist_export() {
	$list = riven_health_blacklist();
	sort( $list );
	return array(
		'plugin'      => 'wp-comment-guard',
		'type'        => 'blacklist',
		'format'      => 1,
		'site'        => home_url(),
		'exported_at' => current_time( 'mysql' ),
		'count'       => count( $list ),
		'hosts'       => array_values( $list ),
	);
}

/**
 * 黑名单导入（合并，不覆盖）
 *
 * 接受两种输入：上面导出的 JSON，或者一行一个域名的纯文本（方便从别处拷）。
 * 只做"加"，绝不清空 —— 导入一份旧清单不能把本站在这期间新建的黑名单抹掉。
 *
 * @param string $raw 粘贴内容或上传文件内容。
 * @return array( added, skipped, invalid, total )
 */
function riven_health_blacklist_import( $raw ) {
	$raw = (string) $raw;
	$in  = array();

	$raw = trim( $raw );
	if ( '' === $raw ) {
		return array( 'added' => 0, 'skipped' => 0, 'invalid' => 0, 'total' => 0 );
	}

	$json = json_decode( $raw, true );
	if ( is_array( $json ) ) {
		if ( isset( $json['hosts'] ) && is_array( $json['hosts'] ) ) {
			$in = $json['hosts'];
		} elseif ( isset( $json[0] ) ) {
			$in = $json; // 直接一个数组
		}
	} else {
		// 纯文本：换行 / 逗号 / 空格都当分隔符
		$in = preg_split( '/[\s,，;；]+/u', $raw );
	}

	$before  = array_map( 'strtolower', (array) riven_health_blacklist() );
	$added   = 0;
	$skipped = 0;
	$invalid = 0;
	$seen    = array();
	foreach ( (array) $in as $h ) {
		$h = trim( (string) $h );
		if ( '' === $h ) {
			continue;
		}
		$key = riven_link_guard_host_key( $h );
		if ( '' === $key || isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		if ( in_array( strtolower( $key ), $before, true ) ) {
			$skipped++;
		} elseif ( riven_health_blacklist_add( $key ) ) {
			$added++;
		} else {
			$invalid++;
		}
	}
	$list  = riven_health_blacklist();
	$total = count( $list );

	return array( 'added' => $added, 'skipped' => $skipped, 'invalid' => $invalid, 'total' => $total );
}

/**
 * 「暂无指纹·待观察」清单（1.3.4）
 *
 * 这些站：抓到了、HTTP 200、**标题像人写的个人站**，但首页不带任何机器指纹
 * （无 RSS / 无 CMS / 标题里也没有"博客"二字）—— 正是 1.9.3 判据误判的那一批。
 *
 * 1.3.4 起它们**前台不再降级**（缺证据不等于域名转手了），但列在这里供站长参考：
 * 看着像垃圾推广站的，照样可以点"拉黑"。
 *
 * @param int      $limit
 * @param int|null $total
 * @return array
 */
function riven_health_nofp_list( $limit = 100, &$total = null ) {
	global $wpdb;
	$total = 0;
	if ( ! riven_health_table_exists() ) {
		return array();
	}
	$t    = riven_health_table();
	$rows = $wpdb->get_results( "SELECT host, status, score, is_blog, hits, title, checked_at FROM {$t} WHERE status='ok' AND is_blog=0 AND title<>'' ORDER BY checked_at DESC" );
	$arch = riven_health_archived_map();
	$out  = array();
	foreach ( (array) $rows as $r ) {
		if ( isset( $arch[ strtolower( (string) $r->host ) ] ) ) {
			continue; // 已归档：前台早降级，看了也没用
		}
		// 只收"标题像人写的个人站"（= 1.3.4 后被放过的那批）
		if ( ! riven_health_title_is_personal_site( (string) $r->title, (string) $r->host ) ) {
			continue;
		}
		$total++;
		if ( count( $out ) < $limit ) {
			$out[] = $r;
		}
	}
	return $out;
}

/**
 * 取某个后台清单的域名集合（供「扫描复核」用，1.3.4）
 *
 * @param string $kind   nonpersonal | fail
 * @param int    $limit  最多取多少个（一次请求的抓取预算有限）
 * @return array 小写域名
 */
function riven_health_list_hosts( $kind, $limit = 200 ) {
	global $wpdb;
	$out = array();
	if ( ! riven_health_table_exists() ) {
		return $out;
	}
	$t = riven_health_table();
	$arch = riven_health_archived_map();
	if ( 'nonpersonal' === $kind ) {
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE status='ok' AND is_blog=0 AND title<>''" );
		foreach ( (array) $rows as $r ) {
			// 已归档的（超过人情味期）前台早按归档降级，扫了也不会改变任何显示
			if ( isset( $arch[ strtolower( (string) $r->host ) ] ) ) {
				continue;
			}
			if ( riven_health_row_is_nonpersonal( $r ) ) {
				$out[] = (string) $r->host;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
	} elseif ( 'fail' === $kind ) {
		list( $dead ) = riven_health_fail_split();
		foreach ( (array) $dead as $r ) {
			$out[] = (string) $r->host;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
	}
	return $out;
}

/** admin-post 入口：导出黑名单 JSON（走 admin-post 才不会有"headers already sent"） */
function riven_health_blacklist_export_handle() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足。' );
	}
	$nonce = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';
	if ( ! wp_verify_nonce( $nonce, 'rcg_export_blacklist' ) ) {
		wp_die( '安全校验失败，请回后台重试。' );
	}
	$data = riven_health_blacklist_export();
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="riven-blacklist-' . gmdate( 'Ymd-His' ) . '.json"' );
	echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
}
add_action( 'admin_post_rcg_export_blacklist', 'riven_health_blacklist_export_handle' );



/* -------------------------------------------------------------- 扫描引擎 */

/**
 * 全站待体检的域名清单（host => 一个示例 url）
 *
 * 只收「评论作者网址」这一路 —— 正文里的引用链接（github 之类）本来就不参与
 * 域名维度判定，且量大噪音高，收益不值当。
 *
 * 结果进 transient（10 分钟），有新评论/审核动作时作废。
 */
function riven_health_collect_hosts() {
	static $hosts = null;
	if ( null !== $hosts ) {
		return $hosts;
	}
	$cached = get_transient( 'riven_health_hosts' );
	if ( is_array( $cached ) ) {
		$hosts = $cached;
		return $hosts;
	}

	global $wpdb;
	$hosts    = array();
	$site     = riven_link_guard_host_key( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$rows     = $wpdb->get_results(
		"SELECT comment_author_url AS u FROM {$wpdb->comments}
		 WHERE comment_approved IN ('0','1') AND comment_author_url <> ''"
	);
	foreach ( (array) $rows as $r ) {
		$h = riven_link_guard_host_key( (string) wp_parse_url( (string) $r->u, PHP_URL_HOST ) );
		if ( '' === $h || $h === $site || strlen( $h ) < 4 || false === strpos( $h, '.' ) ) {
			continue;
		}
		if ( ! isset( $hosts[ $h ] ) ) {
			$hosts[ $h ] = (string) $r->u;
		}
	}

	/*
	 * 2026-10-17（1.3.1）：**正文里的外链也要进体检队列**。
	 *
	 * 原来只收 comment_author_url，于是正文 `<a href>` 指向的域名永远轮不到体检 ——
	 * 它们连"是不是黄赌站"都无从得知，前台又因为"引用型链接"被判定链放行，
	 * 等于一个完整的盲区。实测 w 站有 6 条评论带 href（5 个域名，其中
	 * irenewbraceletreviews.com / jytrjsbmqzil.com 是 2011 年的随机字母垃圾域名）。
	 *
	 * 只收 `<a href>`，**故意不收裸 URL**：WP 会把裸链接自动变成 href，
	 * 但那些多半是引用（知乎、YouTube、政府备案页），收进来会把考核池撑大、
	 * 白耗抓取预算。要判也只判 risk/dead —— 引用型域名在判定链里本来就不走
	 * 「非个人站」那一条（in_touch 对"从没被当作者网址"的域名返回 null）。
	 */
	$linked = $wpdb->get_results(
		"SELECT comment_content AS c FROM {$wpdb->comments}
		 WHERE comment_approved = '1' AND comment_content LIKE '%<a %'"
	);
	foreach ( (array) $linked as $r ) {
		if ( ! preg_match_all( '#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\']#i', (string) $r->c, $m ) ) {
			continue;
		}
		foreach ( $m[1] as $u ) {
			$u = html_entity_decode( (string) $u, ENT_QUOTES, 'UTF-8' );
			$h = riven_link_guard_host_key( (string) wp_parse_url( $u, PHP_URL_HOST ) );
			if ( '' === $h || $h === $site || strlen( $h ) < 4 || false === strpos( $h, '.' ) ) {
				continue;
			}
			if ( ! isset( $hosts[ $h ] ) ) {
				$hosts[ $h ] = 'http://' . $h . '/';
			}
		}
	}

	set_transient( 'riven_health_hosts', $hosts, 10 * MINUTE_IN_SECONDS );
	return $hosts;
}

/**
 * 取下一批要扫的域名：**没检过的排最前，然后按最久没检的优先**
 *
 * 这样"新评论带来的新域名"天然插队（它们没有记录），不需要额外的钩子。
 *
 * @param int  $limit
 * @param bool $force true = 忽略轮转间隔（后台手动"连续扫描到完成"用），
 *                    false = 只取"没检过"或"超过 TTL 没检"的（自动轮转用）
 * @param string $since 仅 $force 时有意义：本次重扫的起始时间（Y-m-d H:i:s，站点本地）。
 *                      已经在这之后检过的域名跳过 —— 否则 force 永远能取到"最久没检的"，
 *                      一批接一批无限循环（2026-10-17 老张踩了一小时）。
 * @param int|null $total_out 回传"符合条件的一共有多少个"（不受 $limit 限制）
 */
function riven_health_pending( $limit, $force = false, $since = '', &$total_out = null, $only_hosts = null ) {
	$all = riven_health_collect_hosts();
	if ( empty( $all ) || ! riven_health_table_exists() ) {
		return array();
	}

	global $wpdb;
	$t       = riven_health_table();
	$checked = array();

	foreach ( array_chunk( array_keys( $all ), 500 ) as $chunk ) {
		$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT host, checked_at FROM {$t} WHERE host IN ( $ph )", $chunk )
		);
		foreach ( (array) $rows as $r ) {
			$checked[ $r->host ] = (string) $r->checked_at;
		}
	}

	$cut  = riven_health_ttl_cut();
	// 人工白名单（1.3.3 引入，1.4.0 加 TTL）：还在保护期内的域名不进队列。
	// ① 省一次抓取；② 更重要的是**不许把它扫回 junk/risk**，
	//   否则老张前脚恢复、后脚自动轮转又给降级了，等于功能白做。
	// 1.4.0：保护期**到期**的域名重新进队列接受检测 —— 域名随时可能被弃用，
	// 永久免检等于把风险敞口一直开着（参考 nickel-comment-link-guard 的白名单 TTL 设计）。
	// 到期后若真变成了风险/废弃站，会正常降级，同时后台「白名单到期复查」会明确列出，
	// 不会静默处理。
	$wl = array();
	foreach ( riven_health_whitelist() as $w => $added ) {
		if ( ! riven_health_is_whitelisted( $w ) ) {
			continue; // 已过期：重新接受检测
		}
		$h2 = riven_link_guard_host_key( (string) $w );
		if ( '' !== $h2 ) {
			$wl[ $h2 ] = true;
		}
	}
	$list = array();
	foreach ( $all as $h => $url ) {
	// 1.3.4「扫描复核」：只重扫指定清单内的域名（后台按钮用）。
	// 传了 $only_hosts 就无视轮转间隔 —— 站长明确要复核这几个。
	if ( null !== $only_hosts && ! in_array( $h, $only_hosts, true ) ) {
		continue;
	}
	// 白名单（含 TTL 判断）：保护期内跳过；**已过期的照样扫** ——
	// 点「扫描复核」本来就是要看它现在怎么样了。
	if ( isset( $wl[ $h ] ) ) {
		continue;
	}
		// 时间闸门（1.10.0）：超过"人情味期"的域名直接不进队列 —— 前台早就按归档降级了，
		// 再抓一次除了烧 CPU 没有任何收益。$force 也不能绕过它（否则"全部重扫"又会回到 2086 个）。
		// ⚠️ 但「扫描复核」要绕过：站长点按钮就是要看这几个域名现在怎么样了，
		// 其中不少正因为超期才落到"已失效"清单里，绕不过去就等于按钮只对一半人生效。
		if ( null === $only_hosts ) {
			$hcut = riven_health_cut_for_host( $h );
			if ( null === $hcut ) {
				continue;
			}
		}
		$c = isset( $checked[ $h ] ) ? (string) $checked[ $h ] : '';
		// 还没到轮转时间的不扫。checked_at 是空的（没检过 / 刚被评论"催检"清空）一律进队列。
		if ( ! $force && '' !== $c && $c > $hcut ) {
			continue;
		}
		// 全站重扫（force）：本次会话里已经检过的跳过。
		// 这是 force 模式能收敛的唯一保证 —— 没有它，队列每批都取"最久没检的 15 个"，
		// 第一轮扫完又开始第二轮，页面能刷新一整晚（2026-10-17 实测一小时没停）。
		if ( $force && '' !== $since && '' !== $c && $c >= $since ) {
			continue;
		}
		$list[] = array(
			'host'    => $h,
			'url'     => $url,
			'checked' => $c,
		);
	}
	usort(
		$list,
		function ( $a, $b ) {
			if ( $a['checked'] === $b['checked'] ) {
				return 0;
			}
			if ( '' === $a['checked'] ) {
				return -1;
			}
			if ( '' === $b['checked'] ) {
				return 1;
			}
			return strcmp( $a['checked'], $b['checked'] );
		}
	);

	$total_out = count( $list );
	return array_slice( $list, 0, $limit );
}

/**
 * 到期待检的数量（没检过的 + 超过轮转间隔的）
 *
 * 2026-10-17 起受「时间闸门」约束：已过人情味期的域名不再算"到期"，
 * 免考核期内的域名按低频间隔算。老张的原话是"减轻服务器负担"，
 * 后台这个数字要跟真实排队量一致，不能还按 2086 报。
 */
function riven_health_due_count() {
	$all = riven_health_collect_hosts();
	if ( empty( $all ) || ! riven_health_table_exists() ) {
		return 0;
	}
	return count( riven_health_pending( count( $all ) ) );
}

/**
 * 已归档域名集合（host => true）
 *
 * 定义与闸门完全一致：`riven_health_cut_for_host()` 返回 null 就是"过了人情味期"——
 * 前台直接降级成纯文本、且**永远不进体检队列**。
 *
 * 为什么单独抽出来（1.11.1，老张反馈）：
 *   体检表里躺着大量历史结论，其中绝大多数属于归档域名。后台列表照单全收，
 *   于是「抓不到（1276，显示最近 30 个）」里 1006 个是五年没往来、早就降级、
 *   以后也不会再去抓的站 —— 唯一作用就是稀释掉真正该看的那 270 个。
 *   列表要按**考核池**口径出，归档的只留个数字，不出条目。
 *
 * 单次请求内 static 缓存：一次遍历 2000+ 域名（host_map / owner_map 本身是
 * transient 数组，查表 O(1)），实测 0.3 秒内跑完；后台几个列表共用这一份。
 *
 * @return array
 */
function riven_health_archived_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	if ( ! riven_health_table_exists() ) {
		return $map;
	}
	foreach ( riven_health_collect_hosts() as $h => $u ) {
		if ( null === riven_health_cut_for_host( $h ) ) {
			$map[ $h ] = true;
		}
	}
	return $map;
}

/**
 * 整表状态：host => array( 'status' => ..., 'reason' => ... )
 *
 * 1.11.2：后台要给两份列表（抓不到 / 换域名候选）逐行标状态。
 * 之前每行查一次 SQL，200 多个邮箱就是几百次查询；这里一次读全表建索引。
 */
function riven_health_status_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	if ( ! riven_health_table_exists() ) {
		return $map;
	}
	global $wpdb;
	$t = riven_health_table();
	foreach ( (array) $wpdb->get_results( "SELECT host, status, fail_reason FROM {$t}" ) as $r ) {
		$map[ (string) $r->host ] = array(
			'status' => (string) $r->status,
			'reason' => (string) $r->fail_reason,
		);
	}
	return $map;
}

/**
 * 「抓不到」按影响拆成两拨 —— 老张 2026-10-17 质疑「显示抓不到有什么意义」
 *
 * 答案是一半有意义、一半是噪音：
 *   ① status=fail 且 fail_reason ∈ dns/http404/http410 → **前台已经降级成纯文本**
 *      （riven_health_is_dead 就是这几条）。这是真影响：博友的站挂了或换了域名，
 *      值得列出来给他看。
 *   ② 其余（noresponse / http403 / 5xx / 空壳）→ 我们在海外服务器上"看不见"，
 *      前台照常可点，判定一点没变。列 30 条纯属占地方，只留一个数字。
 *
 * @return array( dead_rows, dead_total, blind_total )
 */
function riven_health_fail_split() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = array( array(), 0, 0 );
	if ( ! riven_health_table_exists() ) {
		return $cache;
	}
	global $wpdb;
	$t    = riven_health_table();
	$arch = riven_health_archived_map();
	$all  = $wpdb->get_results( "SELECT * FROM {$t} WHERE status='fail' ORDER BY checked_at DESC" );
	$dead = array();
	$blind = 0;
	foreach ( (array) $all as $r ) {
		if ( isset( $arch[ $r->host ] ) ) {
			continue;   // 已归档：前台早就降级，不再体检，也不列
		}
		if ( in_array( (string) $r->fail_reason, array( 'dns', 'http404', 'http410' ), true ) ) {
			$dead[] = $r;
		} else {
			$blind++;
		}
	}
	$cache = array( $dead, count( $dead ), $blind );
	return $cache;
}

/**
 * 考核池统计：[参与体检的域名数, 已过人情味期不再体检的域名数]
 *
 * 后台拿它显示「考核池 489 个 · 已归档 1460 个不再体检」，
 * 免得老张点"全部重扫"时以为漏扫了。
 */
function riven_health_pool_stats() {
	$all   = riven_health_collect_hosts();
	$total = count( $all );
	if ( 0 === $total || ! riven_health_table_exists() ) {
		return array( 0, 0 );
	}
	$off = count( riven_health_archived_map() );
	return array( max( 0, $total - $off ), $off );
}

/** 队列总览：[考核池总数, 池内已检数] */
function riven_health_progress() {
	$stats = riven_health_pool_stats();
	$pool  = (int) $stats[0];
	if ( 0 === $pool || ! riven_health_table_exists() ) {
		return array( $pool, 0 );
	}
	$due = riven_health_due_count();
	return array( $pool, max( 0, $pool - $due ) );
}

/* ------------------------------------------------------------ 抓取与分析 */

/**
 * 这个 host 是不是本机 / 内网 / 云元数据地址（1.5.1 · SSRF 防护）
 *
 * 为什么必须做：体检抓的是**访客可控的网址**。访客把
 *   http://192.168.1.1/  、 http://127.0.0.1:8080/ 、
 *   http://169.254.169.254/latest/meta-data/（云厂商元数据，含临时凭据）
 * 填成评论作者网址，或者在正文 <a href> 里塞这些地址，抓取队列就会去访问内网。
 * 这是标准的 SSRF：能探内网端口、能打内网服务，云上还能读到元数据；
 * 而且抓到的**标题会入库并在后台显示**，等于把内网信息带出来给人看。
 *
 * 判定规则（宁可多拦，反正这些地址也不该出现在博客评论里）：
 *   · localhost / *.localhost / *.local / *.internal / *.lan / *.home.arpa
 *   · 十进制、十六进制、八进制写法（http://2130706433/ 就是 127.0.0.1）
 *   · IP 字面量落在私有段/保留段（127/8、10/8、172.16/12、192.168/16、169.254/16、
 *     100.64/10、198.18/15、::1、fc00::/7、fe80::/10 …）
 *   · 普通域名解析一次，落到上面这些段也算（防"公网域名指向内网"）
 *
 * ⚠️ 域名解析结果只是**预检**。真正的兜底在抓取之后：
 * 看 curl 实际连上的对端 IP（CURLINFO_PRIMARY_IP），DNS rebinding 骗不过那一步。
 *
 * @param string $host
 * @return bool
 */
function riven_health_host_is_internal( $host ) {
	$host = strtolower( trim( (string) $host, " \t\n\r\0\x0B." ) );
	if ( '' === $host ) {
		return true;
	}
	// 去端口 / 去 IPv6 方括号。
	// ⚠️ 不能无脑 preg_replace('/:\d+$/') —— 那会把 `::1` 削成 `:`（IPv6 自带冒号），
	// 1.5.1 首测就漏了 `::1`，靠 `[::1]` 才拦住。
	if ( preg_match( '/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $host, $m ) ) {
		$host = strtolower( $m[1] );
	} elseif ( 1 === substr_count( $host, ':' ) ) {
		$host = (string) preg_replace( '/:\d+$/', '', $host );
	}

	if ( in_array( $host, array( 'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback' ), true ) ) {
		return true;
	}
	foreach ( array( '.localhost', '.local', '.internal', '.lan', '.home.arpa', '.localdomain' ) as $suffix ) {
		if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
			return true;
		}
	}

	// 十进制 / 十六进制 / 八进制混淆写法：curl 会当成 IP 解析
	if ( preg_match( '/^(0x[0-9a-f]+|0[0-7]+|\d{1,10})$/i', $host ) ) {
		return true;
	}

	$flag = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
	$is_bad_ip = function ( $ip ) use ( $flag ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false; // 不是 IP，交给别的分支判断
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, $flag ) ) {
			return true;
		}
		// IPv4 映射的 IPv6（::ffff:127.0.0.1）：取出尾巴按 IPv4 再判一次
		if ( false !== strpos( $ip, ':' ) && false !== strpos( $ip, '.' ) ) {
			$tail = substr( $ip, strrpos( $ip, ':' ) + 1 );
			if ( filter_var( $tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
				&& ! filter_var( $tail, FILTER_VALIDATE_IP, $flag ) ) {
				return true;
			}
		}
		// FILTER_FLAG_NO_RES_RANGE 不一定覆盖这几段，补上
		if ( false !== strpos( $ip, '.' ) ) {
			$long = ip2long( $ip );
			if ( false !== $long ) {
				$extra = array(
					array( '100.64.0.0', '100.127.255.255' ),   // 运营商级 NAT
					array( '198.18.0.0', '198.19.255.255' ),    // 基准测试保留
					array( '192.0.0.0', '192.0.2.255' ),        // IETF 协议分配
				);
				foreach ( $extra as $rg ) {
					if ( $long >= ip2long( $rg[0] ) && $long <= ip2long( $rg[1] ) ) {
						return true;
					}
				}
			}
		}
		return false;
	};

	// IP 字面量
	if ( $is_bad_ip( $host ) ) {
		return true;
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return false; // 是合法公网 IP
	}

	// 普通域名：解析一次看落到哪
	$ip = gethostbyname( $host );
	if ( $ip !== $host && $is_bad_ip( $ip ) ) {
		return true;
	}
	return false;
}

/**
 * 并发抓取（curl_multi，默认 20 路）。
 *
 * 与插件那套的差别：超时放宽到 12 秒，且对"超时 / 无响应"再重试一次 ——
 * 实测本站 1960 个域名有 67.6% 抓不到，其中不少是国内老站太慢，放宽后能多捞回一些。
 *
 * @param array $urls
 * @param int   $deadline 墙钟截止点（microtime(true) 口径）。到了就不再开新的一组，
 *                        没抓到的 URL 不会出现在返回数组里 —— 调用方据此判断
 *                        "这轮没轮到它"，绝不能当成抓取失败。
 * @return array url => array(ok, body, reason)
 */
function riven_health_fetch_many( $urls, $timeout, $concurrency = 20, $deadline = 0 ) {
	$urls = array_values( array_unique( (array) $urls ) );
	$out  = array();
	if ( empty( $urls ) ) {
		return $out;
	}

	$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

	$run = function ( $batch ) use ( $timeout, $concurrency, $ua, $deadline ) {
		$res   = array();
		$queue = array();
		/*
		 * SSRF 防护（1.5.1）：内网 / 本机地址一律不发请求。
		 *
		 * 必须写进 $res —— 否则调用方会当成"这一轮没轮到它"，于是每批都重新排队，
		 * 这几个域名永远出不了队列（等于给攻击者一个免费的队列阻塞手段）。
		 */
		foreach ( (array) $batch as $u ) {
			$uh = (string) wp_parse_url( (string) $u, PHP_URL_HOST );
			if ( riven_health_host_is_internal( $uh ) ) {
				$res[ $u ] = array( 'ok' => false, 'body' => '', 'reason' => 'internal', 'retryable' => false );
				continue;
			}
			$queue[] = $u;
		}
		while ( ! empty( $queue ) ) {
			// 预算快用完就不再开新组：剩下没抓的域名不写库，下一批继续。
			if ( $deadline && microtime( true ) > $deadline - 3 ) {
				break;
			}
			// 本组超时不超过剩余预算，避免最后一组把整请求拖过 php-fpm 时限。
			$tmo = $timeout;
			if ( $deadline ) {
				$left = (int) ( $deadline - microtime( true ) );
				if ( $left < $tmo ) {
					$tmo = max( 3, $left );
				}
			}
			$group = array_splice( $queue, 0, $concurrency );
			$mh    = curl_multi_init();
			$chs   = array();
			foreach ( $group as $u ) {
				$ch = curl_init();
				curl_setopt_array(
					$ch,
					array(
						CURLOPT_URL            => $u,
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_FOLLOWLOCATION => true,
						CURLOPT_MAXREDIRS      => 5,
						CURLOPT_TIMEOUT        => $tmo,
						CURLOPT_CONNECTTIMEOUT => $tmo,
						CURLOPT_SSL_VERIFYPEER => false,
						CURLOPT_SSL_VERIFYHOST => false,
						CURLOPT_USERAGENT      => $ua,
						// 只允许 http/https：防止 file:// / gopher:// / dict:// 这类协议被当成"网址"。
						CURLOPT_PROTOCOLS         => CURLPROTO_HTTP | CURLPROTO_HTTPS,
						CURLOPT_REDIR_PROTOCOLS   => CURLPROTO_HTTP | CURLPROTO_HTTPS,
						CURLOPT_HTTPHEADER     => array(
							'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
							'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
						),
					)
				);
				curl_multi_add_handle( $mh, $ch );
				$chs[ $u ] = $ch;
			}

			$group_deadline = microtime( true ) + $tmo * 2 + 2;
			$running  = 0;
			$mrc      = CURLM_OK;
			do {
				$mrc = curl_multi_exec( $mh, $running );
			} while ( CURLM_CALL_MULTI_PERFORM === $mrc );
			while ( $running > 0 && CURLM_OK === $mrc && microtime( true ) < $group_deadline ) {
				if ( -1 === curl_multi_select( $mh, 0.2 ) ) {
					usleep( 50000 );
				}
				do {
					$mrc = curl_multi_exec( $mh, $running );
				} while ( CURLM_CALL_MULTI_PERFORM === $mrc );
			}

			foreach ( $chs as $u => $ch ) {
				$body  = (string) curl_multi_getcontent( $ch );
				$errno = curl_errno( $ch );
				$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				// 实际连上的对端 IP：DNS 解析成内网、或 302 跳到内网，只有这一步拦得住
				$peer  = (string) curl_getinfo( $ch, CURLINFO_PRIMARY_IP );
				curl_multi_remove_handle( $mh, $ch );
				curl_close( $ch );

				if ( $errno ) {
					if ( 28 === $errno ) {
						$r = 'timeout';
					} elseif ( 6 === $errno ) {
						$r = 'dns';
					} elseif ( 7 === $errno ) {
						$r = 'refused';
					} elseif ( in_array( $errno, array( 35, 51, 60 ), true ) ) {
						$r = 'ssl';
					} else {
						$r = 'curl' . $errno;
					}
					$res[ $u ] = array( 'ok' => false, 'body' => '', 'reason' => $r, 'retryable' => in_array( $r, array( 'timeout', 'dns' ), true ) );
				} elseif ( 0 === $code ) {
					// 实测这类绝大多数是域名已经解析不了（DNS 真死），重试只是浪费时间
					$res[ $u ] = array( 'ok' => false, 'body' => '', 'reason' => 'noresponse', 'retryable' => false );
				} elseif ( $code < 200 || $code >= 400 ) {
					$res[ $u ] = array( 'ok' => false, 'body' => '', 'reason' => 'http' . $code, 'retryable' => false );
				} elseif ( '' !== $peer && filter_var( $peer, FILTER_VALIDATE_IP )
					&& ! filter_var( $peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					// SSRF 兜底：请求确实发出去了，但对端是内网（DNS 指向内网 / 302 跳内网）。
					// 结果一律丢弃 —— 内网页面的标题、正文绝不能进库。
					$res[ $u ] = array( 'ok' => false, 'body' => '', 'reason' => 'internal', 'retryable' => false );
				} elseif ( '' === trim( $body ) ) {
					$res[ $u ] = array( 'ok' => false, 'body' => '', 'reason' => 'empty', 'retryable' => true );
				} else {
					$res[ $u ] = array( 'ok' => true, 'body' => $body, 'reason' => '', 'retryable' => false );
				}
			}
			curl_multi_close( $mh );
		}
		return $res;
	};

	$first = $run( $urls );

	// 失败重试一次（只重试"可能只是慢"的：超时 / 无响应）
	$again = array();
	foreach ( $first as $u => $r ) {
		if ( ! $r['ok'] && ! empty( $r['retryable'] ) ) {
			$again[] = $u;
		}
	}
	if ( ! empty( $again ) ) {
		$second = $run( $again );
		foreach ( $second as $u => $r ) {
			if ( $r['ok'] ) {
				$first[ $u ] = $r;
			}
		}
	}

	// 第三轮：**换个协议再试一次**（2026-10-16 实测补上的）
	//
	// 老站常常只支持其中一种协议，而评论里填的那个可能早就失效了。
	// 实测：510 个"抓不到"的域名里有 **50 个（9.8%）换协议就能打开** ——
	//   ・活着的博客被误判成"看不清" → 白降级（卢松松、暮光、猫客印象…）
	//   ・赌博/色情站被误判成"看不清" → **安全漏网**（抓不到就不改变默认结果，
	//     访客只要还活跃，链接就照样挂在站上）
	// 所以这一轮既提召回又堵漏。DNS 真死的不用试（换协议也没用）。
	$alt = array();
	foreach ( $first as $u => $r ) {
		// internal（内网地址，SSRF 防护）不换协议重试 —— 换完还是内网
		if ( $r['ok'] || 'dns' === $r['reason'] || 'internal' === $r['reason'] ) {
			continue;
		}
		if ( 0 === strpos( $u, 'https://' ) ) {
			$alt[ preg_replace( '#^https://#', 'http://', $u ) ] = $u;
		} elseif ( 0 === strpos( $u, 'http://' ) ) {
			$alt[ preg_replace( '#^http://#', 'https://', $u ) ] = $u;
		}
	}
	if ( ! empty( $alt ) ) {
		$third = $run( array_keys( $alt ) );
		foreach ( $third as $au => $r ) {
			if ( ! empty( $r['ok'] ) && isset( $alt[ $au ] ) ) {
				$first[ $alt[ $au ] ] = $r;
			}
		}
	}

	// 第四轮：裸域解析不了时，补 www 再试（2026-10-17，老张报 zahui.top 误判）
	//
	// `riven_link_guard_host_key()` 会把 www. 去掉，所以体检表里的 host 是裸域；
	// 而不少站**只给 www 配了 A 记录**，裸域压根不解析。于是我们拿裸域去抓必然
	// errno 6（Could not resolve host），再被 `fail_reason='dns'` 判成死链 ——
	// 前台无辜降级，可访客点 www 明明是好的。
	// 实测：817 个 dns 失败里有 19 个属于这种（zahui.top / quji.org / hanson.run …）。
	//
	// 反过来不成立：www 能开而裸域打不开，那是博主自己的 DNS 没配好，
	// 但"站点还活着"这个事实没变，不该降级。
	$www = array();
	// www 兜底本身是串行的 DNS 查询 + 抓取，一批里失败的域名多时会线性变慢。
	// 限 5 个、且总耗时 25 秒就收手：没轮到的那几个保持原判（fail = 前台照常可点），
	// 不会被误降级，下一批还会再试。2026-10-17 白屏事故就是这里不限量造成的。
	$www_checks = 0;
	$www_t0     = microtime( true );
	foreach ( $first as $u => $r ) {
		// 'noresponse'（curl code=0）也要试：DNS 解析不了时，curl 有时给 errno 6，
		// 有时只给 code=0 —— 两者都可能是"裸域没配 A 记录"，不能只认 errno 6。
		// 404/410 同理：裸域常见的是"没配虚拟主机 → 默认 404 页"，而 www 才是真站点。
		// 实测 47 个 404 里有 9 个属于这种（ewao.cn / tianchunlei.com 是正常博客，
		// 18yqm.com / shamas.me 是赌站 —— 靠"死链"降级虽然结果对，理由却是错的）。
		if ( $r['ok'] || ! in_array( (string) $r['reason'], array( 'dns', 'noresponse', 'http404', 'http410' ), true ) ) {
			continue;
		}
		$p = wp_parse_url( $u );
		if ( empty( $p['host'] ) || 0 === stripos( $p['host'], 'www.' ) ) {
			continue;
		}
		if ( $www_checks >= 5 || ( microtime( true ) - $www_t0 ) > 25 ) {
			continue;
		}
		$path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		if ( ! empty( $p['query'] ) ) {
			$path .= '?' . $p['query'];
		}
		// 先确认 www 真的有 A 记录，没有就别发请求：
		// 否则每个"真死"的域名都要白等两个超时。
		// 用带缓存的 rcg_host_resolves()：同一个域名一天内只查一次，
		// 不再是每次扫描都拿系统 resolver 硬等（这就是白屏的元凶之一）。
		$wh = 'www.' . $p['host'];
		$www_checks++;
		if ( ! rcg_host_resolves( $wh, false ) ) {
			continue;
		}
		// 根路径优先：我们判的是"这个域名还活着吗"，不是"那条 2013 年的旧文章还在吗"。
		// 原 URL 带路径且早已 404 时，只拼原路径会又判一次 404。
		$paths = array( '/' );
		if ( '/' !== $path ) {
			$paths[] = $path;
		}
		foreach ( $paths as $pa ) {
			$www[ 'https://' . $wh . $pa ] = $u;
			$www[ 'http://' . $wh . $pa ]  = $u;
		}
	}
	if ( ! empty( $www ) ) {
		$fourth = $run( array_keys( $www ) );
		foreach ( $fourth as $wu => $r ) {
			$orig = isset( $www[ $wu ] ) ? $www[ $wu ] : '';
			// 只要有一个变体成功就认；后面失败的那个不覆盖已成功的结果
			if ( $orig && ! empty( $r['ok'] ) && empty( $first[ $orig ]['ok'] ) ) {
				$first[ $orig ] = $r;
			}
		}
	}

	foreach ( $first as $u => $r ) {
		$out[ $u ] = $r;
	}
	return $out;
}

/**
 * 从一个页面里找出它的「跳转目标」（JS 重定向 / meta refresh）
 *
 * 域名停靠商很喜欢这种写法：首页只放一行 `window.location.href="/lander"`，
 * 人用浏览器打开会跳到待售页，而机器只看首页什么都读不到。
 * 所以抓到这种空壳页时要跟一跳，看它到底跳到哪 —— 有的跳到待售页，
 * 有的直接跳去黄赌站，两种都得看清（2026-10-17）。
 *
 * @param string $html
 * @param string $base_url 用于把相对地址补全
 * @return string 绝对地址；找不到返回空串
 */
function riven_health_find_redirect( $html, $base_url ) {
	$html = (string) $html;
	$t    = '';

	if ( preg_match( '#location\.(?:href|replace|assign)\s*[=(]\s*["\']([^"\']+)["\']#i', $html, $m ) ) {
		$t = $m[1];
	} elseif ( preg_match( '#http-equiv\s*=\s*["\']refresh["\'][^>]*content\s*=\s*["\'][^"\']*url\s*=\s*([^"\'\s>;]+)#i', $html, $m2 ) ) {
		$t = $m2[1];
	}

	$t = trim( (string) $t );
	if ( '' === $t || 0 === strpos( $t, '#' ) || 0 === stripos( $t, 'javascript:' ) ) {
		return '';
	}
	if ( 0 === strpos( $t, '//' ) ) {
		$pp = wp_parse_url( $base_url );
		return ( isset( $pp['scheme'] ) ? $pp['scheme'] : 'http' ) . ':' . $t;
	}
	if ( false === strpos( $t, '://' ) ) {
		return rtrim( $base_url, '/' ) . '/' . ltrim( $t, '/' );
	}
	return $t;
}

/** 从 HTML 里抠出正文文本 / 标题 / RSS 地址 / 日期 / 博客特征 */
function riven_health_analyze_html( $html, $base_url ) {
	$html = (string) $html;

	$title = '';
	if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
		$title = trim( preg_replace( '#\s+#u', ' ', html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
	}
	$desc = '';
	if ( preg_match( '#<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']#is', $html, $m2 ) ) {
		$desc = $m2[1];
	}

	$body = preg_replace( '#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html );
	$body = strip_tags( (string) $body );
	$body = html_entity_decode( $body, ENT_QUOTES, 'UTF-8' );
	$body = preg_replace( '#\s+#u', ' ', $body );

	// ===== 博客特征（2026-10-17 连修两处误判，1.9.4）=====
	//
	// 起因：老张发现 chanue.com（Wix 建的模特主页，"许岑"2011 年留的链接）在前台仍可点。
	// 查下来是**特征提取本身在撒谎**，而且是两种谎：
	//
	//   ① 匹配范围太脏：原来拿**整份 $html** 去搜 CMS 名字，而现代站点的 HTML 里
	//      塞着 2MB 级 inline JS —— Wix 那份页面的 JS 里有个组件名 `GhostComp`，
	//      一句 ghost 就白送一个 cms 特征 → is_blog=1 → 「已经不是个人站」判定放行。
	//   ② generator 太廉价：原来"只要页面挂了 generator meta 就算博客"，
	//      于是 Wix / Squarespace / Webflow 这些建站平台全成了博客。
	//
	// 实测抓到的其它噪音（都是真站）：
	//      banwagong.me  → CSS 类名 `btn-ghost` 命中 ghost
	//      dute.org      → 在线工具站的"WordPress主题检测"工具名命中 wordpress
	//      cgartlab.com  → 博文标题里的 "WordPress插件商店" 命中 wordpress
	//   这些都是"页面上提到过 WordPress"，跟"这站是 WordPress 建的"毫无关系。
	//
	// 定稿三条：
	//   ① 先把 <script>/<style> 的**内容**挖掉 —— 保留标签本身，因为 WordPress
	//      首页的 /wp-content/ 常常只出现在 `<script src>` / `<link href>` 属性里，
	//      连标签一起删会把真博客的 path 特征也删没。
	//   ② CMS 名字只在 **<head> + HTML 注释**里找（真正的指纹都在这儿：generator
	//      声明、主题链接、构建工具注释）；正文/属性里出现的一律当噪音。
	//   ③ generator 要 content 里真写着 CMS 系统名才算，且正则要能吃下
	//      **无引号属性** —— Hugo / Hexo 输出的是 `<meta name=generator
	//      content="Hugo 0.168.0">`，带引号的写法一律抓不到，eallion.com、
	//      dusays.com、iliu.org 全部因此丢过特征。
	//
	// ⚠️ assets/helper/link-helper.ps1 里有一份等价的 PowerShell 实现（本机抓取助手
	//    自己提取特征）。**改这里必须同步改那边**，否则服务器判"正常"、本机判"风险"。
	$markup = preg_replace( '#(<(script|style)\b[^>]*>).*?(</\2>)#is', '$1$3', (string) $html );

	// CMS 指纹的搜索区：head + 注释
	if ( preg_match( '#<head\b[^>]*>(.*?)</head>#is', $markup, $hm ) ) {
		$cms_zone = $hm[1];
	} else {
		$cms_zone = substr( $markup, 0, 30000 );
	}
	if ( preg_match_all( '#<!--(.*?)-->#s', $markup, $cmt ) ) {
		$cms_zone .= ' ' . implode( ' ', array_slice( $cmt[1], 0, 40 ) );
	}
	$cms_zone = substr( $cms_zone, 0, 200000 );

	// 词表：z[-\s]?blog 修的是 Z-BlogPHP（原来写成 z\.?blog，连字符版本抓不到）；
	// 补上 halo（国产博客系统）、gridea、astro、gatsby 这些内容站生成器。
	// 刻意**不收** next/nuxt/vue 这类通用前端框架 —— 企业官网也在用，认了等于放宽。
	$cms_re = '#(wordpress|typecho|hexo|hugo|z[-\s]?blog|emlog|ghost|jekyll|movabletype|drupal|halo|gridea|astro|gatsby)#i';

	$generator = '';
	if ( preg_match( '#<meta\b[^>]*\bname\s*=\s*["\']?generator["\']?[^>]*>#i', $markup, $gm ) ) {
		if ( preg_match( '#content\s*=\s*["\']?([^"\'>]+)["\']?#i', $gm[0], $gc ) ) {
			$generator = trim( $gc[1] );
		}
	}

	$signals = array();
	if ( preg_match( '#type\s*=\s*["\']application/(rss|atom)\+xml["\']#i', $markup ) ) {
		$signals[] = 'rss';
	}
	if ( preg_match( $cms_re, $cms_zone ) ) {
		$signals[] = 'cms';
	}
	// generator 声明了博客/CMS 系统（WordPress 6.x / Hexo 8 / Hugo 0.1xx / Z-BlogPHP…）
	if ( '' !== $generator && preg_match( $cms_re, $generator ) ) {
		$signals[] = 'cms';
		$signals[] = 'generator';
	}
	if ( preg_match( '#(/wp-content/|/wp-includes/|/usr/themes/|/usr/plugins/|/typecho/)#i', $markup ) ) {
		$signals[] = 'path';
	}
	if ( preg_match( '#(博客|blog|笔记|随笔|小站|空间|记录|折腾)#iu', $title ) ) {
		$signals[] = 'title';
	}
	// 兜底：页面里出现博客圈的**黑话**。
	// 有些自研 / 手写的个人博客没有任何机器可读指纹 —— 实测 jdeal.cn（Typecho 只写在
	// 博文正文里）、ficor.net（自研 React 站）、anji66.net 收紧 cms 范围后会一条特征
	// 都不剩，再叠上"久未往来"就会被误判成"域名早转手了"。这条把它们捞回来。
	// 词表刻意只收博客圈专有词："友情链接""RSS订阅""归档"这些商用站（工具站、行业站、
	// 问卷站）也满页都是，收了等于白送特征，一律不要。
	foreach ( array( '友链', '博主', '博友', '留言板', '独立博客', '个人博客', '博客园', '自留地' ) as $bw ) {
		if ( false !== mb_strpos( $body, $bw ) ) {
			$signals[] = 'blogish';
			break;
		}
	}
	$signals = array_values( array_unique( $signals ) );

	// RSS 地址（有的话，下一轮拿精确日期）
	$rss = '';
	if ( preg_match_all( '#<link\b[^>]+>#i', $markup, $lt ) ) {
		foreach ( $lt[0] as $tag ) {
			if ( preg_match( '#application/(rss|atom)\+xml#i', $tag ) && preg_match( '#href\s*=\s*["\']([^"\']+)["\']#i', $tag, $h ) ) {
				$u = html_entity_decode( $h[1], ENT_QUOTES, 'UTF-8' );
				if ( 0 === strpos( $u, '//' ) ) {
					$u = 'http:' . $u;
				} elseif ( '/' === substr( $u, 0, 1 ) ) {
					$p = wp_parse_url( $base_url );
					$u = ( isset( $p['scheme'] ) ? $p['scheme'] : 'http' ) . '://' . $p['host'] . $u;
				} elseif ( false === strpos( $u, 'http' ) ) {
					$u = rtrim( $base_url, '/' ) . '/' . ltrim( $u, '/' );
				}
				$rss = $u;
				break;
			}
		}
	}

	// 空壳页（域名停靠 / 占位页）：正文几乎为零，且不是 JS 应用（SPA）。
	// 2026-10-17 老张发现：redich.com 的首页只返回一行
	// `window.location.href="/lander"`（整页 114 字节）—— 打分拿不到任何词 → 0 分 →
	// 判成"正常"，前台仍可点击，而点开是域名待售页。
	// 正常 SPA 博客的 HTML 也没有正文，但体量很大，所以这里要求"整页也很小"。
	$shell = false;
	if ( mb_strlen( $body ) < 30 && strlen( $html ) < 6000
		&& ! preg_match( '#(id=["\']app["\']|__NUXT__|__NEXT_DATA__|vite|webpackJsonp|data-server-rendered|window\.__INITIAL)#i', $html ) ) {
		$shell = true;
	}

	return array(
		'title'    => $title,
		'desc'     => $desc,
		'text'     => $title . ' ' . $desc . ' ' . $body,
		'text_len' => mb_strlen( $body ),
		'signals'  => array_unique( $signals ),
		'rss'      => $rss,
		'date_ts'  => riven_health_find_date( $body . ' ' . $html ),
		'shell'    => $shell,
		'jump'     => riven_health_find_redirect( $html, $base_url ),
	);
}

/**
 * 判定与落库的统一入口（2026-10-16 抽出）
 *
 * 为什么要有这个函数：服务器端扫描（`riven_health_scan_batch`）和
 * **本机抓取助手**（把页面特征 POST 回来）都要走同一套判定 ——
 * 否则同一个域名，服务器判"正常"、本机判"风险"，站长就没法信任任何结论。
 *
 * 输入是"已经从页面里提取好的特征"，所以本机助手不需要复刻词库与规则，
 * 它只负责抓 + 提取，判定全在这里做。
 *
 * @param string $host     归一化后的域名（不含 www.）
 * @param string $title    页面标题
 * @param string $text     标题 + 描述 + 正文（用于打分）
 * @param int    $text_len 去标签正文的真实长度（用于"内容极短"规则）
 * @param array  $signals  博客特征（rss / cms / path / generator / title）
 * @param int    $date_ts  页面里解析出的最新时间戳（0 = 没读到）
 * @return array status / score / is_blog
 */

/**
 * 标成「看不清」（fail）
 *
 * 用在两种"抓到的根本不是站点本身"的场景：错误页（404 / 站点暂停）、
 * 以及**没有跳转目标**的空壳页。语义上等同于"抓不到"：
 *   · 前台：fail 不参与任何降级判定（is_risk / is_dead / is_nonpersonal 全不命中）→ 放行
 *   · 旧结论保护：这个域名**之前已实锤** risk/junk 的，绝不能因为这次看不见就洗白，
 *     只把 checked_at 往后推（沿用 1.9.1 起的老规矩）。
 *
 * 为什么需要它（2026-10-17 实测）：shitoucuo.com 服务器端拿到的是一张 138 字节的
 * nginx 404 页，**HTTP 状态码还是 200**，骗过了抓取层。它既没有博客特征、也没被判
 * junk，于是以「无博客特征」的身份触发了「已经不是个人站」→ 被降级。
 * 等于让"抓不到"绕个弯又变成了降级理由，违背"看不清不能作为降级依据"的铁律。
 *
 * @param string $host
 * @param string $title
 * @param string $reason 'errorpage' | 'shell'
 * @return array
 */
function riven_health_mark_unclear( $host, $title, $reason ) {
	$prev = riven_health_row( $host );
	if ( $prev && in_array( (string) $prev->status, array( 'risk', 'junk' ), true ) ) {
		global $wpdb;
		$wpdb->update(
			riven_health_table(),
			array( 'checked_at' => current_time( 'mysql' ), 'fail_reason' => $reason ),
			array( 'host' => $host ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		return array( 'status' => (string) $prev->status, 'score' => (int) $prev->score, 'is_blog' => (int) $prev->is_blog );
	}

	riven_health_save(
		$host,
		array(
			'status'      => 'fail',
			'score'       => 0,
			'is_blog'     => 0,
			'signals'     => array(),
			'updated_at'  => null,
			'hits'        => '',
			'title'       => (string) $title,
			'fail_reason' => $reason,
		)
	);
	return array( 'status' => 'fail', 'score' => 0, 'is_blog' => 0 );
}

function riven_health_finalize( $host, $title, $text, $text_len, $signals, $date_ts, $shell = false, $jump = '' ) {
	// ---- 先排除"这根本不是站点自己的页面"（2026-10-17，1.9.4）----
	// ① 错误页：站点被暂停 / nginx·CDN 的 404·403·5xx 页面。这些页面 HTTP 状态码
	//    常常还报 200，抓取层拦不住，只能靠内容认。
	// ② 空壳页且**没有跳转目标**：只剩骨架的 JS 站，或抓取只拿到半页 —— 一样是看不清。
	//    带跳转的空壳页不在此列（域名停靠商的惯用手法），那是实打实的"已废弃"。
	if ( $text_len < 800 ) {
		$probe = mb_substr( (string) preg_replace( '#\s+#u', ' ', (string) $text ), 0, 600 );
		if ( preg_match( '#(404\s*not\s*found|403\s*forbidden|500\s*internal\s*server|502\s*bad\s*gateway|503\s*service\s*(temporarily\s*)?unavailable|504\s*gateway|bad\s*gateway|account\s+suspended|site\s+suspended|this\s+site\s+is\s+(temporarily\s+)?unavailable|has\s+been\s+suspended|网站?已?暂停|站点已?\s*(暂停|关闭|停止)|该?(站点|网站|域名)已\s*(暂停|关闭|停止|过期)|域名已过期)#iu', $probe ) ) {
			return riven_health_mark_unclear( $host, $title, 'errorpage' );
		}
	}
	if ( $shell && '' === trim( (string) $jump ) ) {
		return riven_health_mark_unclear( $host, $title, 'shell' );
	}

	$threshold = riven_health_threshold();
	list( $score, $hits ) = riven_health_score( (string) $text . ' ' . implode( ' ', (array) $signals ), (string) $title );

	// 正文极短：正常博客首页不会这么空（实测几个漏网的黄赌站都是这个特征）
	if ( $text_len < 120 && $score > 0 ) {
		$score = min( 80, $score + 20 );
		$hits[] = 'rule:thin';
	}

	$is_blog = ! empty( $signals ) ? 1 : 0;

	/*
	 * 1.3.0：有 cms 信号 ≠ 个人站（2026-10-17 老张报 58yqm.com 的根因）
	 *
	 * 58yqm.com 是用 WordPress 搭的中币交易所，cms 信号让它一路被算成"博客"，
	 * 于是「不是个人站」那条判据永远碰不到它 —— 不管标题写的是
	 * 「ZB中币网 | 中币网官网入口_中币区块链_交易所_交易平台」。
	 *
	 * 现在：标题是商业站点句式时，一律不算博客。**status 仍然是 ok**
	 * （它没到风险阈值，不该冤枉成黄赌），但前台会走「不是个人站」→ 降级。
	 */
	$rcg_plat_why = '';
	if ( $is_blog && riven_health_title_is_commercial( (string) $title, $rcg_plat_why ) ) {
		$is_blog = 0;
		$hits[]  = 'platform:' . $rcg_plat_why;
	}

	$status  = $score >= $threshold ? 'risk' : 'ok';

	// 空壳 / 域名停靠页（2026-10-17）：整页没有正文、只看得到一行跳转脚本。
	// 得分必然是 0，但它的性质就是"这个域名已经不是谁的家了" → 归到 junk（已废弃），
	// 前台降级、后台单独列。若它同时命中风险词，则按 risk 处理（风险优先）。
	// 走到这儿说明 $shell **一定带跳转目标** —— 没跳转的空壳已在函数开头按
	// "看不清"退场（riven_health_mark_unclear），免得 JS 站被当成停靠页。
	//
	// 1.3.3 补一刀（老张报 airy.ink「瓦匠不舟」/ hahaha.cc「好事发生」误判）：
	// **标题是人写的，就不是域名待售页**。正常 JS 博客的 HTML 也没正文、体量也小，
	// 拿"页面小"当废弃证据本来就不成立；只有标题也是域名商模板风格时才判 junk。
	// 错误页（404/502）单独摘出来算"看不清"——站点此刻出问题 ≠ 域名被卖掉。
	$shell_junk = false;
	if ( 'risk' !== $status && $shell ) {
		if ( ! riven_health_title_is_shell_template( $title, $host ) ) {
			return riven_health_mark_unclear( $host, $title, 'shell' );
		}
		if ( riven_health_title_is_error_page( $title ) ) {
			return riven_health_mark_unclear( $host, $title, 'errorpage' );
		}
		$status     = 'junk';
		$shell_junk = true;
	}

	// 域名待售 / 停靠页：不是黄赌，但域名早就不属于原博主了 → 单独一类。
	// 前台处理和"风险"一样（降级），后台分开列，老张一眼能分清。
	if ( 'risk' !== $status ) {
		foreach ( $hits as $_hit ) {
			if ( 0 === strpos( (string) $_hit, 'junk:' ) ) {
				$status = 'junk';
				break;
			}
		}
		// 另一种停靠页：标题就是域名本身、页面几乎没有内容
		// （"[域名] is for sale" 那种模板页，正文只有一两句）。
		// 2026-10-16 实测：zhangbo.org / ludou.org / loststop.com 都是这一类。
		if ( 'junk' !== $status && '' !== trim( (string) $title ) ) {
			$tt = mb_strtolower( trim( (string) $title ) );
			$tt = preg_replace( '#^https?://#', '', $tt );
			$tt = rtrim( (string) preg_replace( '#^www\.#', '', $tt ), '/' );
			if ( $tt === $host && $text_len < 600 ) {
				$status = 'junk';
			}
		}
	}

	riven_health_save(
		$host,
		array(
			'status'      => $status,
			'score'       => $score,
			'is_blog'     => $is_blog,
			'signals'     => (array) $signals,
			'updated_at'  => $date_ts ? gmdate( 'Y-m-d H:i:s', $date_ts ) : null,
			'hits'        => implode( ',', array_slice( $hits, 0, 12 ) ),
			// 空壳页没有标题，后台清单里空着会让人看不懂 —— 留个标记
			'title'       => ( $shell_junk && '' === trim( (string) $title ) ) ? '[空壳/停靠页]' : (string) $title,
			'fail_reason' => '',
		)
	);

	return array( 'status' => $status, 'score' => $score, 'is_blog' => $is_blog );
}

/** 从一段文本里找最新日期（要带月日，避免把 © 2026 这种版权年份当成更新） */
function riven_health_find_date( $text ) {
	$best = 0;
	$floor = strtotime( '2000-01-01' );
	$ceil  = time() + DAY_IN_SECONDS;

	if ( preg_match_all( '#(20\d{2})[-/\.](\d{1,2})[-/\.](\d{1,2})#', $text, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			$ts = strtotime( sprintf( '%04d-%02d-%02d', $x[1], $x[2], $x[3] ) );
			if ( $ts && $ts > $floor && $ts < $ceil && $ts > $best ) {
				$best = $ts;
			}
		}
	}
	if ( preg_match_all( '#(20\d{2})年(\d{1,2})月(\d{1,2})日#u', $text, $m2, PREG_SET_ORDER ) ) {
		foreach ( $m2 as $x ) {
			$ts = strtotime( sprintf( '%04d-%02d-%02d', $x[1], $x[2], $x[3] ) );
			if ( $ts && $ts > $floor && $ts < $ceil && $ts > $best ) {
				$best = $ts;
			}
		}
	}
	// RFC822 形式（RSS 的 pubDate）：Wed, 17 Sep 2026 00:00:00 +0800
	// 2026-10-16 补：原来只认 yyyy-mm-dd，导致 RSS 里的日期解析不出来，
	// 只好去捡 RSS 正文里其它形如日期的残留值（imzl.com 就被捡到个 2022-01-26）。
	if ( preg_match_all( '#\b(\d{1,2})\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(20\d{2})\b#i', $text, $m3, PREG_SET_ORDER ) ) {
		foreach ( $m3 as $x ) {
			$ts = strtotime( $x[1] . ' ' . $x[2] . ' ' . $x[3] );
			if ( $ts && $ts > $floor && $ts < $ceil && $ts > $best ) {
				$best = $ts;
			}
		}
	}
	return $best;
}

/* ------------------------------------------------------------------ 落库 */

function riven_health_save( $host, $row ) {
	if ( ! riven_health_table_exists() ) {
		return;
	}
	global $wpdb;
	$t = riven_health_table();
	$wpdb->replace(
		$t,
		array(
			'host'        => $host,
			'status'      => (string) $row['status'],
			'score'       => (int) $row['score'],
			'is_blog'     => (int) $row['is_blog'],
			'signals'     => implode( ',', (array) $row['signals'] ),
			'updated_at'  => $row['updated_at'] ? $row['updated_at'] : null,
			'checked_at'  => current_time( 'mysql' ),
			'hits'        => (string) $row['hits'],
			'title'       => mb_substr( (string) $row['title'], 0, 200 ),
			'fail_reason' => (string) $row['fail_reason'],
		),
		array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	// 判定结果变了，请求内缓存与 host 列表都要刷新
	delete_transient( 'riven_health_hosts' );
	$cache = riven_health_cache();
	unset( $cache->rows[ $host ] );
}

/**
 * 域名能不能解析（带缓存）。
 *
 * 为什么需要它：`gethostbyname()` 没有超时参数，走系统 resolver，
 * 一个彻底死掉的域名能让进程卡 5~10 秒。一批里死域名多的时候，
 * 光这一步就能把单请求拖过 fpm 的时限 → 白屏（2026-10-17 老张踩到）。
 * 加缓存后同一个域名短时间内只查一次，扫描耗时才可控。
 *
 * 能解析缓存 7 天，不能解析只缓存 1 天 —— 域名可能被重新注册，
 * 别让「解析不了」这个结论粘太久把人误降级。
 *
 * @param string $host      主机名。
 * @param bool   $strip_www 是否去掉开头的 www.（查「www 有没有 A 记录」时必须保留）。
 * @return bool
 */
function rcg_host_resolves( $host, $strip_www = true ) {
	$host = strtolower( (string) $host );
	if ( $strip_www ) {
		$host = preg_replace( '/^www\./', '', $host );
	}
	if ( '' === $host ) {
		return false;
	}
	$key = 'rcg_dns_' . md5( $host );
	$hit = get_transient( $key );
	if ( 'y' === $hit ) {
		return true;
	}
	if ( 'n' === $hit ) {
		return false;
	}

	$ip   = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$ok   = ( $ip !== $host && (bool) filter_var( $ip, FILTER_VALIDATE_IP ) );
	set_transient( $key, $ok ? 'y' : 'n', $ok ? 7 * DAY_IN_SECONDS : DAY_IN_SECONDS );
	return $ok;
}

/**
 * 扫一批。返回统计数组。
 *
 * @param int|null $limit 不传则用后台设置
 * @param bool     $force true = 忽略轮转间隔，强制扫"最久没检的"；
 *                        false = 只扫到期的（连续扫描/自动轮转用，这样才会收敛）
 * @param string   $since $force 时必传：本次重扫的起始时间（站点本地 MySQL 时间）。
 *                        只有 checked_at 早于它的域名才会被扫 —— 这是 force 模式
 *                        能跑完并停下的关键，否则永远能取到"最久没检的"，无限循环。
 */
function riven_health_scan_batch( $limit = null, $force = false, $since = '', $only_hosts = null ) {
	global $wpdb;
	$limit = $limit ? absint( $limit ) : riven_health_batch();
	$limit = $limit > 0 ? $limit : riven_health_batch();

	/*
	 * 单批上限 60（2026-10-17 老张要求"批量大小让我自己设"）。
	 *
	 * 1.1.6 曾把上限焊死 15，是因为一批 60 个会撞 php-fpm 的 100 秒时限
	 * 被杀掉、浏览器白屏。现在靠**墙钟预算**兜底（见下面 $deadline）：
	 * 取不完的域名不写库、留在队首，下一批继续。所以批大小只影响推进速度，
	 * 不再影响"会不会超时被杀"。
	 */
	$limit = min( $limit, 60 );

	// 尽力而为：fpm 自己的时限不走 max_execution_time，但能覆盖 PHP 层的限制。
	@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	$t_start  = microtime( true );
	$deadline = $t_start + riven_health_request_budget();

	if ( ! riven_health_table_exists() ) {
		riven_health_install();
		if ( ! riven_health_table_exists() ) {
			return array( 'error' => 'db', 'scanned' => 0 );
		}
	}

	// 「扫描复核」模式（1.3.4）：只扫指定清单内的域名，强制扫（无视轮转间隔），
	// 靠 $since 收敛 —— 多点几次按钮不会把同一批反复扫。
	$pq_total = 0;
	$queue    = riven_health_pending(
		$limit,
		$force || ( null !== $only_hosts ),
		$since,
		$pq_total,
		$only_hosts
	);
	if ( empty( $queue ) ) {
		return array( 'scanned' => 0, 'risk' => 0, 'ok' => 0, 'fail' => 0, 'remaining' => 0, 'done' => true );
	}

	$urls = array();
	foreach ( $queue as $q ) {
		$urls[] = $q['url'];
	}

	$timeout = riven_health_timeout();
	// 墙钟预算：超时就不再开新组，没抓到的域名不出现在 $res 里 → 下面跳过、不写库。
	$res = riven_health_fetch_many( $urls, $timeout, 20, $deadline );

	$stat = array( 'scanned' => 0, 'risk' => 0, 'junk' => 0, 'ok' => 0, 'fail' => 0, 'recoverable' => 0 );
	$threshold = riven_health_threshold();
	$rss_queue  = array();
	$jump_queue = array();   // 空壳页的跳转目标（第三轮去跟）
	$res_of     = array();   // 每个域名本轮判成了什么（跟跳后要比较）

	// DNS 复核的次数上限：每查一次最坏 5~10 秒，不能跟着域名数量线性涨。
	// 超额的这轮按 noresponse（= 我们看不见，不降级）处理，宁可漏判也不误伤。
	$dns_checks = 0;
	$dns_cap    = 5;

	foreach ( $queue as $q ) {
		$h = $q['host'];
		$u = $q['url'];
		// 时间预算用完、这轮压根没去抓它 —— 绝不能当成"抓不到"写库。
		// 不碰 checked_at，它下一批仍在队首，既不漏判也不会被误降级。
		if ( ! isset( $res[ $u ] ) ) {
			continue;
		}
		$f = $res[ $u ];

		if ( ! $f['ok'] ) {
			$reason = $f['reason'];
			// 复核一次 DNS：解析不了 = 域名真死（点开必是空页面，判定时要降级）；
			// 能解析 = 站还活着、只是从这台服务器连不上（多半是国内站），不动它。
			// DNS 复核要留出余量：只剩 20 秒就别再查了（一次最坏 10 秒）。
			// 2026-10-17 实测：批 60 时 fetch 用 30 秒 + 这里串行查 5 次 = 整批 68 秒，
			// 虽然没撞 fpm 时限但也够悬，所以 DNS 窗口改成跟 deadline 挂钩。
			if ( 'noresponse' === $reason && $dns_checks < $dns_cap && microtime( true ) < $deadline - 20 ) {
				$dns_checks++;
				if ( ! rcg_host_resolves( $h ) ) {
					$reason = 'dns';
				}
			}
			// 抓不到 ≠ 洗白（2026-10-16 修）
			// 如果之前已经实锤它是风险站 / 已废弃域名，这次只是连不上 —— **绝不能覆盖**。
			// 实测教训：resobang.cn（算命站）被判 risk 后，某次重扫抓不到就退回 fail，
			// 而 fail 在新判据下是放行的 → 风险链接会重新变回可点击。
			$prev = riven_health_row( $h );
			if ( $prev && in_array( (string) $prev->status, array( 'risk', 'junk' ), true ) ) {
				global $wpdb;
				$wpdb->update(
					riven_health_table(),
					array( 'checked_at' => current_time( 'mysql' ), 'fail_reason' => $reason ),
					array( 'host' => $h ),
					array( '%s', '%s' ),
					array( '%s' )
				);
				$stat['fail']++;
				$stat['scanned']++;
				continue;
			}

			riven_health_save(
				$h,
				array(
					'status'      => 'fail',
					'score'       => 0,
					'is_blog'     => 0,
					'signals'     => array(),
					'updated_at'  => null,
					'hits'        => '',
					'title'       => '',
					'fail_reason' => $reason,
				)
			);
			$stat['fail']++;
			$stat['scanned']++;
			continue;
		}

		$a   = riven_health_analyze_html( $f['body'], $u );
		// 判定与落库统一走 riven_health_finalize()，本机抓取助手也调它 —— 两边口径必然一致
		// 注意变量名：$res 在上面是**整批抓取结果**，这里的结果另有其名，
		// 否则第一个域名判完就把抓取结果覆盖了 —— 同批后续域名全部变成
		// "miss（抓不到）"，一次重扫能把几十个正常域名打成 fail（2026-10-17 踩过）。
		$vd = riven_health_finalize( $h, $a['title'], $a['text'], $a['text_len'], $a['signals'], $a['date_ts'], ! empty( $a['shell'] ), isset( $a['jump'] ) ? (string) $a['jump'] : '' );
		$res_of[ $h ] = $vd['status'];

		// 空壳页带了跳转 → 排进第三轮，跟过去看它到底是待售页还是黄赌站
		if ( ! empty( $a['shell'] ) && ! empty( $a['jump'] ) ) {
			$jump_queue[ $h ] = $a['jump'];
		}

		$stat['scanned']++;
		if ( 'risk' === $vd['status'] ) {
			$stat['risk']++;
		} elseif ( 'junk' === $vd['status'] ) {
			$stat['junk']++;
		} else {
			$stat['ok']++;
			// 有 RSS 且是博客：下一轮拿准确日期
			if ( $a['rss'] && $vd['is_blog'] ) {
				$rss_queue[ $h ] = $a['rss'];
			}
		}
	}

	// 第二轮：抓 RSS 拿最新文章日期（只对有 RSS 的，通常几十个）
	if ( ! empty( $rss_queue ) && microtime( true ) < $deadline - 5 ) {
		$rres = riven_health_fetch_many( array_values( $rss_queue ), min( 10, $timeout ), 20, $deadline );
		foreach ( $rss_queue as $h => $u ) {
			if ( empty( $rres[ $u ]['ok'] ) ) {
				continue;
			}
			$ts_rss = riven_health_find_date( $rres[ $u ]['body'] );
			if ( ! $ts_rss ) {
				continue;
			}
			// 取「首页日期」与「RSS 日期」中较新的那个 —— 不要无条件覆盖。
			// 2026-10-16 修：原来直接拿 RSS 日期盖掉首页日期，结果 imzl.com 首页
			// 明明解析出 2026-09-17，却被 rss.xml 里一个 2022-01-26 的残留值覆盖，
			// 一个正常更新的博客被判成"停更 4 年"、链接被屏蔽（老张实测发现）。
			$cur    = $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM " . riven_health_table() . " WHERE host = %s", $h ) );
			$cur_ts = $cur ? (int) strtotime( (string) $cur ) : 0;
			if ( $ts_rss > $cur_ts ) {
				$wpdb->update(
					riven_health_table(),
					array( 'updated_at' => gmdate( 'Y-m-d H:i:s', $ts_rss ) ),
					array( 'host' => $h ),
					array( '%s' ),
					array( '%s' )
				);
			}
		}
	}

	// 第三轮：跟进空壳页的跳转目标（2026-10-17）
	// 域名停靠商的首页往往只有一行 `window.location.href="/lander"`，
	// 不跟这一跳，就只能看到一个空白页 —— 也就判不出它是不是已经变成待售页，
	// 更看不出它有没有被转手挂上黄赌内容。
	if ( ! empty( $jump_queue ) && microtime( true ) < $deadline - 5 ) {
		$jres = riven_health_fetch_many( array_values( $jump_queue ), $timeout, 20, $deadline );
		foreach ( $jump_queue as $h => $ju ) {
			if ( empty( $jres[ $ju ]['ok'] ) ) {
				continue;   // 跟不过去就保持"空壳 → 已废弃"的结论，不动
			}
			$b  = riven_health_analyze_html( $jres[ $ju ]['body'], $ju );
			$nf = riven_health_finalize( $h, $b['title'], $b['text'], $b['text_len'], $b['signals'], $b['date_ts'], ! empty( $b['shell'] ), isset( $b['jump'] ) ? (string) $b['jump'] : '' );

			// 判定可能变了（比如跳到一个黄赌站），把统计修正过来
			if ( $nf['status'] !== $res_of[ $h ] ) {
				foreach ( array( $res_of[ $h ], $nf['status'] ) as $k => $st ) {
					$key = ( 'risk' === $st || 'junk' === $st ) ? $st : 'ok';
					if ( 0 === $k ) {
						$stat[ $key ]--;
					} else {
						$stat[ $key ]++;
					}
				}
			}
		}
	}

	delete_transient( 'riven_health_hosts' );
	$cache = riven_health_cache();
	$cache->rows = array();

	// "还剩多少"：
	// · 普通轮转（false）= 到期数（没检过的 + 超过 TTL 的），收敛到 0 就停；
	// · 全站重扫（force）= 本次会话里还没重扫的（checked_at < $since），同样收敛到 0。
	//   —— 2026-10-17 老张的"全站重扫跑了一小时不停"就是因为 force 也拿到期数当剩余，
	//   而 force 自己不看 TTL，永远都有"最久没检的"可扫，剩余却始终显示 0。
	if ( null !== $only_hosts ) {
		// 「扫描复核」：剩余 = 清单里还没扫过的（total 减去本批实际处理的）
		$left = max( 0, (int) $pq_total - $stat['scanned'] );
	} elseif ( $force && '' !== $since ) {
		$all   = riven_health_collect_hosts();
		$left  = empty( $all ) ? 0 : count( riven_health_pending( count( $all ), true, $since ) );
	} else {
		$left = riven_health_due_count();
	}
	// 实测速度：每个域名平均要几秒（滑动平均）。
	// 2026-10-17 老张反馈：按钮上说"2~8 分钟"，实际跑了半小时 —— 因为耗时完全取决于
	// 当批里有几个死域名（每个都要等满 timeout，再加 DNS 复核/www 兜底）。
	// 与其拍脑袋写死一个区间，不如把真实速度记下来，进度条按它算 ETA。
	$elapsed = microtime( true ) - $t_start;
	if ( $stat['scanned'] > 0 && $elapsed > 0.5 ) {
		$per  = $elapsed / $stat['scanned'];
		$prev = (float) get_option( 'rcg_scan_per_item', 0 );
		$avg  = $prev > 0 ? ( $prev * 0.7 + $per * 0.3 ) : $per;
		update_option( 'rcg_scan_per_item', round( $avg, 3 ), false );
	}

	$stat['total']     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . riven_health_table() );
	$stat['remaining'] = (int) $left;
	$stat['done']      = ( 0 === $stat['remaining'] );

	return $stat;
}

/* ------------------------------------------------------ 前台黑名单拦截 */

/**
 * 评论作者网址命中黑名单 → 直接拒绝提交
 *
 * 只匹配"明确拉黑"的域名，白名单优先，避免误伤正常访客。
 *
 * 1.4.1 增强：**正文里的链接也查**。原来只看表单网址，于是有人表单填自己的正常站、
 * 正文里塞一条赌链，照样提交成功。现在两处都查，任一命中就拒绝。
 */
function riven_health_block_comment( $approved, $commentdata ) {
	if ( ! riven_health_block_on() ) {
		return $approved;
	}
	// 站长/编辑自己发的，不拦
	if ( ! empty( $commentdata['user_id'] ) || ( function_exists( 'current_user_can' ) && current_user_can( 'moderate_comments' ) ) ) {
		return $approved;
	}
	$url = isset( $commentdata['comment_author_url'] ) ? (string) $commentdata['comment_author_url'] : '';
	$txt = isset( $commentdata['comment_content'] ) ? (string) $commentdata['comment_content'] : '';
	if ( '' === trim( $url ) && '' === trim( $txt ) ) {
		return $approved;
	}

	// 收集所有要检查的域名：表单网址 + 正文里的链接
	$hosts = array();
	if ( '' !== trim( $url ) ) {
		$hosts[] = $url; // is_blacklisted 内部会归一化
	}
	if ( '' !== trim( $txt ) && preg_match_all( '#https?://[^\s<>"\'()]+#i', $txt, $m ) ) {
		foreach ( $m[0] as $u ) {
			$hosts[] = $u;
		}
	}

	foreach ( array_unique( $hosts ) as $h ) {
		if ( riven_health_is_blacklisted( $h ) ) {
			// 1.4.1：提示语为空时回退到默认文案 —— 否则 wp_die 会弹一个空白页，
			// 访客完全不知道发生了什么。
			$msg = trim( (string) rcg_opt( 'health_block_message', '' ) );
			if ( '' === $msg ) {
				$msg = '你填写的网址已被本站列入黑名单，评论无法提交。如有疑问请联系站长。';
			}
			wp_die(
				esc_html( $msg ),
				esc_html( '评论提交被拒绝' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}
	}
	return $approved;
}

/* -------------------------------------------------------- 同一邮箱多域名 */

/**
 * 候选：同一个邮箱先后填过多个域名（博友换了站）
 *
 * @return array email => array( host => array('last'=>'Y-m-d H:i:s','count'=>n) )
 */
function riven_health_email_hosts() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT LOWER(comment_author_email) AS e, comment_author_url AS u,
		        COUNT(*) AS c, MAX(comment_date) AS last
		 FROM {$wpdb->comments}
		 WHERE comment_approved IN ('0','1') AND comment_author_email <> '' AND comment_author_url <> ''
		 GROUP BY e, u"
	);
	$map = array();
	foreach ( (array) $rows as $r ) {
		$e = trim( (string) $r->e );
		if ( ! is_email( $e ) ) {
			continue;
		}
		$h = riven_link_guard_host_key( (string) wp_parse_url( (string) $r->u, PHP_URL_HOST ) );
		if ( '' === $h || strlen( $h ) < 4 || false === strpos( $h, '.' ) ) {
			continue;
		}
		if ( ! isset( $map[ $e ][ $h ] ) ) {
			$map[ $e ][ $h ] = array( 'last' => (string) $r->last, 'count' => 0 );
		}
		$map[ $e ][ $h ]['count'] += (int) $r->c;
		if ( $r->last > $map[ $e ][ $h ]['last'] ) {
			$map[ $e ][ $h ]['last'] = (string) $r->last;
		}
	}
	// 只留多域名的
	$out = array();
	foreach ( $map as $e => $hosts ) {
		if ( count( $hosts ) >= 2 ) {
			$out[ $e ] = $hosts;
		}
	}
	return $out;
}

/**
 * 换域名候选：按「该不该动手」排序，不是按邮箱字母序
 *
 * 1.11.2：老张说 211 个候选只显示前 60 个。原来是按 SQL 返回顺序（≈邮箱字母序）
 * 截前 60 —— 排在 61 位之后的，可能恰恰是旧域名已经变成黄赌站的那种。
 * 现在按严重程度分层，最该处理的排最前：
 *   0 风险（旧域名已被实锤黄赌/诈骗）
 *   1 待售（域名商停靠页）
 *   2 已失效（dns / 404 / 410，前台已是纯文本）
 *   3 抓不到（noresponse / 403，我们看不见，未必是坏）
 *   4 库里没记录
 *   5 两个域名都正常（多半是他自己有好几个站，不用管）
 *
 * @return array array( 'email'=>, 'hosts'=>按时间倒序, 'tags'=>host=>标签, 'rank'=>, 'last'=> )
 */
function riven_health_email_candidates() {
	static $rows = null;
	if ( null !== $rows ) {
		return $rows;
	}
	$map = riven_health_email_hosts();
	$st  = riven_health_status_map();
	$rows = array();
	foreach ( $map as $em => $hosts ) {
		uasort(
			$hosts,
			function ( $a, $b ) {
				return strcmp( (string) $b['last'], (string) $a['last'] );
			}
		);
		$worst = 5;
		$last  = '';
		$tags  = array();
		foreach ( $hosts as $h => $meta ) {
			$s   = isset( $st[ $h ] ) ? $st[ $h ] : null;
			$tag = '';
			$rk  = 4;
			if ( $s ) {
				if ( 'risk' === $s['status'] ) {
					$tag = 'risk';
					$rk  = 0;
				} elseif ( 'junk' === $s['status'] ) {
					$tag = 'junk';
					$rk  = 1;
				} elseif ( 'fail' === $s['status'] ) {
					if ( in_array( (string) $s['reason'], array( 'dns', 'http404', 'http410' ), true ) ) {
						$tag = 'dead';
						$rk  = 2;
					} else {
						$tag = 'blind';
						$rk  = 3;
					}
				} else {
					$rk = 5;
				}
			}
			$tags[ $h ] = $tag;
			if ( $rk < $worst ) {
				$worst = $rk;
			}
			if ( (string) $meta['last'] > $last ) {
				$last = (string) $meta['last'];
			}
		}
		$rows[] = array(
			'email' => $em,
			'hosts' => $hosts,
			'tags'  => $tags,
			'rank'  => $worst,
			'last'  => $last,
		);
	}
	usort(
		$rows,
		function ( $a, $b ) {
			if ( $a['rank'] !== $b['rank'] ) {
				return $a['rank'] - $b['rank'];
			}
			return strcmp( $b['last'], $a['last'] );   // 同层里最近来过的排前
		}
	);
	return $rows;
}

/**
 * 换域名候选里的状态小标签（只用于后台显示）
 */
function riven_health_candidate_tag_html( $tag ) {
	$map = array(
		'risk'     => array( '风险', '#fcf0f1', '#d63638' ),
		'junk'     => array( '待售', '#fcf9e8', '#996800' ),
		'dead'     => array( '已失效', '#f0f0f1', '#50575e' ),
		'blind'    => array( '抓不到', '#f6f7f7', '#8c8f94' ),
	);
	if ( ! isset( $map[ $tag ] ) ) {
		return '';
	}
	list( $label, $bg, $fg ) = $map[ $tag ];
	return '<span style="display:inline-block;padding:0 6px;margin-left:4px;border-radius:3px;font-size:11px;line-height:18px;background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $fg . '33">' . esc_html( $label ) . '</span>';
}

/**
 * 把评论作者网址里的旧域名批量换成新域名
 *
 * 这是本模块**唯一会改数据库**的操作，所以：
 *   ① 只改 comment_author_url（不碰正文、不删评论）；
 *   ② 改之前把原值写进备份表，支持一键撤销；
 *   ③ 严格校验两个域名，拒绝 http/www 之类的通用词。
 *
 * @return array(ok, changed, msg)
 */
function riven_health_replace_host( $from, $to ) {
	$from = riven_link_guard_host_key( $from );
	$to   = riven_link_guard_host_key( $to );
	$bad  = array( '', 'http', 'https', 'www', 'ftp' );
	if ( in_array( $from, $bad, true ) || in_array( $to, $bad, true )
		|| strlen( $from ) < 4 || strlen( $to ) < 4
		|| false === strpos( $from, '.' ) || false === strpos( $to, '.' ) ) {
		return array( 'ok' => false, 'changed' => 0, 'msg' => '域名不合法（必须填完整域名，如 abc.com）' );
	}
	if ( $from === $to ) {
		return array( 'ok' => false, 'changed' => 0, 'msg' => '新旧域名相同' );
	}

	global $wpdb;
	$like = '%' . $wpdb->esc_like( $from ) . '%';
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comment_ID, comment_author_url FROM {$wpdb->comments}
			 WHERE comment_author_url <> '' AND comment_author_url LIKE %s",
			$like
		)
	);
	if ( empty( $rows ) ) {
		return array( 'ok' => false, 'changed' => 0, 'msg' => '没有找到填了这个域名的评论' );
	}

	$b_t = riven_health_backup_table();
	$bt  = riven_health_table();
	$now = current_time( 'mysql' );
	$changed = 0;

	foreach ( $rows as $r ) {
		$old = (string) $r->comment_author_url;
		// 精确位置替换：只换 host 部分
		$new = preg_replace(
			'#^(https?://[^/]*?)' . preg_quote( $from, '#' ) . '#i',
			'$1' . $to,
			$old
		);
		if ( ! $new || $new === $old ) {
			// 兜底：整串替换
			$new = str_ireplace( $from, $to, $old );
			if ( $new === $old ) {
				continue;
			}
		}
		$wpdb->insert(
			$b_t,
			array( 'comment_id' => (int) $r->comment_ID, 'old_url' => $old, 'new_url' => $new, 'batch' => $now ),
			array( '%d', '%s', '%s', '%s' )
		);
		$wpdb->update( $wpdb->comments, array( 'comment_author_url' => $new ), array( 'comment_ID' => (int) $r->comment_ID ), array( '%s' ), array( '%d' ) );
		$changed++;
	}

	// 体检记录跟着搬（旧域名的结论对新域名也有参考价值，但更稳妥是标记为待重检）
	if ( riven_health_table_exists() ) {
		$old_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE host = %s", $from ), ARRAY_A );
		if ( $old_row ) {
			$old_row['host']       = $to;
			$old_row['checked_at'] = '1970-01-01 00:00:00'; // 立刻重检
			$wpdb->replace( $bt, $old_row );
		}
	}

	delete_transient( 'riven_health_hosts' );
	$cache = riven_health_cache();
	$cache->rows = array();

	return array( 'ok' => true, 'changed' => $changed, 'msg' => sprintf( '已替换 %d 条评论的作者网址', $changed ) );
}

/** 备份表（换域名的撤销依据） */
function riven_health_backup_table() {
	global $wpdb;
	$t = $wpdb->prefix . 'riven_link_urlbackup';
	if ( ! get_option( 'riven_health_backup_ready' ) ) {
		$charset = $wpdb->get_charset_collate();
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				comment_id bigint(20) unsigned NOT NULL,
				old_url text NOT NULL,
				new_url text NOT NULL,
				batch varchar(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY batch_idx (batch)
			) {$charset};"
		);
		update_option( 'riven_health_backup_ready', 1, false );
	}
	return $t;
}

/** 撤销最近一次换域名 */
function riven_health_undo_replace() {
	global $wpdb;
	$t     = riven_health_backup_table();
	$batch = $wpdb->get_var( "SELECT batch FROM {$t} ORDER BY id DESC LIMIT 1" );
	if ( ! $batch ) {
		return array( 'ok' => false, 'restored' => 0, 'msg' => '没有可撤销的记录' );
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_id, old_url FROM {$t} WHERE batch = %s", $batch ) );
	$n    = 0;
	foreach ( (array) $rows as $r ) {
		$wpdb->update( $wpdb->comments, array( 'comment_author_url' => $r->old_url ), array( 'comment_ID' => (int) $r->comment_id ), array( '%s' ), array( '%d' ) );
		$n++;
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE batch = %s", $batch ) );
	delete_transient( 'riven_health_hosts' );
	$cache = riven_health_cache();
	$cache->rows = array();
	return array( 'ok' => true, 'restored' => $n, 'msg' => sprintf( '已撤销 %d 条', $n ) );
}

/* -------------------------------------------------------------- 白名单 */

/**
 * 人工白名单：站长手动放行的域名（老友、自己的站、确定没问题但机器看不清的）
 *
 * 规矩：白名单优先于**关系判定**，但**不优先于体检实锤的风险** ——
 * 万一站长手滑把一个真黄站加进白名单，也不会因此放行。
 *
 * ⚠️ 1.4.0 起白名单**带 TTL**（默认 30 天，到期重新接受检测）。
 * 参考 nickel-comment-link-guard 的设计理念："域名随时可能会被弃用" ——
 * 我们 1.3.3 为了防"自动轮转把刚恢复的站扫回 junk"，把白名单域名永久剔除出抓取队列，
 * 等于让"误判·恢复"变成一次性永久免检：老张今天恢复一个站，明年它变成赌站也没人知道。
 *
 * 存储格式（1.4.0 起）：`array( 'host' => '加入时间(MySQL)' )`。
 * 旧格式（`array( 'host1', 'host2'`）自动兼容：没有时间戳的按"永不过期"处理，
 * 但一旦重新 add 就会带上时间戳。这样升级不丢数据，也不会让老站突然全部到期。
 *
 * @return array host => added_at（added_at 为 '' 表示旧数据/永不过期）
 */
function riven_health_whitelist() {
	$list = rcg_opt( 'health_whitelist', array() );
	if ( ! is_array( $list ) ) {
		return array();
	}
	$out = array();
	foreach ( $list as $k => $v ) {
		if ( is_numeric( $k ) ) {
			// 旧格式：值是 host，没有时间戳
			$h = riven_link_guard_host_key( (string) $v );
			if ( '' !== $h && ! isset( $out[ $h ] ) ) {
				$out[ $h ] = '';
			}
			continue;
		}
		$h = riven_link_guard_host_key( (string) $k );
		if ( '' !== $h ) {
			$out[ $h ] = (string) $v;
		}
	}
	return $out;
}

/**
 * 白名单 TTL（天）。到期后域名重新进抓取队列接受检测。
 */
function riven_health_whitelist_ttl() {
	$d = (int) rcg_opt( 'health_whitelist_ttl', 30 );
	return $d > 0 ? $d : 30;
}

/**
 * 这个域名现在还在白名单保护期内吗
 *
 * @param string $url_or_host
 * @return bool
 */
function riven_health_is_whitelisted( $url_or_host ) {
	$list = riven_health_whitelist();
	if ( empty( $list ) ) {
		return false;
	}
	$raw = (string) $url_or_host;
	$h   = riven_link_guard_host_key(
		false === strpos( $raw, '://' ) ? $raw : (string) wp_parse_url( $raw, PHP_URL_HOST )
	);
	if ( '' === $h ) {
		return false;
	}
	$ttl = riven_health_whitelist_ttl();
	foreach ( $list as $w => $added ) {
		$w = riven_link_guard_host_key( (string) $w );
		if ( '' === $w ) {
			continue;
		}
		if ( $h !== $w && substr( $h, - strlen( $w ) - 1 ) !== '.' . $w ) {
			continue;
		}
		// 旧数据没有时间戳 → 永不过期（升级兼容，见 riven_health_whitelist 注释）
		if ( '' === (string) $added ) {
			return true;
		}
		// TTL 内有效
		if ( strtotime( (string) $added ) > time() - $ttl * DAY_IN_SECONDS ) {
			return true;
		}
	}
	return false;
}

/**
 * 曾经被加进白名单、但**已经过期**的域名（1.4.0）
 *
 * 用途：后台提示"这些域名你手动恢复过，现在保护期已过、重新接受检测了"。
 * 以及「白名单到期复查」—— 到期后重新抓取，如果变成了风险/废弃站，
 * 要清清楚楚告诉老张，而不是静默降级。
 *
 * @return array host => added_at
 */
function riven_health_whitelist_expired() {
	$list = riven_health_whitelist();
	if ( empty( $list ) ) {
		return array();
	}
	$ttl  = riven_health_whitelist_ttl();
	$out  = array();
	foreach ( $list as $h => $added ) {
		if ( '' === (string) $added ) {
			continue; // 旧数据永不过期，不算"过期"
		}
		if ( strtotime( (string) $added ) <= time() - $ttl * DAY_IN_SECONDS ) {
			$out[ $h ] = (string) $added;
		}
	}
	return $out;
}

function riven_health_whitelist_add( $host ) {
	$h = riven_link_guard_host_key( $host );
	if ( strlen( $h ) < 4 || false === strpos( $h, '.' ) || in_array( $h, array( 'http', 'https', 'www' ), true ) ) {
		return false;
	}
	$list = riven_health_whitelist();
	// 已经在保护期内 → 不重置时间（否则老张每次点都等于续期，TTL 失去意义）
	if ( isset( $list[ $h ] ) && riven_health_is_whitelisted( $h ) ) {
		return true;
	}
	$list[ $h ] = current_time( 'mysql' );
	riven_health_set( 'health_whitelist', $list );
	return true;
}

function riven_health_whitelist_remove( $host ) {
	$h    = riven_link_guard_host_key( $host );
	$list = riven_health_whitelist();
	unset( $list[ $h ] );
	riven_health_set( 'health_whitelist', $list );
	return true;
}

/* -------------------------------------------------------------- 缓存清理 */

/** 改完输出相关的设置后清一次整页缓存（有 WP Rocket 就调用它的接口） */
function riven_health_purge_cache() {
	/*
	 * 1.5.2：从"只清 WP Rocket"扩成通用。
	 *
	 * 为什么必须清：降级是**渲染期**替换（把 <a> 换成 <span>），页面缓存插件存的是
	 * 替换后的 HTML。判定变了但缓存没清 → 前台还是旧样子，用户会以为插件没生效。
	 * 老张的 w 站用 WP Rocket，之前每次改完都得手动 rm -rf 缓存目录。
	 *
	 * 同一请求内只清一次：一次后台动作可能触发好几个判定分支，
	 * 每个都清一遍会把站打得更慢。
	 */
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	// WP Rocket
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'rocket_clean_minify' ) ) {
		rocket_clean_minify();
	}
	// LiteSpeed Cache
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
	}
	// W3 Total Cache
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	// WP Super Cache
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	// WP Fastest Cache
	if ( function_exists( 'wpfc_clear_all_cache' ) ) {
		wpfc_clear_all_cache( true );
	}
	// Autoptimize（只清它的页面缓存，不动 CSS/JS 合并）
	if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
		autoptimizeCache::clearall();
	}
	// 通用逃生口：不认识的缓存插件可以自己挂这个钩子
	do_action( 'riven_health_purge_page_cache' );
}

/* ------------------------------------------------------------ 自动轮转调度 */

/**
 * 定时跑一批（WP-Cron，每小时一次）
 *
 * 只扫"到期的"（没检过的 / 超过轮转间隔的）。全站都还在间隔内时空转，不产生抓取。
 * 这就是"持续监控"的正解：域名什么时候被卖掉我们无法预知，只能定期去看 ——
 * 某个老博友的域名明年被注册成黄站，下一轮轮转就会把它抓出来降级。
 */
function riven_health_cron_run() {
	if ( ! riven_health_auto_on() || ! riven_health_table_exists() ) {
		return;
	}

	/*
	 * 2026-10-17 起单批只扫 15 个（为了让一次 HTTP 请求不撞 fpm 时限），
	 * 所以这里改成「40 秒内能跑几批就跑几批」：域名都很健康时一小时能扫上百个，
	 * 撞上一堆死域名时也能在第 40 秒安全收手。
	 * 每批自己提交落库，中途被打断也不会丢已扫结果。
	 */
	$sum = array( 'scanned' => 0, 'risk' => 0, 'junk' => 0, 'ok' => 0, 'fail' => 0, 'remaining' => 0, 'error' => '' );
	$t0  = microtime( true );
	$i   = 0;
	do {
		$s = riven_health_scan_batch( null, false );
		$i++;
		foreach ( array( 'scanned', 'risk', 'junk', 'ok', 'fail' ) as $k ) {
			$sum[ $k ] += isset( $s[ $k ] ) ? (int) $s[ $k ] : 0;
		}
		$sum['remaining'] = isset( $s['remaining'] ) ? (int) $s['remaining'] : 0;
		if ( ! empty( $s['error'] ) ) {
			$sum['error'] = (string) $s['error'];
		}
		// 队列空了（done）或这一批一个都没扫到 → 收工，别空转。
		if ( ! empty( $s['done'] ) || empty( $s['scanned'] ) ) {
			break;
		}
	} while ( ( microtime( true ) - $t0 ) < 40 && $i < 10 );

	$s = $sum;
	update_option(
		'riven_health_last_cron',
		array(
			'time'      => time(),
			'scanned'   => isset( $s['scanned'] ) ? (int) $s['scanned'] : 0,
			'risk'      => isset( $s['risk'] ) ? (int) $s['risk'] : 0,
			'ok'        => isset( $s['ok'] ) ? (int) $s['ok'] : 0,
			'fail'      => isset( $s['fail'] ) ? (int) $s['fail'] : 0,
			'remaining' => isset( $s['remaining'] ) ? (int) $s['remaining'] : 0,
			'error'     => isset( $s['error'] ) ? (string) $s['error'] : '',
		),
		false
	);
	// 只在扫出风险时清前台缓存 —— 收紧必须立刻生效（安全优先）；
	// "恢复"是放宽，可以等缓存自然过期，不必为了它每小时清一次整站缓存。
	if ( ! empty( $s['risk'] ) || ! empty( $s['junk'] ) ) {
		riven_health_purge_cache();
	}
}
add_action( 'riven_health_cron_hook', 'riven_health_cron_run' );

/** 把定时任务排上 / 撤下（跟着开关走） */
function riven_health_maybe_schedule() {
	$hook = 'riven_health_cron_hook';
	$next = wp_next_scheduled( $hook );
	if ( riven_health_auto_on() ) {
		if ( ! $next ) {
			wp_schedule_event( time() + 120, 'hourly', $hook );
		}
	} elseif ( $next ) {
		wp_clear_scheduled_hook( $hook );
	}
}
// 只在后台检查，避免前台每次请求都去排程
add_action( 'admin_init', 'riven_health_maybe_schedule', 20 );

/* ---------------------------------------------------------------- 挂载 */

/**
 * 把某个域名标记为"待重检"（把检查时间推回到很久以前）
 *
 * 「新评论入场即检」的落地：博友回来留言，第一件事就是重新核实他的站现在是什么，
 * 而不是等下一次轮转。推回时间后它会排在队列最前（`riven_health_pending` 把
 * 最早检查的排最前），下一批（含自动轮转）就处理。
 *
 * 用 '1970-01-01' 而不是 NULL：checked_at 列是 NOT NULL，写 NULL 在 MySQL 严格模式下
 * 会直接报错；写一个远古时间既合法、语义又清楚（"排在所有人前面"）。
 */
const RIVEN_HEALTH_TODO = '1970-01-01 00:00:00';

function riven_health_touch_host( $url ) {
	$url = (string) $url;
	if ( '' === $url || ! riven_health_table_exists() ) {
		return;
	}
	$h = riven_link_guard_host_key( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $h || strlen( $h ) < 4 || false === strpos( $h, '.' ) ) {
		return;
	}
	global $wpdb;
	$t = riven_health_table();
	$wpdb->query(
		$wpdb->prepare( "UPDATE {$t} SET checked_at = %s WHERE host = %s", RIVEN_HEALTH_TODO, $h )
	);
	delete_transient( 'riven_health_hosts' );
}

// 新评论 / 审核变化 → 域名清单缓存作废（新域名下一批进队）＋ 涉及的域名催检
add_action(
	'wp_insert_comment',
	function ( $id, $comment ) {
		delete_transient( 'riven_health_hosts' );
		$st = isset( $comment->comment_approved ) ? (string) $comment->comment_approved : '';
		if ( '1' === $st || 'approve' === $st ) {
			riven_health_touch_host( isset( $comment->comment_author_url ) ? $comment->comment_author_url : '' );
		}
	},
	20,
	2
);
add_action(
	'transition_comment_status',
	function ( $new, $old, $comment ) {
		delete_transient( 'riven_health_hosts' );
		if ( 'approved' === $new || '1' === (string) $new ) {
			riven_health_touch_host( isset( $comment->comment_author_url ) ? $comment->comment_author_url : '' );
		}
	},
	20,
	3
);
foreach ( array( 'deleted_comment', 'trashed_comment' ) as $_h ) {
	add_action(
		$_h,
		function () {
			delete_transient( 'riven_health_hosts' );
		}
	);
}

// 评论列表取出来时批量预查体检状态（一次 SQL，避免逐条查库）
add_filter(
	'comments_array',
	function ( $comments ) {
		if ( riven_health_verdict_on() ) {
			riven_health_prime( $comments );
		}
		return $comments;
	},
	9,
	1
);

// 前台黑名单拦截
add_filter( 'pre_comment_approved', 'riven_health_block_comment', 10, 2 );

// 表结构兜底（版本变化后第一次进后台时建表）
add_action( 'admin_init', 'riven_health_maybe_install' );

/* ------------------------------------------------------------ 后台面板 */

// 菜单注册已统一移到插件主文件（wp-comment-guard.php）：
// 本文件只负责页面渲染，避免「插件」和「主题」两处都注册菜单打架。
// slug 仍是 riven-health，老书签继续可用。

/** 后台列表查询（$where 由本文件内部拼，不接受用户输入） */
function riven_health_admin_list( $where, $params = array(), $limit = 100 ) {
	if ( ! riven_health_table_exists() ) {
		return array();
	}
	global $wpdb;
	$t     = riven_health_table();
	$params[] = (int) $limit;
	$sql   = "SELECT * FROM {$t} WHERE {$where} ORDER BY score DESC, checked_at DESC LIMIT %d";
	return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
}

/**
 * 按「考核池口径」取后台列表（1.11.1，老张 2026-10-17 反馈）
 *
 * 问题和 nonpersonal_list 一样：表里 1006 个 fail 记录属于已归档域名 ——
 * 五年没往来、前台早就是纯文本、永远不会再被抓。它们混在列表里，
 * 把真正该看的 270 个淹掉了（老张原话：显示这 30 个也没意义）。
 *
 * 所以：**归档域名一律不出条目**，只回一个数字，页面上用一行小字交代清楚，
 * 免得他以为是"数据丢了"。记录本身不动 —— 万一哪天把人情味期调大，
 * 这些域名会重新回到考核池，历史结论还在。
 *
 * @param string $where  已由内部拼好的条件
 * @param array  $params prepare 参数
 * @param int    $limit  最多返回多少条（池内口径）
 * @return array array( rows, 池内总数, 归档隐藏数 )
 */
function riven_health_admin_split( $where, $params = array(), $limit = 100, $skip_whitelist = false ) {
	if ( ! riven_health_table_exists() ) {
		return array( array(), 0, 0 );
	}
	$arch = riven_health_archived_map();
	// 表最多 2000 多行，一次全取再过滤最省事，也不用担心"取 30 行全是归档的"导致列表空掉
	$all = riven_health_admin_list( $where, $params, 5000 );

	// 1.3.3：junk 列表要跳过人工白名单（点过"误判·恢复"的就不再出现在这里）
	// 1.4.0：只跳过**保护期内**的；已过期的重新出现在列表里（它又要被当废弃站处理了）
	$wl = array();
	if ( $skip_whitelist ) {
		foreach ( riven_health_whitelist() as $w => $added ) {
			if ( ! riven_health_is_whitelisted( $w ) ) {
				continue;
			}
			$h2 = riven_link_guard_host_key( (string) $w );
			if ( '' !== $h2 ) {
				$wl[ $h2 ] = true;
			}
		}
	}

	$rows   = array();
	$live   = 0;
	$hidden = 0;
	foreach ( (array) $all as $r ) {
		if ( isset( $arch[ $r->host ] ) ) {
			$hidden++;
			continue;
		}
		if ( $skip_whitelist && isset( $wl[ $r->host ] ) ) {
			$hidden++;
			continue;
		}
		$live++;
		if ( count( $rows ) < $limit ) {
			$rows[] = $r;
		}
	}
	return array( $rows, $live, $hidden );
}

/* ------------------------------------------------------ 待复核（1.3.0） */

/**
 * 人工"忽略"掉的待复核域名
 *
 * 为什么需要：待复核表里混着真博客（讲算命的、聊博彩新闻的），
 * 逐条判断一次就够了 —— 忽略过的不要再跳出来烦人。
 *
 * @return array 小写域名
 */
function riven_health_suspect_ignored() {
	$list = get_option( 'rcg_health_suspect_ignored', array() );
	if ( ! is_array( $list ) ) {
		$list = array();
	}
	return array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $list ) ) ) );
}

/**
 * 加入 / 移出"忽略"
 *
 * @param string $host
 * @param bool   $on
 * @return bool
 */
function riven_health_suspect_ignore( $host, $on = true ) {
	$h = riven_link_guard_host_key( $host );
	if ( '' === $h ) {
		return false;
	}
	$list = riven_health_suspect_ignored();
	if ( $on ) {
		if ( ! in_array( $h, $list, true ) ) {
			$list[] = $h;
		}
	} else {
		$list = array_values( array_diff( $list, array( $h ) ) );
	}
	update_option( 'rcg_health_suspect_ignored', $list, false );
	return true;
}

/**
 * 待复核清单：**网站标题本身**命中风险词，但总分没到阈值
 *
 * ⚠️ 1.3.2 重写准入条件（老张 2026-10-17 报"40 个里大部分是正常博客"）。
 *
 * 原来的准入是 `score > 0` —— 而 score 是**首页全文**打分出来的。结果实测 40 条，
 * **标题命中风险词的有 0 条**，前排全是真博客：小徐的流水时光、刑辩人在路上、
 * 海拉鲁打怪日记、折腾笔记……它们只是首页摘要里正好写了"法事""中奖""钓鱼"
 * "算命"这类词（在讲自己的经历 / 转载新闻 / 写读书笔记）。
 *
 * 判断一个站是不是黄赌诈骗，该看的是**网站标题**——那是站主给全站的自我定位，
 * 出现在标题里说明整站就干这个；出现在正文里只说明这篇文章提到了它。
 * 58yqm.com 能一眼认出，靠的正是标题「ZB中币网 | 中币网官网入口_交易所_交易平台」，
 * 不是正文里那两个 scam 词。
 *
 * 所以现在：**标题里一个风险词都命中的，才进这张表**；正文命中的只回传个数，
 * 不列出、不打扰（真要看时它就在体检记录里）。
 *
 * 已排除：已过人情味期（前台早降级）、已拉黑、已人工忽略、
 * 以及标题已被判为"商业站点"的（那些会出现在「已不是个人站」里，不重复列）。
 *
 * @param int      $limit
 * @param int|null $total      回传"标题命中"的总数
 * @param int|null $body_total 回传"仅正文命中、已被移出主表"的个数
 * @return array
 */
function riven_health_suspect_list( $limit = 100, &$total = null, &$body_total = null ) {
	global $wpdb;
	$total      = 0;
	$body_total = 0;
	if ( ! riven_health_table_exists() ) {
		return array();
	}
	$t   = riven_health_table();
	$thr = riven_health_threshold();
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT host, status, score, is_blog, signals, checked_at, hits, title
			 FROM {$t}
			 WHERE status = 'ok' AND score > 0 AND score < %d
			 ORDER BY score DESC, checked_at DESC",
			$thr
		)
	);
	if ( empty( $rows ) ) {
		return array();
	}

	$arch = riven_health_archived_map();
	$ign  = riven_health_suspect_ignored();
	$bl   = array();
	foreach ( riven_health_blacklist() as $b ) {
		$bl[] = riven_link_guard_host_key( (string) $b );
	}

	$out = array();
	foreach ( $rows as $r ) {
		$h = strtolower( (string) $r->host );
		if ( isset( $arch[ $h ] ) || in_array( $h, $ign, true ) || in_array( $h, $bl, true ) ) {
			continue;
		}
		if ( riven_health_row_is_nonpersonal( $r ) ) {
			continue; // 已在「已不是个人站」里，不重复列
		}

		/*
		 * 1.3.2：**只认标题**。
		 * riven_health_score( $title, $title ) = 把标题当作全文来打分，
		 * 得到的就是"标题里自带的词"。junk（域名待售）那一类归到「已废弃」栏，
		 * 不在这里重复出现。
		 */
		list( $ts, $th ) = riven_health_score( (string) $r->title, (string) $r->title );
		$th_all          = (array) $th;
		$th              = array_values(
			array_filter(
				$th_all,
				function ( $x ) {
					return 0 !== strpos( (string) $x, 'junk:' );
				}
			)
		);

		// 标题一个风险词都没有 → 正文里那些词只是"这篇文章在说这件事"
		if ( empty( $th_all ) ) {
			$body_total++;
			continue;
		}
		// 标题只有"域名待售"类 —— 那是「已废弃」那一栏的活，不在这里重复
		if ( empty( $th ) ) {
			continue;
		}
		// 标题自己就写着"博客/日志/随笔/自留地" → 站主已经自报家门，不打扰
		if ( riven_health_title_looks_personal( (string) $r->title ) ) {
			$body_total++;
			continue;
		}

		$r->title_score = (int) $ts;
		$r->title_hits  = implode( ',', $th );
		$out[]          = $r;
	}

	// 排序：标题分数高的在前（标题里两个词 > 一个词），同分再看全文分
	usort(
		$out,
		function ( $a, $b ) {
			if ( (int) $a->title_score !== (int) $b->title_score ) {
				return (int) $b->title_score - (int) $a->title_score;
			}
			return (int) $b->score - (int) $a->score;
		}
	);
	$total = count( $out );
	return array_slice( $out, 0, max( 1, (int) $limit ) );
}

/**
 * 按新规则复判全部「已废弃」域名（1.3.3）
 *
 * 背景：老张报 airy.ink「瓦匠不舟」、hahaha.cc「好事发生」被误判成域名待售。
 * 新规则只看标题 —— 标题是人写的就不是域名商模板页。**这条规则不依赖抓取**，
 * 所以存量记录可以立刻复判，不用等自动轮转把 600 个域名全跑一遍。
 *
 * 处理：
 *   · 标题是域名商模板（for sale / 域名出售 / 域名本身 / Loading…）→ 保持 junk
 *   · 标题是人写的                                          → 改 fail（fail_reason=shell）
 *   · 标题是 HTTP 错误页（404/502…）                        → 改 fail（fail_reason=errorpage）
 *
 * @return array{kept:int,freed:int}
 */
function riven_health_rejudge_junk() {
	global $wpdb;
	$t    = riven_health_table();
	$rows = $wpdb->get_results( "SELECT host, title, status FROM {$t} WHERE status = 'junk'" );
	$kept = 0;
	$free = 0;
	foreach ( (array) $rows as $r ) {
		$host  = (string) $r->host;
		$title = (string) $r->title;
		if ( riven_health_title_is_error_page( $title ) ) {
			$reason = 'errorpage';
		} elseif ( riven_health_title_is_shell_template( $title, $host ) ) {
			$kept++;
			continue;
		} else {
			$reason = 'shell';
		}
		$wpdb->update(
			$t,
			array(
				'status'      => 'fail',
				'fail_reason' => $reason,
				'checked_at'  => current_time( 'mysql' ),
			),
			array( 'host' => $host ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
		$free++;
	}
	riven_health_purge_cache();
	return array( 'kept' => $kept, 'freed' => $free );
}

/**
 * 词库指纹（1.4.2）
 *
 * 词库一变（插件内置词增删、后台自定义词、停用词）指纹就变。
 * 用来回答一个血泪问题：**"我明明升到最新版了，为什么这个站没被判出来？"**
 *
 * 因为 status / score / hits 是**扫描那一刻**的快照 —— 词是后来加的，
 * 存量记录不会自己改判。qijiwiki.com 就是这么漏的：
 * v1.3.2 补了「威廉希尔 / williamhill」，w 站当时做了定向重扫所以判了，
 * laozhang.org 那条记录还停在旧快照（0 分）→ 一直放行。
 *
 * @return string
 */
function riven_health_lexicon_hash() {
	$lex = riven_health_lexicon();
	ksort( $lex );
	$out = array();
	foreach ( $lex as $cat => $words ) {
		sort( $words );
		$out[ $cat ] = $words;
	}
	return md5( (string) wp_json_encode( $out ) );
}

/**
 * 按现行词库重判存量（**不重新抓取**，1.4.2）
 *
 * 只认**标题**：标题已经在库里，不需要再抓一次；正文没存，所以靠正文凑分的
 * （58yqm.com 那种只拿到 20 分）复判不出来，那类只能重扫。口径与「待复核」一致。
 *
 * 三条铁律：
 *   ① **只升不降**：够阈值就把 ok/junk 改成 risk；词被停用**不会**把 risk 改回来
 *      —— 风险结论宁可留着，也不能因为手滑删个词就放行赌站。
 *   ② 不动 fail（抓不到）的记录：它的标题可能是很久以前的，站点早换内容了，
 *      拿旧标题定罪就是"看不清也降级"，违反铁律。等下次抓到再按新词库判。
 *   ③ 跳过 junk 类词：待售页是"域名废弃"，不是"黄赌风险"，语义不同。
 *
 * @param bool $dry 只统计不落库
 * @return array{checked:int,promoted:int,list:array}
 */
function riven_health_rejudge_lexicon( $dry = false ) {
	global $wpdb;
	$t   = riven_health_table();
	$thr = riven_health_threshold();
	$out = array(
		'checked'  => 0,
		'promoted' => 0,
		'list'     => array(),
	);
	if ( ! riven_health_table_exists() ) {
		return $out;
	}
	// title 为空的（从来没抓到标题）没有判断依据，不碰。
	$rows = $wpdb->get_results(
		"SELECT host, status, score, title FROM {$t}
		 WHERE status IN ('ok','junk') AND title <> '' AND title <> '[空壳/停靠页]'"
	);
	foreach ( (array) $rows as $r ) {
		$out['checked']++;
		list( $s, $hits ) = riven_health_score( (string) $r->title, (string) $r->title, true );
		if ( $s < $thr ) {
			continue;
		}
		$out['promoted']++;
		$out['list'][] = array(
			'host'  => (string) $r->host,
			'old'   => (int) $r->score,
			'new'   => (int) $s,
			'hits'  => implode( ',', (array) $hits ),
			'title' => (string) $r->title,
		);
		if ( $dry ) {
			continue;
		}
		$wpdb->update(
			$t,
			array(
				'status'      => 'risk',
				'score'       => (int) $s,
				'hits'        => implode( ',', (array) $hits ),
				'fail_reason' => 'lexicon',  // 来源可溯：词库升级复判，不是本轮抓出来的
			),
			array( 'host' => (string) $r->host ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%s' )
		);
	}
	if ( ! $dry && $out['promoted'] > 0 ) {
		riven_health_purge_cache();
	}
	return $out;
}

/**
 * 词库指纹变了就自动复判一次（1.4.2）
 *
 * 为什么挂在 init 而不是只在后台：前台判定读的就是库里的 status，
 * 若只在后台跑，升完级没进后台之前前台还是旧结论。
 *
 * 成本：指纹相同 → 一次 get_option（走 alloptions 缓存），什么都不做；
 * 只有指纹变了才跑一次全表扫描（600 来行纯字符串匹配，几十毫秒）。
 */
function riven_health_lexicon_sync() {
	if ( ! riven_health_on() || ! function_exists( 'rcg_opt' ) ) {
		return;
	}
	$cur = riven_health_lexicon_hash();
	if ( $cur === (string) get_option( 'rcg_lexicon_hash', '' ) ) {
		return;
	}
	// 并发锁：多个请求同时发现指纹变化时，只让一个真跑
	if ( get_transient( 'rcg_lex_sync_lock' ) ) {
		return;
	}
	set_transient( 'rcg_lex_sync_lock', 1, 60 );
	$r = riven_health_rejudge_lexicon();
	update_option( 'rcg_lexicon_hash', $cur, false );
	delete_transient( 'rcg_lex_sync_lock' );
	if ( $r['promoted'] > 0 ) {
		// 留一条后台提示，让站长知道"有些域名是被新词改判的"，不是凭空冒出来的
		update_option(
			'rcg_lex_sync_note',
			array(
				'at'       => current_time( 'mysql' ),
				'promoted' => (int) $r['promoted'],
			),
			false
		);
	}
}
add_action( 'init', 'riven_health_lexicon_sync', 20 );

/* ================================================================ 异常播报（1.5.0）
 *
 * 为什么要有：插件一直在"持续扫描"，但结果要站长自己进后台滑过六张表才知道
 * 有没有新情况 —— 等于装了监控、没装报警器。
 *
 * 设计取舍（2026-10-18 老张定）：
 *   · **只做后台提示，不做邮件**。邮件依赖 SMTP 配置，很多站根本没配好，
 *     真出事时恰恰是它不响；而后台提示只要人一登录就看得见。
 *   · **只报"变化"，不报"状态"**。天天显示"风险 50 个"看两次就麻木了，
 *     要报的是"自你上次看过之后，又多了哪几个"。
 *   · **首次不报**：装好/升级后第一眼不该甩出几十条"未读"，那是噪音。
 *     第一次运行只建立基线。
 *   · 归档域名（超过人情味期）不参与 —— 跟前台判定、体检页口径保持一致。
 */

/**
 * 当前风险域名的 host 列表（轻量，1 条 SQL）
 *
 * 徽章要在**每个后台页面**都算，所以不能查全表、不能跑判定链。
 * status 有索引，50 行左右，代价可以忽略。
 *
 * @return string[]
 */
function riven_health_alert_risk_hosts() {
	// 不设静态缓存：徽章(admin_menu) 与页面各调一次，2 条带索引的查询代价可忽略；
	// 而静态缓存会在"同一请求里状态被改过"时给出旧结果（1.5.0 测试时就踩到这个）。
	if ( ! riven_health_on() || ! riven_health_table_exists() ) {
		return array();
	}
	global $wpdb;
	$t = riven_health_table();
	return array_map( 'strval', (array) $wpdb->get_col( "SELECT host FROM {$t} WHERE status='risk'" ) );
}

/**
 * 自上次「标记已读」以来新增的风险域名
 *
 * @return string[]
 */
function riven_health_alert_unread() {
	$cur  = riven_health_alert_risk_hosts();
	$seen = get_option( 'rcg_alert_seen_risk', null );
	if ( ! is_array( $seen ) ) {
		// 首次运行：建立基线。不报"未读"，否则一升级就甩出几十条。
		update_option( 'rcg_alert_seen_risk', array_values( $cur ), false );
		update_option( 'rcg_alert_seen_at', current_time( 'mysql' ), false );
		return array();
	}
	$seen = array_map( 'strval', $seen );
	return array_values( array_diff( $cur, $seen ) );
}

/**
 * 自上次「标记已读」以来新增的待复核域名（较重，带 10 分钟缓存）
 *
 * 走的是面板同一个函数 riven_health_suspect_list()，保证口径不会分叉。
 *
 * @return array<string,string> host => title
 */
function riven_health_alert_new_suspect() {
	$cache = get_transient( 'rcg_alert_suspect' );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$tmp = 0;
	$cur = array();
	foreach ( (array) riven_health_suspect_list( 300, $tmp ) as $r ) {
		$cur[ (string) $r->host ] = (string) $r->title;
	}
	$seen = get_option( 'rcg_alert_seen_suspect', null );
	if ( ! is_array( $seen ) ) {
		update_option( 'rcg_alert_seen_suspect', array_keys( $cur ), false );
		set_transient( 'rcg_alert_suspect', array(), 10 * MINUTE_IN_SECONDS );
		return array();
	}
	$seen = array_map( 'strval', $seen );
	$new  = array();
	foreach ( $cur as $h => $title ) {
		if ( ! in_array( $h, $seen, true ) ) {
			$new[ $h ] = $title;
		}
	}
	set_transient( 'rcg_alert_suspect', $new, 10 * MINUTE_IN_SECONDS );
	return $new;
}

/** 全部标记已读 */
function riven_health_alert_mark_seen() {
	update_option( 'rcg_alert_seen_risk', array_values( riven_health_alert_risk_hosts() ), false );
	$tmp = 0;
	$sus = array();
	foreach ( (array) riven_health_suspect_list( 300, $tmp ) as $r ) {
		$sus[] = (string) $r->host;
	}
	update_option( 'rcg_alert_seen_suspect', $sus, false );
	update_option( 'rcg_alert_seen_at', current_time( 'mysql' ), false );
	delete_transient( 'rcg_alert_suspect' );
}

/** 菜单徽章上的数字（0 = 不显示徽章） */
function riven_health_alert_badge_count() {
	if ( ! riven_health_on() ) {
		return 0;
	}
	return count( riven_health_alert_unread() );
}

/** 仪表盘卡片：登录 wp-admin 首页就能看到，不用先摸到菜单 */
function riven_health_dashboard_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$risk   = riven_health_alert_unread();
	$sus    = riven_health_alert_new_suspect();
	$seen_at = (string) get_option( 'rcg_alert_seen_at', '' );

	if ( empty( $risk ) && empty( $sus ) ) {
		echo '<p style="color:#646970">自上次查看以来没有新增异常。</p>';
		if ( '' !== $seen_at ) {
			echo '<p class="description">上次查看：' . esc_html( $seen_at ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=riven-health' ) ) . '">打开外链体检</a></p>';
		return;
	}

	if ( ! empty( $risk ) ) {
		echo '<p><b style="color:#d63638">新增风险域名 ' . count( $risk ) . ' 个</b>（前台已自动降为纯文本）</p><ul style="margin:0 0 10px 18px;list-style:disc">';
		$i = 0;
		foreach ( $risk as $h ) {
			$row   = riven_health_row( $h );
			$title = $row ? (string) $row->title : '';
			echo '<li><code>' . esc_html( $h ) . '</code>' . ( '' !== $title ? ' — ' . esc_html( mb_substr( $title, 0, 40 ) ) : '' ) . '</li>';
			if ( ++$i >= 8 ) {
				echo '<li style="list-style:none;color:#646970">…另有 ' . ( count( $risk ) - 8 ) . ' 个，见外链体检</li>';
				break;
			}
		}
		echo '</ul>';
	}
	if ( ! empty( $sus ) ) {
		echo '<p><b>新增待复核 ' . count( $sus ) . ' 个</b>（标题自带风险词，还没到阈值）</p><ul style="margin:0 0 10px 18px;list-style:disc">';
		$i = 0;
		foreach ( $sus as $h => $title ) {
			echo '<li><code>' . esc_html( $h ) . '</code>' . ( '' !== $title ? ' — ' . esc_html( mb_substr( $title, 0, 40 ) ) : '' ) . '</li>';
			if ( ++$i >= 5 ) {
				break;
			}
		}
		echo '</ul>';
	}

	echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
	echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=riven-health' ) ) . '">去看一看</a>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
	wp_nonce_field( 'rcg_alert_seen' );
	echo '<input type="hidden" name="action" value="rcg_alert_seen">';
	echo '<button class="button">标记为已读</button></form></div>';
}
add_action(
	'wp_dashboard_setup',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'rcg_alerts', '外链守卫 · 异常播报', 'riven_health_dashboard_widget' );
	}
);

/** admin-post：仪表盘上的「标记为已读」 */
add_action(
	'admin_post_rcg_alert_seen',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '没有权限。' );
		}
		check_admin_referer( 'rcg_alert_seen' );
		riven_health_alert_mark_seen();
		wp_safe_redirect( add_query_arg( 'rcg-seen', '1', admin_url( 'index.php' ) ) );
		exit;
	}
);

/* ================================================================ 域名速查（1.5.0）
 *
 * 站长看到一个链接拿不准（或者好奇"这个站为什么被放行"），原来只能来问开发。
 * 这里把判定链**每一步**的结果摊开：库内记录、黑白名单、死链、停靠页、
 * 时间闸门、站点性质、最终结论和前台提示文案。
 *
 * 注意：只诊断、不改任何数据。
 */

/**
 * @param string $input 域名或整条链接
 * @return array
 */
function riven_health_diagnose( $input ) {
	$raw = trim( (string) $input );
	$host = $raw;
	if ( false !== strpos( $raw, '://' ) ) {
		$host = (string) wp_parse_url( $raw, PHP_URL_HOST );
	} elseif ( false !== strpos( $raw, '/' ) ) {
		$host = (string) wp_parse_url( 'http://' . $raw, PHP_URL_HOST );
	}
	$host = riven_link_guard_host_key( $host );
	if ( '' === $host || false === strpos( $host, '.' ) || strlen( $host ) < 4 ) {
		return array( 'error' => '看起来不是一个合法域名：' . $raw );
	}

	$out = array(
		'host'    => $host,
		'steps'   => array(),
		'verdict' => null,
		'comment' => null,
	);

	/*
	 * 整个诊断**强制访客视角**。
	 *
	 * 站长登录后台时"站长豁免"生效（看到的永远是原始链接），
	 * 不强制就会算出"你能看到 → 可点"，而结论要回答的是"访客看到什么"。
	 * 1.5.0 第一版没做这个，结果步骤表写着"免考核期=是→放行"、
	 * 结论却是"降级" —— 两套口径混在一张表里，纯属自己给自己挖坑。
	 */
	$had_guest  = array_key_exists( 'riven_link_guard_force_guest', $GLOBALS );
	$prev_guest = $had_guest ? $GLOBALS['riven_link_guard_force_guest'] : null;
	$GLOBALS['riven_link_guard_force_guest'] = true;

	// ① 库内记录
	$row = riven_health_row( $host );
	$out['row'] = $row;

	// ② 引用它的评论 —— **逐条跑判定**。
	//    同一域名常被多个访客引用，每个人的"最后往来时间"不同，结论可能不一样：
	//    老访客那条会被归档降级，新访客那条却还在免考核期。只拿一条做代表会骗人
	//    （1.5.0 第一版就这么写的，拿最新一条算，跟页面上实际渲染的结果对不上）。
	global $wpdb;
	$rows_c = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comment_ID, comment_post_ID, comment_author, comment_author_email,
			        comment_author_url, comment_date
			 FROM {$wpdb->comments}
			 WHERE comment_approved = '1' AND comment_author_url LIKE %s
			 ORDER BY comment_date DESC LIMIT 40",
			'%' . $wpdb->esc_like( $host ) . '%'
		)
	);
	$refs = array();
	foreach ( (array) $rows_c as $x ) {
		// LIKE 可能命中的是子串（a-example.com），必须再核一遍 host
		$ch = riven_link_guard_host_key( (string) wp_parse_url( (string) $x->comment_author_url, PHP_URL_HOST ) );
		if ( $ch !== $host ) {
			continue;
		}
		$v      = riven_link_guard_guest_verdict( $x, (string) $x->comment_author_url );
		$refs[] = array(
			'comment' => $x,
			'allow'   => (bool) $v['allow'],
			'kind'    => (string) $v['kind'],
		);
	}
	$out['refs_list'] = $refs;
	$out['refs']      = count( $refs );

	// 时间维度的示例，用**最新**那条（页面上一眼看的就是它）
	$c = ! empty( $refs ) ? $refs[0]['comment'] : null;
	$out['comment'] = $c;

	$url = 'https://' . $host . '/';

	// ③ 逐层
	$out['steps'][] = array(
		'name' => '库内记录',
		'res'  => $row ? (string) $row->status : '（从未体检过）',
		'used' => true,
		'note' => $row
			? sprintf(
				'分数 %d · 标题「%s」%s · 检查于 %s%s',
				(int) $row->score,
				mb_substr( (string) $row->title, 0, 40 ),
				'' !== (string) $row->hits ? ' · 命中 ' . mb_substr( (string) $row->hits, 0, 60 ) : '',
				(string) $row->checked_at,
				'' !== (string) $row->fail_reason ? ' · fail_reason=' . $row->fail_reason : ''
			)
			: '不在体检表里，说明它还没进过考核池（可能超出人情味期，或从未被提到）',
	);

	/*
	 * 判定链是**短路**的：某一层命中就直接返回，后面的层根本不执行。
	 * 表格必须体现这一点 —— 否则会出现"免考核期写着『是 → 放行』，
	 * 结论却是降级"的自相矛盾（1.5.0 第一版就这么错的）。
	 */
	$halt = '';
	$step = function ( $name, $hit, $note, $effect ) use ( &$out, &$halt ) {
		$used = ( '' === $halt );
		if ( $used && $hit ) {
			$halt = $name;
		}
		if ( ! $used ) {
			$note = trim( (string) $note . '（本次未参与：前面的「' . $halt . '」已决定结果）' );
		}
		$out['steps'][] = array(
			'name' => $name,
			'res'  => $hit ? $effect : '否',
			'note' => $note,
			'used' => $used,
		);
	};

	$wl      = function_exists( 'riven_health_is_whitelisted' ) && riven_health_is_whitelisted( $url );
	$wl_note = '';
	if ( $wl && function_exists( 'riven_health_whitelist' ) ) {
		$list = riven_health_whitelist();
		$at   = isset( $list[ $host ] ) ? (string) $list[ $host ] : '';
		$ttl  = (int) rcg_opt( 'health_whitelist_ttl', 30 );
		if ( '' !== $at ) {
			$left = $ttl - (int) floor( ( time() - strtotime( $at ) ) / DAY_IN_SECONDS );
			$wl_note = $left > 0
				? sprintf( '加入于 %s，保护期还剩 %d 天（到期后会重新体检）', $at, $left )
				: sprintf( '加入于 %s，保护期已过 —— 下次轮转会重新体检', $at );
		}
	}
	$step( '人工白名单', $wl, $wl_note, '是 → 直接放行' );

	$bl = riven_health_is_blacklisted( $host );
	$out['steps'][] = array(
		'name' => '黑名单',
		'res'  => $bl ? '是' : '否',
		'note' => $bl ? '站长亲手拉黑，独立于体检与判定开关生效（命中即拦）' : '',
		'used' => true,
	);

	$step( '体检判风险', riven_health_is_risk( $url ), '命中词见上面「库内记录」一行', '是 → 降级' );
	$step( '死链', riven_health_is_dead( $url ), '只认 dns / 404 / 410；403 与超时算"看不清"，不降级', '是 → 降级' );
	$step( '停靠 / 待售页', riven_health_is_parked( $url ), '看标题模板，不看抓取体积', '是 → 降级' );

	// 时间闸门：拿最新的那条评论当代表，跑真实判定
	$in_touch   = $c ? riven_link_guard_in_touch( $c, $url ) : false;
	$person_ts  = $c ? riven_link_guard_person_ts( (string) $c->comment_author_email ) : 0;
	$host_fresh = function_exists( 'riven_link_guard_host_fresh' ) ? riven_link_guard_host_fresh( $host ) : null;

	$t_note = array();
	if ( $c ) {
		$t_note[] = sprintf( '代表性评论：%s（%s）', (string) $c->comment_author, (string) $c->comment_date );
	}
	$t_note[] = $person_ts ? sprintf( '该邮箱最近一次过审评论：%s', get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $person_ts ), 'Y-m-d' ) ) : '该邮箱没有过审评论记录';
	if ( false === $host_fresh ) {
		$t_note[] = '域名维度：早已没人把它当自己的网址填';
	} elseif ( null === $host_fresh ) {
		$t_note[] = '域名维度：引用型（从没人当自己网址填过）→ 不参与判定';
	} else {
		$t_note[] = sprintf( '域名维度：最近一次被填成作者网址 %s', get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $host_fresh ), 'Y-m-d' ) );
	}
	$step(
		'免考核期',
		$in_touch,
		implode( '；', $t_note ) . sprintf( '（阈值 %d 天，人和域名任一新鲜就算）', function_exists( 'riven_link_guard_days' ) ? riven_link_guard_days() : 365 ),
		'是 → 直接放行'
	);

	$mercy = $c ? riven_link_guard_beyond_mercy( $c, $url ) : false;
	$step(
		'人情味期',
		$mercy,
		sprintf( '超过 %d 天毫无往来就归档降级，也不再体检', function_exists( 'riven_link_guard_mercy_days' ) ? riven_link_guard_mercy_days() : 1825 ),
		'是 → 归档降级'
	);

	$nonp    = function_exists( 'riven_health_is_nonpersonal' ) ? riven_health_is_nonpersonal( $url ) : false;
	$np_note = '';
	if ( $row && 'ok' === $row->status ) {
		if ( riven_health_title_looks_personal( (string) $row->title ) ) {
			$np_note = '标题写着"博客/日志/随笔…" → 判为个人站';
		} elseif ( riven_health_title_is_personal_site( (string) $row->title, $host ) ) {
			$np_note = '标题像人写的（网名/意境词）→ 判为个人站';
		} elseif ( riven_health_title_is_commercial( (string) $row->title ) ) {
			$np_note = '标题是商业站点句式 → 判为"已不是个人站"';
		} elseif ( riven_health_title_is_biz_site( (string) $row->title ) ) {
			$np_note = '标题自称公司/工作室/信息网等机构 → 判为"已不是个人站"';
		} elseif ( (int) $row->is_blog ) {
			$np_note = 'HTML 里有博客/CMS 指纹 → 判为个人站';
		} else {
			$np_note = '没有任何博客指纹，但标题不像商业站 → 归入「暂无指纹·待观察」，前台不降级';
		}
	}
	$step( '站点性质', $nonp, $np_note, '是 → 降级（已不是个人站）' );

	// 恢复外层可能已经设好的访客模式标记
	if ( $had_guest ) {
		$GLOBALS['riven_link_guard_force_guest'] = $prev_guest;
	} else {
		unset( $GLOBALS['riven_link_guard_force_guest'] );
	}

	// ④ 最终结论：按**访客视角**统计（站长登录时看到的是原始链接，必须避开这个豁免）
	$allow_n    = 0;
	$deny_n     = 0;
	$first_kind = '';
	foreach ( $refs as $r ) {
		if ( $r['allow'] ) {
			$allow_n++;
		} else {
			$deny_n++;
			if ( '' === $first_kind ) {
				$first_kind = $r['kind'];
			}
		}
	}
	$out['allow_n'] = $allow_n;
	$out['deny_n']  = $deny_n;

	if ( empty( $refs ) ) {
		$out['final'] = array(
			'allow' => true,
			'mixed' => false,
			'text'  => '没有已审评论引用这个域名，无法按"人"这一维判定（如果它出现在正文里，按域名维度单独判）',
		);
	} elseif ( 0 === $deny_n ) {
		$out['final'] = array(
			'allow' => true,
			'mixed' => false,
			'text'  => sprintf( '%d 条引用全部可点击（正常链接，补 rel="nofollow ugc"）', $allow_n ),
		);
	} elseif ( 0 === $allow_n ) {
		$out['final'] = array(
			'allow' => false,
			'mixed' => false,
			'text'  => sprintf( '全部 %d 条都降为纯文本 · 悬停提示：%s', $deny_n, riven_link_guard_muted_tip( $first_kind ) ),
		);
	} else {
		$out['final'] = array(
			'allow' => false,
			'mixed' => true,
			'text'  => sprintf(
				'混合：%d 条可点击、%d 条降级 —— 同域名不同访客，"最后往来时间"不同，结论就不同（明细见下表）',
				$allow_n,
				$deny_n
			),
		);
	}
	$out['first_kind'] = $first_kind;

	return $out;
}

/** 后台面板 */
function riven_health_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';
	$error  = '';

	// ---- 域名速查（1.5.0）：GET 参数，不写库、不动任何数据 ----
	$rcg_diag = null;
	if ( isset( $_GET['rcg_q'] ) ) {
		$rcg_q = sanitize_text_field( wp_unslash( $_GET['rcg_q'] ) );
		if ( '' !== trim( $rcg_q ) ) {
			$rcg_diag = riven_health_diagnose( $rcg_q );
		}
	}
	if ( isset( $_GET['rcg-seen'] ) ) {
		$notice = '已标记为已读。';
	}

	// ---- 表单动作 ----
	if ( isset( $_POST['riven_health_action'] ) ) {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'riven_health_action' ) ) {
			$error = '安全校验失败，请重试。';
		} else {
			$act  = sanitize_text_field( wp_unslash( $_POST['riven_health_action'] ) );
			$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';

			switch ( $act ) {
				case 'scan':
					// 手动点"扫描下一批"= 强制推进队列（忽略轮转间隔）
					$s = riven_health_scan_batch( null, true );
					if ( isset( $s['error'] ) ) {
						$error = '建表失败：请确认数据库账号有 CREATE TABLE 权限。';
					} else {
						$notice = sprintf(
							'本批完成：扫描 %d 个（风险 %d · 正常 %d · 抓不到 %d），还剩 %d 个',
							$s['scanned'], $s['risk'], $s['ok'], $s['fail'], $s['remaining']
						);
						riven_health_purge_cache();
					}
					break;

				case 'blacklist':
					$notice = riven_health_blacklist_add( $host )
						? '已拉黑 ' . $host . '（前台提交该域名的评论会被拒绝）'
						: '拉黑失败：域名不合法';
					riven_health_purge_cache();
					break;

				case 'unblacklist':
					riven_health_blacklist_remove( $host );
					$notice = '已取消拉黑 ' . $host;
					riven_health_purge_cache();
					break;

				case 'import_blacklist':
					// 合并导入：只加不清。可从另一个站导出的 JSON，也可以直接粘一行一个域名。
					$raw = '';
					if ( isset( $_POST['rcg_blacklist_text'] ) ) {
						$raw = (string) wp_unslash( $_POST['rcg_blacklist_text'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					}
					if ( '' === trim( $raw ) && ! empty( $_FILES['rcg_blacklist_file']['tmp_name'] ) ) {
						$tmp = $_FILES['rcg_blacklist_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
						// 大小上限（1 MB）：黑名单几千个域名也才几十 KB，超过这个量级说明选错文件了。
						// 没有上限的话，一次错误上传会把整个文件读进内存 —— 共享主机上容易直接 500。
						$fsize = @filesize( $tmp );
						if ( is_uploaded_file( $tmp ) && false !== $fsize && $fsize <= 1048576 ) {
							$raw = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
						} elseif ( false !== $fsize && $fsize > 1048576 ) {
							$error = '导入文件太大（' . size_format( $fsize ) . '），上限 1 MB —— 黑名单一般只有几十 KB，请确认选的是不是名单文件。';
						}
					}
					$imp    = riven_health_blacklist_import( $raw );
					$notice = sprintf(
						'黑名单导入完成：新增 %d 个，原本就在名单里 %d 个，格式无效 %d 个；现在共 %d 个。',
						$imp['added'], $imp['skipped'], $imp['invalid'], $imp['total']
					);
					if ( 0 === $imp['added'] && 0 === $imp['skipped'] ) {
						$error = '没有解析到任何域名 —— 请确认粘的是导出的 JSON，或一行一个域名。';
					}
					riven_health_purge_cache();
					break;

				case 'whitelist':
					$ok = riven_health_whitelist_add( $host );
					// 1.3.3：库里若还留着 junk/risk 结论，一并清掉 ——
					// 否则前台虽已放行，后台清单里仍挂着它，老张看着以为没生效。
					if ( $ok ) {
						global $wpdb;
						$wpdb->update(
							riven_health_table(),
							array( 'status' => 'fail', 'fail_reason' => 'whitelisted', 'checked_at' => current_time( 'mysql' ) ),
							array( 'host' => riven_link_guard_host_key( $host ) ),
							array( '%s', '%s', '%s' ),
							array( '%s' )
						);
					}
					$notice = $ok
						? '已把 ' . $host . ' 加入人工白名单：前台立即恢复可点，以后也不再抓它、不会再被判"已废弃/风险"'
						: '加入失败：域名不合法';
					riven_health_purge_cache();
					break;

				case 'unwhitelist':
					riven_health_whitelist_remove( $host );
					$notice = '已移出白名单 ' . $host;
					riven_health_purge_cache();
					break;

				case 'replace':
					$to = isset( $_POST['host_to'] ) ? sanitize_text_field( wp_unslash( $_POST['host_to'] ) ) : '';
					if ( ! empty( $_POST['confirm'] ) ) {
						$r      = riven_health_replace_host( $host, $to );
						$notice = $r['msg'];
						if ( ! $r['ok'] ) {
							$error = $r['msg'];
						}
						riven_health_purge_cache();
					} else {
						$error = '请先勾选"我已备份数据库"再执行替换。';
					}
					break;

				case 'undo_replace':
					$r      = riven_health_undo_replace();
					$notice = $r['msg'];
					riven_health_purge_cache();
					break;

				case 'reset_token':
					riven_helper_token( true );
					$notice = '本机助手令牌已重置 —— 旧的助手脚本会失效，请重新下载脚本。';
					break;

				case 'suspect_ignore':
					riven_health_suspect_ignore( $host, true );
					$notice = '已忽略 ' . $host . '，以后不再出现在待复核里（不影响它被判风险）。';
					break;

			case 'suspect_unignore':
				riven_health_suspect_ignore( $host, false );
				$notice = '已恢复 ' . $host . ' 到待复核。';
				break;

			case 'rejudge_junk':
				// 按新规则（1.3.3：标题是人写的就不算域名待售）复判存量 junk，
				// **不重新抓取** —— 新判据只依赖标题，标题已经在库里了。
				$rj = riven_health_rejudge_junk();
				$notice = sprintf(
					'复判完成：%d 个仍是域名待售/停靠页，%d 个标题是人写的（已改为"抓不到"，前台恢复可点）。',
					$rj['kept'],
					$rj['freed']
				);
				break;

			case 'rejudge_lexicon':
				// 按现行词库重判存量（1.4.2）：只认标题、不重新抓取。
				$rl = riven_health_rejudge_lexicon();
				update_option( 'rcg_lexicon_hash', riven_health_lexicon_hash(), false );
				$notice = sprintf(
					'词库复判完成：检查 %d 个，%d 个按现行词库够到 %d 分，已改为风险。%s',
					$rl['checked'],
					$rl['promoted'],
					(int) riven_health_threshold(),
					$rl['promoted'] > 0 ? '（靠正文凑分的域名复判不出来，需要重扫才会更新）' : ''
				);
				break;

			case 'alert_seen':
				// 异常播报的「标记已读」（1.5.0）
				riven_health_alert_mark_seen();
				$notice = '已标记为已读，之后的异常会重新计数。';
				break;

			case 'rescan_list':
				/*
				 * 「扫描复核」（1.3.4 老张要求）：只对该清单内的域名重新抓取判定，
				 * 不用去点"重扫考核池"把整个考核池跑一遍。
				 *
				 * 会话起点存 option（按清单类型分开存）：多点几次按钮能收敛，
				 * 不会把同一批反复扫 —— 跟"全站重扫"的 $since 是同一个套路。
				 */
				$kind  = isset( $_POST['list_kind'] ) ? sanitize_text_field( wp_unslash( $_POST['list_kind'] ) ) : '';
				$label = ( 'nonpersonal' === $kind ) ? '已不是个人站' : ( ( 'fail' === $kind ) ? '已失效链接' : '' );
				if ( '' === $label ) {
					$error = '未知的清单类型。';
					break;
				}
				$hosts = riven_health_list_hosts( $kind, 200 );
				if ( empty( $hosts ) ) {
					$notice = '清单是空的，没有可复核的域名。';
					break;
				}
				$skey  = 'rcg_rescan_since_' . $kind;
				$since = (string) get_option( $skey, '' );
				if ( '' === $since ) {
					$since = current_time( 'mysql' );
					update_option( $skey, $since, false );
				}
				$s = riven_health_scan_batch( 60, true, $since, $hosts );
				riven_health_purge_cache();
				if ( isset( $s['error'] ) ) {
					$error = '扫描失败：请确认数据库账号有 CREATE TABLE 权限。';
					break;
				}
				if ( (int) $s['remaining'] <= 0 ) {
					delete_option( $skey );
				}
				$notice = sprintf(
					'扫描复核完成（%s，清单共 %d 个）：本批处理 %d 个 —— 风险 %d · 正常 %d · 抓不到 %d，还剩 %d 个，可再点一次继续。',
					$label,
					count( $hosts ),
					(int) $s['scanned'],
					(int) $s['risk'],
					(int) $s['ok'],
					(int) $s['fail'],
					(int) $s['remaining']
				);
				break;
			}
		}
	}

	// ---- 一键连续扫描（后台自动一批批跑到底） ----
	// force=1 时忽略轮转间隔，把全站重扫一遍 —— 改完词库/阈值后必须用它才看得到效果。
	$auto_now   = false;
	$scan_force = isset( $_GET['force'] ) && '1' === $_GET['force'];
	// 全站重扫的"会话起点"：只有 checked_at 早于它的域名才扫。
	// 每批完成都把它原样带在刷新 URL 上 —— force 模式靠它收敛，缺了就是无限循环。
	$scan_since = '';
	if ( $scan_force ) {
		$scan_since = isset( $_GET['since'] ) ? sanitize_text_field( wp_unslash( $_GET['since'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $scan_since ) ) {
			$scan_since = current_time( 'mysql' );
		}
	}
	if ( isset( $_GET['riven_autoscan'] ) && '1' === $_GET['riven_autoscan'] ) {
		$s = riven_health_scan_batch( null, $scan_force, $scan_since );
		if ( isset( $s['error'] ) ) {
			$error = '建表失败：请确认数据库账号有 CREATE TABLE 权限。';
		} elseif ( 0 === $s['scanned'] ) {
			if ( (int) $s['remaining'] > 0 ) {
				// 预算用完、这一批一个都没抓到（比如整批都是超慢站）。
				// 不自动刷新 —— 否则可能原地打转；让用户点一下继续。
				$notice = sprintf( '本批没有取到任何结果（可能整批超时），还剩 %d 个。请再点一次按钮继续。', $s['remaining'] );
			} else {
				$notice = $scan_force
					? '全站已重扫完毕（本轮没有待重扫的域名了）。'
					: '扫描已全部完成。';
			}
		} else {
			$auto_now = true;
			$notice   = sprintf(
				'%s：本批 %d 个（风险 %d · 正常 %d · 抓不到 %d），还剩 %d 个…',
				$scan_force ? '全站重扫中' : '连续扫描中',
				$s['scanned'], $s['risk'], $s['ok'], $s['fail'], $s['remaining']
			);
			riven_health_purge_cache();
		}
	}

	// 1.10.0 时间闸门：$total 是「考核池」；$archived 是过了人情味期、不再体检的
	list( $total, $done ) = riven_health_progress();
	list( , $archived )   = riven_health_pool_stats();
	// "还剩"：
	// · 普通轮转 = 到期数（没检过的 + 超过轮转间隔的）；
	// · 正在全站重扫 = 本轮还没扫的（checked_at < since）。不然一边扫一边显示
	//   "还剩 0"，看着像卡死（2026-10-17 老张就是这么被绕进去的）。
	if ( $scan_force && '' !== $scan_since && isset( $s['remaining'] ) ) {
		$remain = (int) $s['remaining'];
	} else {
		$remain = riven_health_due_count();
	}

	$last_cron = get_option( 'riven_health_last_cron' );
	if ( ! is_array( $last_cron ) ) {
		$last_cron = array();
	}
	$last_cron = array_merge(
		array( 'time' => 0, 'scanned' => 0, 'risk' => 0, 'ok' => 0, 'fail' => 0, 'remaining' => 0, 'error' => '' ),
		$last_cron
	);
	$cron_next   = wp_next_scheduled( 'riven_health_cron_hook' );
	$wp_cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

	if ( ! riven_health_table_exists() ) {
		riven_health_install();
		riven_health_purge_cache();
	}

	// 1.11.1：列表按「考核池」口径出 —— 已过人情味期的域名前台早就降级、
	// 以后也不会再体检，不再进列表（只留个数字），否则 1276 个"抓不到"里
	// 1006 个是这种，真正该看的 270 个根本找不着。
	$arch_map = riven_health_archived_map();
	// 风险域名（1.4.1 起也按考核池口径）：已归档的不列 —— 超过人情味期的域名
	// 前台早就按归档降级、以后也不再体检，列出来只会淹没真正该看的那批。
	// 归档记录仍留在库里（黑名单拦截、安全台账都不受影响），只在数字里体现。
	list( $risk_rows, $risk_total, $risk_hidden ) = riven_health_admin_split( "status = 'risk'", array(), 100 );
	list( $junk_rows, $junk_total, $junk_hidden ) = riven_health_admin_split( "status = 'junk'", array(), 100, true );
	// 1.11.2：抓不到拆成「真失效（前台已降级）」与「我们看不见（不影响显示）」两拨
	list( , $fail_total, $fail_hidden ) = riven_health_admin_split( "status = 'fail'", array(), 1 );
	list( $fail_rows, $fail_dead, $fail_blind ) = riven_health_fail_split();
	$fresh_cut   = gmdate( 'Y-m-d H:i:s', time() - riven_health_fresh_months() * MONTH_IN_SECONDS );
	$nonpersonal_rows = riven_health_nonpersonal_list( 100, $nonpersonal_hidden );   // 1.8.0：新判据的产出清单
	$nofp_rows        = riven_health_nofp_list( 100, $nofp_total );                  // 1.3.4：标题像人写但无指纹
	$suspect_body    = 0;
	$suspect_rows    = riven_health_suspect_list( 100, $suspect_total, $suspect_body ); // 1.3.0 新增；1.3.2 改为只看标题
	$suspect_ignored  = riven_health_suspect_ignored();
	$blacklist   = riven_health_blacklist();
	$whitelist   = riven_health_whitelist();
	// 1.4.0：白名单到期复查 —— 这些域名老张手动恢复过，保护期已过、重新接受检测。
	// 若它们此刻已是风险/废弃站，必须明确告诉老张，不能静默降级。
	$wl_expired  = riven_health_whitelist_expired();
	$wl_risk     = array();
	$wl_junk     = array();
	if ( ! empty( $wl_expired ) ) {
		global $wpdb;
		$t2    = riven_health_table();
		$ph    = implode( ',', array_fill( 0, count( $wl_expired ), '%s' ) );
		$rows2 = $wpdb->get_results( $wpdb->prepare( "SELECT host, status FROM {$t2} WHERE host IN ( $ph )", array_keys( $wl_expired ) ) );
		foreach ( (array) $rows2 as $r2 ) {
			if ( 'risk' === $r2->status ) {
				$wl_risk[] = (string) $r2->host;
			} elseif ( 'junk' === $r2->status ) {
				$wl_junk[] = (string) $r2->host;
			}
		}
	}
	$email_hosts = riven_health_email_hosts();
	$candidates  = riven_health_email_candidates();
	$cand_risk   = 0;
	$cand_junk   = 0;
	$cand_dead   = 0;
	foreach ( $candidates as $c ) {
		if ( 0 === $c['rank'] ) {
			$cand_risk++;
		} elseif ( 1 === $c['rank'] ) {
			$cand_junk++;
		} elseif ( 2 === $c['rank'] ) {
			$cand_dead++;
		}
	}

	$purge_url = esc_url( admin_url( 'admin.php?page=riven-health&riven_autoscan=1' ) );
	// 忽略轮转间隔的全站重扫（改完词库/阈值后用）。
	// 这个 URL 是按钮本身 —— 不带 since，点一下就是"开一轮新的重扫"。
	$purge_all_url = esc_url( admin_url( 'admin.php?page=riven-health&riven_autoscan=1&force=1' ) );
	// 自动刷新必须把本轮会话的 since 原样带走，否则每次刷新都被当成新会话
	// → 永远扫不完（2026-10-17 老张那一小时的根因）。
	$auto_now_url = $scan_force
		? esc_url( admin_url( 'admin.php?page=riven-health&riven_autoscan=1&force=1&since=' . rawurlencode( $scan_since ) ) )
		: $purge_url;
	?>
	<div class="wrap">
		<h1>外链体检</h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<?php
		/*
		 * 异常播报（1.5.0）：只报"自上次点已读之后新增的"，
		 * 不报静态总数 —— 天天看到同一个 50 只会让人麻木。
		 */
		$rcg_unread_risk = riven_health_alert_unread();
		$rcg_unread_sus  = riven_health_alert_new_suspect();
		if ( ! empty( $rcg_unread_risk ) || ! empty( $rcg_unread_sus ) ) :
			?>
			<div class="notice notice-warning">
				<p><b>自上次查看以来有新异常</b>：</p>
				<?php if ( ! empty( $rcg_unread_risk ) ) : ?>
					<p>· <b style="color:#d63638">新增风险域名 <?php echo count( $rcg_unread_risk ); ?> 个</b> ——
						<code><?php echo implode( '</code>、<code>', array_map( 'esc_html', array_slice( $rcg_unread_risk, 0, 8 ) ) ); ?></code>
						<?php echo count( $rcg_unread_risk ) > 8 ? '…' : ''; ?>
						<span class="description">（前台已自动降为纯文本）</span></p>
				<?php endif; ?>
				<?php if ( ! empty( $rcg_unread_sus ) ) : ?>
					<p>· <b>新增待复核 <?php echo count( $rcg_unread_sus ); ?> 个</b> ——
						<code><?php echo implode( '</code>、<code>', array_map( 'esc_html', array_slice( array_keys( $rcg_unread_sus ), 0, 8 ) ) ); ?></code>
						<span class="description">（标题自带风险词，还没到阈值）</span></p>
				<?php endif; ?>
				<form method="post" style="margin:0 0 12px">
					<?php wp_nonce_field( 'riven_health_action' ); ?>
					<input type="hidden" name="riven_health_action" value="alert_seen">
					<button class="button">标记为已读</button>
				</form>
			</div>
		<?php endif; ?>

		<h2>域名速查</h2>
		<p class="description">
			粘一个域名或整条链接，看它<b>每一步</b>被判成了什么 —— 为什么降级、为什么放行，一目了然。<b>只查不改</b>。
		</p>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:8px">
			<input type="hidden" name="page" value="riven-health">
			<input type="text" name="rcg_q" class="regular-text code" style="width:420px"
				value="<?php echo isset( $_GET['rcg_q'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['rcg_q'] ) ) ) : ''; ?>"
				placeholder="example.com 或 https://example.com/post/1">
			<button class="button">查判定</button>
		</form>
		<?php if ( is_array( $rcg_diag ) ) : ?>
			<?php if ( isset( $rcg_diag['error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $rcg_diag['error'] ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1040px;margin-bottom:14px">
					<thead><tr><th style="width:140px">判定步骤</th><th style="width:170px">结果</th><th>说明</th></tr></thead>
					<tbody>
						<tr>
							<td><b>域名</b></td>
							<td colspan="2">
								<code><?php echo esc_html( $rcg_diag['host'] ); ?></code>
								<span class="description">被 <b><?php echo (int) $rcg_diag['refs']; ?></b> 条已审评论引用</span>
								<?php if ( ! empty( $rcg_diag['comment'] ) ) : ?>
									<br><span class="description">代表性评论：
										<?php echo esc_html( (string) $rcg_diag['comment']->comment_author ); ?>
										（<?php echo esc_html( (string) $rcg_diag['comment']->comment_date ); ?>）
										<a href="<?php echo esc_url( (string) get_permalink( (int) $rcg_diag['comment']->comment_post_ID ) ); ?>" target="_blank">去看这条评论</a>
									</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php foreach ( (array) $rcg_diag['steps'] as $rcg_s ) : ?>
							<tr<?php echo isset( $rcg_s['used'] ) && ! $rcg_s['used'] ? ' style="opacity:.5"' : ''; ?>>
								<td><?php echo esc_html( $rcg_s['name'] ); ?></td>
								<td><?php echo esc_html( $rcg_s['res'] ); ?></td>
								<td style="font-size:12px;color:#50575e"><?php echo esc_html( (string) $rcg_s['note'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<td><b>最终结论</b></td>
							<td colspan="2">
								<?php if ( ! empty( $rcg_diag['final']['mixed'] ) ) : ?>
									<b style="color:#dba617">部分降级</b>
								<?php elseif ( ! empty( $rcg_diag['final']['allow'] ) ) : ?>
									<b style="color:#00a32a">可点击</b>
								<?php else : ?>
									<b style="color:#d63638">降级为纯文本</b>
								<?php endif; ?>
								<span class="description"> —— <?php echo esc_html( (string) $rcg_diag['final']['text'] ); ?></span>
							</td>
						</tr>
					</tbody>
				</table>

				<?php if ( ! empty( $rcg_diag['refs_list'] ) ) : ?>
					<h3 style="margin-top:6px">每条引用的实际结果（最近 <?php echo count( $rcg_diag['refs_list'] ); ?> 条，最多看 40 条）</h3>
					<table class="widefat striped" style="max-width:1040px">
						<thead><tr><th style="width:130px">访客</th><th style="width:150px">日期</th><th style="width:110px">前台</th><th>说明 / 文章</th></tr></thead>
						<tbody>
						<?php foreach ( $rcg_diag['refs_list'] as $rcg_r ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $rcg_r['comment']->comment_author ); ?></td>
								<td style="font-size:12px"><?php echo esc_html( (string) $rcg_r['comment']->comment_date ); ?></td>
								<td>
									<?php if ( $rcg_r['allow'] ) : ?>
										<span style="color:#00a32a">可点击</span>
									<?php else : ?>
										<span style="color:#d63638">降级</span>
									<?php endif; ?>
								</td>
								<td style="font-size:12px;color:#50575e">
									<?php echo $rcg_r['allow'] ? '' : esc_html( riven_link_guard_muted_tip( $rcg_r['kind'] ) ); ?>
									<a href="<?php echo esc_url( (string) get_permalink( (int) $rcg_r['comment']->comment_post_ID ) ); ?>" target="_blank">去看这篇</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( $auto_now ) : ?>
			<meta http-equiv="refresh" content="2;url=<?php echo esc_url( $auto_now_url ); ?>">
		<?php endif; ?>

		<?php
		/*
		 * 新装引导（1.5.2）：刚装上时体检表是空的，用户进来只看到一张 0 进度的表，
		 * 很容易以为"已经没事了"。这里必须说清楚：现在还没看过这些域名。
		 */
		if ( 0 === (int) $done && (int) $total > 0 ) :
			$rcg_per_est = (float) get_option( 'rcg_scan_per_item', 0 );
			if ( $rcg_per_est <= 0 ) {
				$rcg_per_est = 3;
			}
			$rcg_est_sec = (int) round( $total * $rcg_per_est );
			$rcg_est_txt = $rcg_est_sec >= 3600
				? number_format_i18n( $rcg_est_sec / 3600, 1 ) . ' 小时'
				: max( 1, (int) round( $rcg_est_sec / 60 ) ) . ' 分钟';
			?>
			<div class="notice notice-info">
				<p><b>还没体检过。</b>现在这些链接只按「访客还来不来」判断，<b>还没看过域名现在是什么站</b>。</p>
				<p>点下面的「连续扫描到完成」开始 —— 共 <b><?php echo (int) $total; ?></b> 个域名，预计 <b><?php echo esc_html( $rcg_est_txt ); ?></b>
					（每分钟约 20~30 个，只扫「1~5 年没来往」的域名）。<br>
					扫描期间页面会自己刷新推进，<b>可以切到别的标签页</b>；扫完按 7 天一轮自动复查，不用再手动点。</p>
			</div>
		<?php endif; ?>

		<h2>进度</h2>
		<table class="widefat striped" style="max-width:820px">
			<tbody>
				<tr><td style="width:200px">考核池（参与体检）</td><td><b><?php echo (int) $total; ?></b> 个 —— 只含「免考核期 ~ 人情味期」之间的域名</td></tr>
				<tr><td>不再体检</td><td><b><?php echo (int) $archived; ?></b> 个 —— 已超过人情味期（<?php echo esc_html( function_exists( 'riven_link_guard_mercy_text' ) ? riven_link_guard_mercy_text() : '5 年' ); ?>），前台已按归档降级，抓它们只是白烧 CPU</td></tr>
				<tr><td>已体检</td><td><b><?php echo (int) $done; ?></b> 个
					<?php if ( $scan_force && '' !== $scan_since ) : ?>
						· <b>本轮重扫还剩 <?php echo (int) $remain; ?> 个</b>
					<?php else : ?>
						（还剩 <?php echo (int) $remain; ?>）
					<?php endif; ?>
					<?php
					// 真实 ETA：按最近实测速度算，不再写死"几分钟"（2026-10-17 老张踩过）
					$rcg_per = (float) get_option( 'rcg_scan_per_item', 0 );
					if ( $remain > 0 && $rcg_per > 0 ) :
						$rcg_sec = (int) round( $remain * $rcg_per );
						if ( $rcg_sec >= 3600 ) {
							$rcg_human = number_format_i18n( $rcg_sec / 3600, 1 ) . ' 小时';
						} elseif ( $rcg_sec >= 90 ) {
							$rcg_human = (int) ceil( $rcg_sec / 60 ) . ' 分钟';
						} else {
							$rcg_human = (int) $rcg_sec . ' 秒';
						}
						?>
						<br><span class="description">按最近实测速度（每个约 <?php echo esc_html( number_format_i18n( $rcg_per, 1 ) ); ?> 秒）：
							<b>预计还需约 <?php echo esc_html( $rcg_human ); ?></b>。
							死域名多的批次会明显变慢 —— 每个抓不到的都要等满超时。</span>
					<?php endif; ?>
				</td></tr>
				<tr><td>抓不到</td><td><?php echo (int) $fail_total; ?> 个 —— 其中 <b><?php echo (int) $fail_dead; ?></b> 个是<b>真失效</b>（DNS 死 / 404 / 410，前台已降级成纯文本），其余 <?php echo (int) $fail_blind; ?> 个只是我们连不上（403 / 超时），<b>前台照常可点</b><?php if ( $fail_hidden > 0 ) : ?>；另有 <b><?php echo (int) $fail_hidden; ?></b> 个已归档，不再体检、也不在下方列表里<?php endif; ?></td></tr>
				<tr><td>已废弃 / 待售</td><td><?php echo (int) $junk_total; ?> 个 —— 域名已变成域名商的"待售页"，早已不是原博主的站（前台同样降级）<?php if ( $junk_hidden > 0 ) : ?>；另有 <?php echo (int) $junk_hidden; ?> 个已归档未列出<?php endif; ?></td></tr>
				<tr><td>自动轮转</td><td>
					<?php if ( ! riven_health_auto_on() ) : ?>
						<b>已关闭</b>（可在「评论设置 → 外链安全」打开）
					<?php elseif ( $cron_next ) : ?>
						已开启 · 每次自动跑 40 秒（每批 <?php echo (int) riven_health_batch(); ?> 个，连跑多批）·
						下次：<?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $cron_next ), 'm-d H:i' ) ); ?>
					<?php else : ?>
						<b style="color:#b32d2e">已开启但定时任务未排上</b> —— 到任意后台页面刷一次即可排上
					<?php endif; ?>
					<?php if ( $wp_cron_off ) : ?>
						<br><b style="color:#b32d2e">注意：wp-config.php 里禁用了 WP-Cron</b>，需按下方说明配系统计划任务。
					<?php endif; ?>
					<br><span class="description">轮转间隔 <b><?php echo (int) riven_health_ttl_days(); ?></b> 天 —— 同一个域名最多这么久会被重新抓一次；博友回来留言会立刻催检。</span>
				</td></tr>
				<tr><td>上次自动运行</td><td>
					<?php if ( empty( $last_cron['time'] ) ) : ?>
						<span class="description">还没有跑过（排上后最多一小时开始）</span>
					<?php else : ?>
						<?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $last_cron['time'] ), 'Y-m-d H:i' ) ); ?> ·
						扫描 <?php echo (int) $last_cron['scanned']; ?> 个
						（风险 <?php echo (int) $last_cron['risk']; ?> · 正常 <?php echo (int) $last_cron['ok']; ?> · 抓不到 <?php echo (int) $last_cron['fail']; ?>）·
						当时还剩 <?php echo (int) $last_cron['remaining']; ?> 个
						<?php if ( ! empty( $last_cron['error'] ) ) : ?>
							<br><b style="color:#b32d2e">出错：<?php echo esc_html( $last_cron['error'] ); ?></b>
						<?php endif; ?>
					<?php endif; ?>
				</td></tr>
				<tr><td>体检开关</td><td><?php echo riven_health_on() ? '已开启' : '<b style="color:#b32d2e">已关闭</b>'; ?> ·
					<?php echo riven_health_verdict_on() ? '判定已接入' : '判定未接入（退回纯关系判定）'; ?> ·
					<?php echo riven_health_block_on() ? '黑名单拦截开' : '黑名单拦截关'; ?></td></tr>
			</tbody>
		</table>

		<h2>本机抓取助手</h2>
		<?php
		$helper_last = get_option( 'riven_helper_last' );
		$helper_n    = count( riven_helper_pending( 500 ) );
		$helper_bat  = home_url( '/?riven_helper=script&file=bat&token=' . riven_helper_token() );
		?>
		<p class="description" style="max-width:820px">
			体检是从<b>站点服务器</b>发起的，而服务器在海外 —— 对国内博客常常连不上。
			实测那批"抓不到"的域名里，服务器只能读懂 <b>6.7%</b>，而从你本机（国内网络）能读懂 <b>24.7%</b>（3.7 倍）。<br>
			这个助手就是让体检<b>用你电脑的网络去抓</b>：双击一下，它自己拉清单、抓页面、把结果报回来 ——
			判定仍在站点端做，两边标准完全一致，抓到什么都不会绕过任何规则。
		</p>
		<table class="widefat striped" style="max-width:820px">
			<tbody>
				<tr><td style="width:160px">现在可以帮忙抓</td><td><b><?php echo (int) $helper_n; ?></b> 个域名 —— 服务器抓不到、但域名还活着的</td></tr>
				<tr><td>上次本机上报</td><td>
					<?php if ( is_array( $helper_last ) && ! empty( $helper_last['time'] ) ) : ?>
						<?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $helper_last['time'] ), 'Y-m-d H:i' ) ); ?> ·
						抓回 <?php echo (int) $helper_last['got']; ?>/<?php echo (int) $helper_last['total']; ?> 个
						（正常 <?php echo (int) $helper_last['ok']; ?> · 风险 <?php echo (int) $helper_last['risk']; ?> · 待售 <?php echo (int) $helper_last['junk']; ?> · 没抓到 <?php echo (int) $helper_last['fail']; ?>）
					<?php else : ?>
						还没跑过
					<?php endif; ?>
				</td></tr>
				<tr><td>令牌</td><td><code style="user-select:all;font-size:11px"><?php echo esc_html( riven_helper_token() ); ?></code><br>
					<span class="description">已经内置在下载的脚本里，你一般不用管它</span></td></tr>
			</tbody>
		</table>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $helper_bat ); ?>">下载助手（link-helper.bat）</a>
			<form method="post" style="display:inline-block;margin-left:8px">
				<?php wp_nonce_field( 'riven_health_action' ); ?>
				<input type="hidden" name="riven_health_action" value="reset_token">
				<button class="button" onclick="return confirm('重置后旧脚本会失效，需要重新下载。确定？')">重置令牌</button>
			</form>
			<span class="description">
				把下载到的 <code>link-helper.bat</code> 放到桌面，<b>双击</b>即可（它会自己去取最新的抓取脚本，
				以后逻辑升级不用重新下载）。跑完窗口会停住显示结果，看完按任意键关闭。一次大约 2~5 分钟。
			</span>
		</p>

		<?php if ( riven_health_auto_on() ) : ?>
			<details style="max-width:820px;margin:8px 0 0">
				<summary style="cursor:pointer;color:#2271b1">站点访问量很小、或想更准时？在「宝塔 → 计划任务」加一条系统任务（推荐）</summary>
				<p class="description" style="margin:8px 0 0">
					任务类型「Shell 脚本」，执行周期「每 10 分钟」，脚本内容：<br>
					<code style="display:inline-block;margin-top:6px;padding:6px 8px;background:#f6f7f7">cd <?php echo esc_html( ABSPATH ); ?> &amp;&amp; /www/server/php/<?php echo esc_html( PHP_MAJOR_VERSION . PHP_MINOR_VERSION ); ?>/bin/php wp-cron.php &gt;/dev/null 2&gt;&amp;1</code><br>
					配了它以后，体检每小时都会准时跑，不用等访客访问来触发 WordPress 的定时任务。
				</p>
			</details>
		<?php endif; ?>

		<h2>风险词库</h2>
		<?php
		$lex_now   = riven_health_lexicon();
		$lex_count = 0;
		$lex_parts = array();
		$cat_zh    = array( 'gamble' => '博彩', 'adult' => '色情', 'fortune' => '算命', 'scam' => '诈骗', 'strong' => '高置信' );
		foreach ( $lex_now as $cat => $words ) {
			$lex_count += count( $words );
			$lex_parts[] = ( isset( $cat_zh[ $cat ] ) ? $cat_zh[ $cat ] : $cat ) . ' ' . count( $words );
		}
		// 1.3.0：站点性质词单独统计（它不参与风险打分，所以不在 $lex_now 里）
		$rcg_plat    = riven_health_platform_terms();
		$plat_count  = count( $rcg_plat['strong'] ) + count( $rcg_plat['weak'] );
		$lex_parts[] = '站点性质 ' . $plat_count;
		$extra_raw  = (string) rcg_opt( 'health_extra_words', '' );
		$ignore_raw = (string) rcg_opt( 'health_ignore_words', '' );
		$sett_url   = admin_url( 'admin.php?page=riven-settings&tab=comment#riven-settings' );

		// 全站重扫要多久：按实测速度算。
		// 2026-10-17 老张踩过：界面上写"2~8 分钟"，实际跑了半小时 —— 因为耗时几乎
		// 全花在"抓不到的域名要等满超时"上，跟域名总数不是线性关系。
		$rcg_per_item = (float) get_option( 'rcg_scan_per_item', 0 );
		if ( $rcg_per_item <= 0 ) {
			$rcg_per_item = 3.0; // 还没跑过任何一批：按 55 秒预算 / 15 个保守估
		}
		$rcg_secs_all = (int) round( $total * $rcg_per_item );
		if ( $rcg_secs_all >= 3600 ) {
			$rcg_eta_all = sprintf(
				'预计 %.1f~%.1f 小时',
				max( 0.1, $rcg_secs_all * 0.7 / 3600 ),
				max( 0.2, $rcg_secs_all * 1.3 / 3600 )
			);
		} elseif ( $rcg_secs_all >= 90 ) {
			$rcg_eta_all = sprintf(
				'预计 %d~%d 分钟',
				max( 1, (int) floor( $rcg_secs_all * 0.7 / 60 ) ),
				max( 2, (int) ceil( $rcg_secs_all * 1.3 / 60 ) )
			);
		} else {
			$rcg_eta_all = sprintf( '预计约 %d 秒', $rcg_secs_all );
		}
		?>
		<table class="widefat striped" style="max-width:820px">
			<tbody>
				<tr><td style="width:160px">当前词库</td><td><b><?php echo (int) $lex_count; ?></b> 个词（<?php echo esc_html( implode(' · ', $lex_parts) ); ?>）</td></tr>
				<tr><td>判定阈值</td><td>普通词命中 1 个 +10 分；<b>「高置信」类</b>（组合词 / 平台名，正常站几乎不会出现）命中 1 个 +30 分。总分达到 <b><?php echo (int) riven_health_threshold(); ?></b> 分判为风险</td></tr>
				<tr><td>站点性质词</td><td><b><?php echo (int) $plat_count; ?></b> 个（交易所 / 官网入口 / 直播网 / 软件库…）<br>
					<span class="description">与风险词<b>分开</b>：只在<b>标题</b>里统计，不参与打分。<b>strong 命中 2 个</b>（或 1 强 + 1 弱、或 3 个弱）判「已不是个人站」→ 前台降级，但<b>不算风险</b>、不进黑名单。专门对付"老域名被买走改成交易所/资源站"这种。</span></td></tr>
				<tr><td>后台自定义词</td><td><?php echo '' === trim( $extra_raw ) ? '<span class="description">还没加过</span>' : '<b>' . esc_html( $extra_raw ) . '</b>'; ?></td></tr>
				<tr><td>停用词</td><td><?php echo '' === trim( $ignore_raw ) ? '<span class="description">无</span>' : '<b>' . esc_html( $ignore_raw ) . '</b>'; ?></td></tr>
			</tbody>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( $sett_url ); ?>">编辑词库（加词 / 停用词）</a>
			<form method="post" style="display:inline-block;margin:0">
				<?php wp_nonce_field( 'riven_health_action' ); ?>
				<input type="hidden" name="riven_health_action" value="rejudge_lexicon">
				<button class="button">按现行词库重判（不重抓）</button>
			</form>
			<a class="button" href="<?php echo esc_url( $purge_all_url ); ?>" onclick="return confirm('将忽略轮转间隔，把考核池里的全部 <?php echo (int) $total; ?> 个域名重扫一遍（<?php echo esc_js( $rcg_eta_all ); ?>；已超过人情味期的 <?php echo (int) $archived; ?> 个不再体检）。页面会自动刷新推进，跑完会自己停。改完词库/阈值后用它才看得到效果。现在开始？');">改完词库 → 重扫考核池</a>
			<span class="description">词库是"只加不删"的，「停用词」是唯一能减的办法。<br>
			<b>词库一变，插件会自动按新词把存量记录重判一次</b>（只认标题、不重新抓取，几十毫秒跑完），所以升级后通常会看到风险域名多出几个 —— 那是补词揪出来的旧域名，不是误报。上面的按钮供你手动再跑一次对账。</span>
		</p>

		<h2>扫描</h2>
		<p>
			<form method="post" style="display:inline-block;margin-right:8px">
				<?php wp_nonce_field( 'riven_health_action' ); ?>
				<input type="hidden" name="riven_health_action" value="scan">
				<button class="button button-primary">扫描下一批（<?php echo (int) riven_health_batch(); ?> 个）</button>
			</form>
			<a class="button" href="<?php echo esc_url( $purge_url ); ?>" onclick="return confirm('将连续扫描直到跑完考核池里全部到期域名（<?php echo esc_js( $remain > 0 ? $rcg_eta_all : '当前没有到期域名' ); ?>，页面会自动刷新推进）。现在开始？');">连续扫描到完成</a>
			<span class="description">每批 <?php echo (int) riven_health_batch(); ?> 个、20 路并发，单次请求最长 <?php echo (int) riven_health_request_budget(); ?> 秒（取不完的自动留到下一批），靠页面自动刷新推进，<b>跑到剩余 0 会自己停</b>。<br>
			<b>每一批都是独立请求、独立落库</b>：切到别的标签、把窗口最小化都不会丢进度。万一页面变成白屏，那是某一批撞上服务器单请求时限被掐了（死域名多的批次最容易），<b>直接刷新本页</b>——已扫的结果都在库里，「已体检 / 还剩」会如实反映，再点一次就接着往下走。</span>
		</p>

		<?php
		/*
		 * 风险清单的来源拆分（2026-10-17 老张对比两站时的核心困惑）：
		 * 「风险 39 个」里绝大多数可能根本不是这轮扫出来的，而是历史拉黑的实锤。
		 * 两站的扫描判据可以完全一致，但黑名单是**各站独立台账**，不拆开显示就会
		 * 误以为"同源数据库扫出了不同结果"。
		 */
		$rcg_bl_set  = array_map( 'strtolower', (array) $blacklist );
		$rcg_risk_bl = 0;
		$rcg_risk_lx = 0;
		foreach ( (array) $risk_rows as $rcg_r ) {
			if ( in_array( strtolower( $rcg_r->host ), $rcg_bl_set, true ) ) {
				$rcg_risk_bl++;
			} elseif ( isset( $rcg_r->fail_reason ) && 'lexicon' === $rcg_r->fail_reason ) {
				// 1.4.2：词库升级后按标题补判的（不是本轮抓出来的）——
				// 必须标出来，否则站长会以为"凭空冒出一堆风险域名"。
				$rcg_risk_lx++;
			}
		}
		$rcg_risk_scan = count( (array) $risk_rows ) - $rcg_risk_bl - $rcg_risk_lx;
		?>
		<h2>风险域名（<?php echo (int) $risk_total; ?>）</h2>
		<p class="description">体检实锤为风险的域名：无论访客多活跃，其链接一律降为纯文本，且可一键拉黑（前台提交时直接拒绝）。<br>
			其中 <b><?php echo (int) $rcg_risk_bl; ?></b> 个来自<b>黑名单</b>（历次体检攒下的实锤，长期拦截、不依赖本轮是否扫到），
			<b><?php echo (int) $rcg_risk_scan; ?></b> 个是本轮体检当场判出来的<?php if ( $rcg_risk_lx > 0 ) : ?>，
			<b><?php echo (int) $rcg_risk_lx; ?></b> 个是<b>词库升级后按标题补判</b>的（不是重新抓的，所以没有新的检查时间）<?php endif; ?>。
			<b>黑名单是每个站独立的台账，不会自动同步</b>——两个站数字不一样，先看这一项差多少。<br>
			<b>两站判定不一致时看这里</b>：体检结论是"扫描那一刻"的快照，词是后来加的，另一个站没重扫就还是旧结论。
			从 1.4.2 起词库一变会自动重判存量，这种不一致会自己消失。<br>
			这张表按「考核池」口径出：<b>已过人情味期的不列</b>（前台早就按归档降级，以后也不再体检）。
			<?php if ( $risk_hidden > 0 ) : ?>
				<span style="color:#646970">另有 <b><?php echo (int) $risk_hidden; ?></b> 个已归档，不再列入。</span>
			<?php endif; ?>
		</p>
		<table class="widefat striped">
			<thead><tr><th>域名</th><th style="width:70px">分数</th><th>命中词</th><th>页面标题</th><th style="width:200px">操作</th></tr></thead>
			<tbody>
			<?php if ( empty( $risk_rows ) ) : ?>
				<tr><td colspan="5">暂无</td></tr>
			<?php else : ?>
			<?php foreach ( $risk_rows as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( $r->host ); ?></code>
						<?php if ( isset( $r->fail_reason ) && 'lexicon' === $r->fail_reason ) : ?>
							<span class="description" style="color:#8c8f94;white-space:nowrap">（词库补判）</span>
						<?php endif; ?>
					</td>
						<td><?php echo (int) $r->score; ?></td>
						<td style="font-size:12px;color:#666"><?php echo esc_html( $r->hits ); ?></td>
						<td style="font-size:12px"><?php echo esc_html( mb_substr( $r->title, 0, 50 ) ); ?></td>
						<td>
							<?php if ( riven_health_is_blacklisted( $r->host ) ) : ?>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'riven_health_action' ); ?>
									<input type="hidden" name="riven_health_action" value="unblacklist">
									<input type="hidden" name="host" value="<?php echo esc_attr( $r->host ); ?>">
									<button class="button button-small">取消拉黑</button>
								</form>
							<?php else : ?>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'riven_health_action' ); ?>
									<input type="hidden" name="riven_health_action" value="blacklist">
									<input type="hidden" name="host" value="<?php echo esc_attr( $r->host ); ?>">
									<button class="button button-small">拉黑</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h2>待复核 · 标题自带风险词（<?php echo (int) $suspect_total; ?>）</h2>
		<p class="description">
			<b>只看网站标题。</b>标题是站主给整个站定的调子 —— 标题里写着"皇冠网址""开云在线"，
			这个站干的就是这件事；而正文里出现"法事""中奖""钓鱼""算命"，多半只是某篇文章在讲自己的经历
			或转载新闻。<br>
			所以这里列的，是<b>标题里就有风险词、但总分还没到 <?php echo (int) riven_health_threshold(); ?> 分</b>的域名：
			该拉黑的点拉黑，确实是个人站的点"忽略"（以后不再出现）。
			<b>表是空的才是正常状态</b>，不用每天来翻。<br>
			<?php if ( $suspect_body > 0 ) : ?>
				<span style="color:#646970">另有 <b><?php echo (int) $suspect_body; ?></b> 个域名正文提到过风险词、标题干净 ——
				这类基本都是博客在讨论（写自己被诈骗、聊赌博危害、转载新闻），不列出、不打扰。</span>
			<?php endif; ?>
			<?php if ( ! empty( $suspect_ignored ) ) : ?>
				<span style="color:#646970">已人工忽略 <b><?php echo count( $suspect_ignored ); ?></b> 个。标题已被判为"商业站点"的不在这里列（见下方「已不是个人站」）。</span>
			<?php else : ?>
				标题已被判为"商业站点"的不在这里列（见下方「已不是个人站」）。
			<?php endif; ?>
		</p>
		<div style="max-height:520px;overflow:auto;border:1px solid #c3c4c7;border-radius:4px">
		<table class="widefat striped" style="border:0">
			<thead><tr><th style="width:180px">域名</th><th style="width:72px">标题分</th><th>标题里的风险词</th><th>页面标题</th><th style="width:150px">操作</th></tr></thead>
			<tbody>
			<?php if ( empty( $suspect_rows ) ) : ?>
				<tr><td colspan="5">暂无 —— 说明没有哪个站的标题里带着风险词还漏在外面，这是正常状态</td></tr>
			<?php else : ?>
				<?php foreach ( $suspect_rows as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r->host ); ?></code></td>
						<td style="font-weight:500"><?php echo (int) $r->title_score; ?></td>
						<td style="font-size:12px;color:#666"><?php echo esc_html( $r->title_hits ); ?></td>
						<td style="font-size:12px"><?php echo esc_html( mb_substr( (string) $r->title, 0, 46 ) ); ?></td>
						<td>
							<form method="post" style="display:inline">
								<?php wp_nonce_field( 'riven_health_action' ); ?>
								<input type="hidden" name="riven_health_action" value="blacklist">
								<input type="hidden" name="host" value="<?php echo esc_attr( $r->host ); ?>">
								<button class="button button-small">拉黑</button>
							</form>
							<form method="post" style="display:inline">
								<?php wp_nonce_field( 'riven_health_action' ); ?>
								<input type="hidden" name="riven_health_action" value="suspect_ignore">
								<input type="hidden" name="host" value="<?php echo esc_attr( $r->host ); ?>">
								<button class="button button-small">忽略</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>

		<h2>已废弃 / 待售域名（<?php echo (int) $junk_total; ?>）</h2>
		<p class="description">体检发现这些域名已经变成<b>域名商的"待售 / 停靠页"</b>（页面只有一句"此域名出售"之类的模板内容）。它们不是黄赌风险，但域名早就不是原来那位博主的了。前台与风险站一样降为纯文本。<br>
			<b>看错了就点"误判·恢复"</b>：该域名进人工白名单，前台立刻恢复可点，以后也不再抓它。
			正常 JS 博客（Astro / VitePress 之类）的 HTML 里也没有正文、页面也很小，
			<b>标题是人写的就不该判废弃</b>（1.3.3 起已按这条收窄，airy.ink、hahaha.cc 这类不会再进去）。<br>
			<?php if ( $junk_hidden > 0 ) : ?>
				<span style="color:#646970">另有 <b><?php echo (int) $junk_hidden; ?></b> 个已过人情味期（<?php echo esc_html( function_exists( 'riven_link_guard_mercy_text' ) ? riven_link_guard_mercy_text() : '5 年' ); ?>）—— 前台早已按归档降级，不再列入。</span>
			<?php else : ?>
				已过人情味期的域名不再列入（前台早已降级）。
			<?php endif; ?>
			<br>
			<form method="post" style="margin-top:6px" onsubmit="return confirm('按新规则复判全部「已废弃」域名？不会重新抓取，只按已存的标题重新判。');">
				<?php wp_nonce_field( 'riven_health_action' ); ?>
				<input type="hidden" name="riven_health_action" value="rejudge_junk">
				<button class="button button-small">按新规则复判全部</button>
				<span style="color:#646970;font-size:12px">升级到 1.3.3 后点一次，把存量里被误判的（标题是人写的）改成"抓不到"——前台立即恢复可点</span>
			</form>
		</p>
		<table class="widefat striped">
			<thead><tr><th>域名</th><th style="width:70px">分数</th><th>命中词</th><th>页面标题</th><th style="width:110px">操作</th></tr></thead>
			<tbody>
			<?php if ( empty( $junk_rows ) ) : ?>
				<tr><td colspan="5">暂无</td></tr>
			<?php else : ?>
				<?php foreach ( $junk_rows as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r->host ); ?></code></td>
						<td><?php echo (int) $r->score; ?></td>
						<td style="font-size:12px;color:#666"><?php echo esc_html( $r->hits ); ?></td>
						<td style="font-size:12px"><?php echo esc_html( mb_substr( $r->title, 0, 50 ) ); ?></td>
						<td>
							<form method="post" style="display:inline" onsubmit="return confirm('确认 <?php echo esc_attr( $r->host ); ?> 是误判？该域名会进白名单，前台恢复可点。');">
								<?php wp_nonce_field( 'riven_health_action' ); ?>
								<input type="hidden" name="riven_health_action" value="whitelist">
								<input type="hidden" name="host" value="<?php echo esc_attr( $r->host ); ?>">
								<button class="button button-small">误判·恢复</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h2>已不是个人站（<?php echo count( $nonpersonal_rows ); ?>）</h2>
		<p class="description">
			体检<b>抓到了</b>这些站点，但它们是"<b>已经不是个人站</b>"的样子：标题是<b>商业站点句式</b>
			（交易所 / 官网入口 / 直播网 / 软件库…，1.3.0 新增）、域名商模板页、
			或者标题把自己域名顶在最前面（"www.xxx.com-官网首页"）—— 域名多半已经转手。<br>
			<b>只有"久未往来"的访客填的这些链接会降级</b>；近一年还在往来的不受影响（他在用，说明还是他的）。
			抓不到的一律不管 —— 看不清不等于它坏了。<br>
			<b>1.3.4 起判据收窄</b>：原来"没有任何博客特征"就算不是个人站，误判了一批真博客
			（它们的首页就是不带指纹）。现在<b>标题像人写的（猫客印象 / 随望淡思 / 老麦）一律算个人站</b>，
			不再降级；这类站移到下方「暂无指纹·待观察」里供参考，不影响显示。
			<?php if ( $nonpersonal_hidden > 0 ) : ?>
				<br><span style="color:#646970">另有 <b><?php echo (int) $nonpersonal_hidden; ?></b> 个已过人情味期 —— 前台早已按归档降级，不再列入。</span>
			<?php endif; ?>
			<br>
			<form method="post" style="margin-top:6px" onsubmit="return confirm('对清单内全部域名重新抓取复核？会跑一批（约 60 个），可多次点击直到还剩 0。');">
				<?php wp_nonce_field( 'riven_health_action' ); ?>
				<input type="hidden" name="riven_health_action" value="rescan_list">
				<input type="hidden" name="list_kind" value="nonpersonal">
				<button class="button button-small">扫描复核</button>
				<span style="color:#646970;font-size:12px">只重扫这个清单里的域名，不用跑整个考核池</span>
			</form>
		</p>
		<table class="widefat striped">
			<thead><tr><th>域名</th><th>标题</th><th style="width:150px">操作</th></tr></thead>
			<tbody>
			<?php if ( empty( $nonpersonal_rows ) ) : ?>
				<tr><td colspan="3">暂无</td></tr>
			<?php else : ?>
				<?php foreach ( $nonpersonal_rows as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r->host ); ?></code></td>
						<td style="font-size:12px"><?php echo esc_html( mb_substr( $r->title, 0, 60 ) ); ?></td>
						<td>
							<form method="post" style="display:inline">
								<?php wp_nonce_field( 'riven_health_action' ); ?>
								<input type="hidden" name="riven_health_action" value="whitelist">
								<input type="hidden" name="host" value="<?php echo esc_attr( $r->host ); ?>">
								<button class="button button-small">其实是个站？放行</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h2>暂无指纹 · 待观察（<?php echo (int) $nofp_total; ?>）</h2>
		<p class="description">
			这些站<b>抓到了、页面正常，但首页不带任何机器指纹</b>（没有 RSS / CMS / 标题里也没有"博客"二字），
			而<b>标题是人写的</b>（猫客印象、随望淡思、老麦这种网名意境词）—— 正是 1.3.4 之前被
			误判成"已不是个人站"的那一批。<br>
			<b>它们前台不再降级</b>：抓不到指纹只说明我们看不全，不能反过来说"域名转手了"。
			<b>标题自称是商业机构的（公司 / 工作室 / 信息网 / 搜索 / 导航 / 平台…）已经自动拦到上面去了</b>，
			这里剩下的基本都是真博客，正常状态就该是这么一屏，不用逐个看。<br>
			万一看到明显不像个人站的，照样可以点"拉黑"。
		</p>
		<table class="widefat striped">
			<thead><tr><th>域名</th><th style="width:60px">分数</th><th>标题</th><th style="width:80px">操作</th></tr></thead>
			<tbody>
			<?php if ( empty( $nofp_rows ) ) : ?>
				<tr><td colspan="4">暂无</td></tr>
			<?php else : ?>
				<?php foreach ( $nofp_rows as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r->host ); ?></code></td>
						<td><?php echo (int) $r->score; ?></td>
						<td style="font-size:12px"><?php echo esc_html( mb_substr( (string) $r->title, 0, 60 ) ); ?></td>
						<td>
							<form method="post" style="display:inline">
								<?php wp_nonce_field( 'riven_health_action' ); ?>
								<input type="hidden" name="riven_health_action" value="blacklist">
								<input type="hidden" name="host" value="<?php echo esc_attr( $r->host ); ?>">
								<button class="button button-small">拉黑</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h2>已失效链接（<?php echo (int) $fail_dead; ?>）</h2>
		<p class="description">
			<b>DNS 已死、首页 404 / 410</b> —— 这些链接访客<b>已经点不动了</b>（前台渲染成纯文本）。
			多半是博友的站挂了、或者换了域名：到下方「博友换域名」里搜同一个邮箱，能直接把旧域名换掉。<br>
			<?php if ( $fail_blind > 0 ) : ?>
				另外 <b><?php echo (int) $fail_blind; ?></b> 个只是<b>我们连不上</b>（403 / 超时 / 5xx / 空壳页）——
				服务器在海外，看不见不等于坏了，<b>前台照常可点</b>，判定一点没变，所以不在这里列。
			<?php endif; ?>
		<?php if ( $fail_hidden > 0 ) : ?>
			<span style="color:#646970">已过人情味期的 <b><?php echo (int) $fail_hidden; ?></b> 个也不再列（前台早就是纯文本，以后不会再去抓）。</span>
		<?php endif; ?>
		<br>
		<form method="post" style="margin-top:6px" onsubmit="return confirm('对清单内全部域名重新抓取复核？会跑一批（约 60 个），可多次点击直到还剩 0。');">
			<?php wp_nonce_field( 'riven_health_action' ); ?>
			<input type="hidden" name="riven_health_action" value="rescan_list">
			<input type="hidden" name="list_kind" value="fail">
			<button class="button button-small">扫描复核</button>
			<span style="color:#646970;font-size:12px">只重扫这个清单里的域名，不用跑整个考核池；博友的站恢复了会从这里消失、前台恢复可点</span>
		</form>
		</p>
		<div style="max-height:600px;overflow:auto;border:1px solid #c3c4c7;border-radius:4px">
		<table class="widefat striped" style="border:0">
			<thead><tr><th>域名</th><th style="width:120px">原因</th><th style="width:160px">最后检查</th></tr></thead>
			<tbody>
			<?php if ( empty( $fail_rows ) ) : ?>
				<tr><td colspan="3">暂无</td></tr>
			<?php else : ?>
				<?php foreach ( $fail_rows as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r->host ); ?></code></td>
						<td style="font-size:12px"><?php echo esc_html( $r->fail_reason ); ?></td>
						<td style="font-size:12px"><?php echo esc_html( substr( (string) $r->checked_at, 0, 16 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>

		<h2>人工白名单（<?php echo count( $whitelist ); ?>）</h2>
		<p class="description">
			你手动放行的域名：不再参与关系判定（体检实锤风险仍会拦）。<br>
			<b>1.4.0 起白名单有保护期（默认 <?php echo (int) riven_health_whitelist_ttl(); ?> 天，可在设置里改）</b>：
			到期后域名重新接受检测 —— 域名随时可能被弃用，永久免检等于一直开着风险敞口。
			到期后若真变成了风险/废弃站会正常降级，并在下方「白名单到期复查」里明确列出，不会静默处理。
		</p>
		<?php if ( ! empty( $wl_risk ) || ! empty( $wl_junk ) ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 12px">
				<p><b>白名单到期复查：</b>
					<?php if ( ! empty( $wl_risk ) ) : ?>
						<span style="color:#d63638">已变成风险站 <?php echo count( $wl_risk ); ?> 个（<?php echo esc_html( implode( '、', array_slice( $wl_risk, 0, 8 ) ) ); ?><?php echo count( $wl_risk ) > 8 ? '…' : ''; ?>）</span>
					<?php endif; ?>
					<?php if ( ! empty( $wl_junk ) ) : ?>
						<?php if ( ! empty( $wl_risk ) ) : ?>　·　<?php endif; ?><span>已变成待售/停靠页 <?php echo count( $wl_junk ); ?> 个</span>
					<?php endif; ?>
					<br>这些域名你之前手动恢复过，保护期已过、重新检测后变了样。前台已按新结论处理，
					确认没问题就再点一次「误判·恢复」续期，确实变坏了就到黑名单里拉黑。
				</p>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $whitelist ) ) : ?>
			<p>
				<?php foreach ( $whitelist as $w => $added ) : ?>
					<?php
					$expired = ( '' !== (string) $added && ! riven_health_is_whitelisted( $w ) );
					$days    = ( '' !== (string) $added && ! $expired )
						? max( 0, (int) ( ( strtotime( (string) $added ) + riven_health_whitelist_ttl() * DAY_IN_SECONDS - time() ) / DAY_IN_SECONDS ) )
						: null;
					?>
					<form method="post" style="display:inline-block;margin:0 6px 6px 0">
						<?php wp_nonce_field( 'riven_health_action' ); ?>
						<input type="hidden" name="riven_health_action" value="unwhitelist">
						<input type="hidden" name="host" value="<?php echo esc_attr( $w ); ?>">
						<button class="button button-small" title="<?php echo $expired ? '保护期已过，点击仅移出名单' : esc_attr( '还剩 ' . $days . ' 天' ); ?>">
							<code><?php echo esc_html( $w ); ?></code>
							<?php if ( $expired ) : ?>
								<span style="color:#d63638">已过期</span>
							<?php elseif ( null !== $days ) : ?>
								<span style="color:#646970"><?php echo (int) $days; ?>天</span>
							<?php endif; ?>
							✕
						</button>
					</form>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<h2>黑名单（<?php echo count( $blacklist ); ?>）</h2>
		<p class="description">
			黑名单是<b>每个站独立的台账</b>：命中即拦（前台提交直接拒绝），而且<b>不受</b>体检开关、判定开关、轮转间隔的影响。
			多站运营时用下面的导出/导入搬运 —— 两个站的风险数字对不上，先比这一项差多少。
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rcg_export_blacklist' ), 'rcg_export_blacklist' ) ); ?>">导出黑名单（JSON）</a>
			<span class="description">导出的文件同时给了域名清单和导出时间，可以直接当备份。</span>
		</p>
		<details style="margin:0 0 14px">
			<summary style="cursor:pointer;color:#2271b1">从另一个站导入（合并，不会清空现有名单）</summary>
			<form method="post" enctype="multipart/form-data" style="margin-top:10px;max-width:760px">
				<?php wp_nonce_field( 'riven_health_action' ); ?>
				<input type="hidden" name="riven_health_action" value="import_blacklist">
				<textarea name="rcg_blacklist_text" rows="5" class="large-text code" placeholder="把另一个站导出的 JSON 全文粘在这里；也可以直接一行一个域名"></textarea>
				<p><input type="file" name="rcg_blacklist_file" accept=".json,.txt"> <span class="description">或选择导出的 .json 文件</span></p>
				<p><button class="button button-primary">导入并合并</button>
					<span class="description">只加不删；已在名单里的会跳过。导入的域名会当场生效（前台立即拦截）。</span></p>
			</form>
		</details>
		<?php if ( ! empty( $blacklist ) ) : ?>
			<p>
				<?php foreach ( $blacklist as $b ) : ?>
					<form method="post" style="display:inline-block;margin:0 6px 6px 0">
						<?php wp_nonce_field( 'riven_health_action' ); ?>
						<input type="hidden" name="riven_health_action" value="unblacklist">
						<input type="hidden" name="host" value="<?php echo esc_attr( $b ); ?>">
						<button class="button button-small"><code><?php echo esc_html( $b ); ?></code> ✕</button>
					</form>
				<?php endforeach; ?>
			</p>
		<?php else : ?>
			<p class="description">暂无。</p>
		<?php endif; ?>

		<h2>博友换域名（候选 <?php echo (int) count( $candidates ); ?> 个邮箱）</h2>
		<p class="description">
			同一个邮箱先后填过多个域名 —— 多半是博友换了站。<b>已按"该不该动手"排序，全部列出</b>：
			<?php if ( $cand_risk > 0 ) : ?>
				<b style="color:#d63638"><?php echo (int) $cand_risk; ?> 个域名已被实锤为风险站</b>、
			<?php endif; ?>
			<?php if ( $cand_junk > 0 ) : ?>
				<b style="color:#996800"><?php echo (int) $cand_junk; ?> 个成了待售页</b>、
			<?php endif; ?>
			<?php if ( $cand_dead > 0 ) : ?>
				<b><?php echo (int) $cand_dead; ?> 个已经打不开</b>，
			<?php endif; ?>
			这几类排最前；域名都还活着的是他自己有好几个站，排最后，通常不用管。<br>
			<b>「已归档」</b>＝这位博友已超过人情味期没来，这条链接前台<b>本来就是纯文本</b>。
			它和「风险 / 待售 / 已失效」是两个维度：域名可能既是风险站、又已归档，两个标签会并列显示。<br>
			替换会改动评论表（<b>唯一会写数据库的操作</b>），执行前请先备份；替换后可一键撤销。
		</p>
		<form method="post" style="margin:12px 0">
			<?php wp_nonce_field( 'riven_health_action' ); ?>
			<input type="hidden" name="riven_health_action" value="replace">
			旧域名 <input type="text" name="host" placeholder="old.example.com" style="width:220px">
			→ 新域名 <input type="text" name="host_to" placeholder="new.example.com" style="width:220px">
			<label style="margin-left:10px"><input type="checkbox" name="confirm" value="1"> 我已备份数据库</label>
			<button class="button button-primary" style="margin-left:10px">执行替换</button>
		</form>
		<form method="post" style="margin:0 0 12px">
			<?php wp_nonce_field( 'riven_health_action' ); ?>
			<input type="hidden" name="riven_health_action" value="undo_replace">
			<button class="button" onclick="return confirm('撤销最近一次替换？')">撤销最近一次替换</button>
		</form>
		<div style="max-height:620px;overflow:auto;border:1px solid #c3c4c7;border-radius:4px">
		<table class="widefat striped" style="border:0">
			<thead><tr><th style="width:200px">邮箱</th><th>填过的域名（最后时间 / 条数）</th><th style="width:100px">最近来访</th></tr></thead>
			<tbody>
			<?php if ( empty( $candidates ) ) : ?>
				<tr><td colspan="3">暂无</td></tr>
			<?php else : ?>
				<?php
				// 面板要跟前台一个口径：已过人情味期的域名前台是纯文本，
				// 这里就得标出来，不能让"没有标签"被读成"没问题"。
				$arch_map = riven_health_archived_map();
				foreach ( $candidates as $c ) :
					?>
					<tr>
						<td style="font-size:12px"><?php echo esc_html( $c['email'] ); ?></td>
						<td style="font-size:12px">
							<?php
							$parts = array();
							$seq   = 0;
							foreach ( $c['hosts'] as $hh => $meta ) {
								$mark = '';
								if ( 0 === $seq ) {
									// 时间最新的一个就是他"现在在用的"
									$mark .= '<span style="display:inline-block;padding:0 6px;margin-left:4px;border-radius:3px;font-size:11px;line-height:18px;background:#eef6ff;color:#1d4f91;border:1px solid #1d4f9133">最新</span>';
								}
								// 标签和「最新」是两回事情：他最新在用的那个域名本身就可能已经变成风险站，
								// 不能因为标了「最新」就把风险标签吞掉（1.11.2 自测时发现）
								if ( ! empty( $c['tags'][ $hh ] ) ) {
									// 风险 / 待售 / 死链 / 抓不到：域名体检出来的客观状态
									$mark .= riven_health_candidate_tag_html( $c['tags'][ $hh ] );
								}
								if ( ! empty( $arch_map[ $hh ] ) ) {
									// 1.11.3：「归档」是另一个维度 —— 前台现在怎么显示。
									// 一个域名可以既是风险站、又已归档（降级理由取风险），
									// 也可以只是归档。两者并列显示，别用一个盖掉另一个。
									$mark .= '<span style="display:inline-block;padding:0 6px;margin-left:4px;border-radius:3px;font-size:11px;line-height:18px;background:#f6f1e7;color:#8a6d3b;border:1px solid #8a6d3b33" title="这位访客已超过人情味期没来，该域名在前台是纯文本">已归档</span>';
								}
								$parts[] = '<code>' . esc_html( $hh ) . '</code>' . $mark
									. '（' . esc_html( substr( (string) $meta['last'], 0, 10 ) ) . ' / ' . (int) $meta['count'] . ' 条）';
								$seq++;
							}
							echo implode( '<br>', $parts );   // 一行一个域名，4 个域名也不会挤成一团
							?>
						</td>
						<td style="font-size:12px"><?php echo esc_html( substr( (string) $c['last'], 0, 10 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>
	<?php
}

/* ==================================================== 本机抓取助手（接口） */
/*
 * 为什么需要它
 * ------------
 * 体检是从站点服务器发起的，而服务器在海外 —— 对国内博客的覆盖率很低。
 * 实测：从"抓不到"的域名里抽 150 个，站服务器只能读懂 6.7%（10 个），
 * 从站长本机（国内网络）能读懂 24.7%（37 个），**3.7 倍**。
 *
 * 所以给站长一个"双击就跑"的小工具：用他本机的网去抓，把**提取好的特征**
 * 回传给站点。注意分工 ——
 *   本机：只负责 抓页面 + 提取特征（标题/正文/RSS/日期/博客特征）
 *   站点：负责 打分 + 定状态 + 落库（riven_health_finalize）
 * 判定逻辑只有一份，本机端不需要复刻词库和规则，也不会出现两套口径。
 *
 * 鉴权：一个随机 token（后台能看、能重置）。没有 token 谁也调不动。
 * 安全：push 只接受"站内确实存在的域名"，且字段白名单；抓不到时只推 checked_at。
 */

/** 取（或生成）本机助手 token */
function riven_helper_token( $reset = false ) {
	if ( $reset ) {
		update_option( 'riven_helper_token', wp_generate_password( 32, false, false ), false );
	}
	$t = (string) get_option( 'riven_helper_token', '' );
	if ( '' === $t ) {
		$t = wp_generate_password( 32, false, false );
		update_option( 'riven_helper_token', $t, false );
	}
	return $t;
}

/** 待本机抓取的域名：服务端抓不到、且域名还没死的（最久没试过的优先） */
function riven_helper_pending( $limit = 100 ) {
	global $wpdb;
	if ( ! riven_health_table_exists() ) {
		return array();
	}
	$pool = riven_health_collect_hosts();
	if ( empty( $pool ) ) {
		return array();
	}
	$t   = riven_health_table();
	// 「最近试过就别再拉」的门槛（2026-10-17 老张踩的坑）：
	//
	// 以前只按 status='fail' 拉，本机没抓到的回传时把 checked_at 推到当前时间 ——
	// 但 fail 表一旦不大（比如刚建站基线只有两三百行），下一轮 pull 的 SQL
	// 还是覆盖全表，这些「刚试过」的排最前又被拉一遍。助手默认 8 轮 × 100 个，
	// 实际池子里只有 172 个待抓 → 800 次"尝试"里四五遍都是重复劳动，
	// 老张看到的「尝试 800 个」就是这么来的。
	//
	// 现在跟服务器扫描的轮转间隔对齐：刚试过的几天内不再进本机清单，
	// 后台那个「可以帮忙抓取 N 个」也按同一口径计数。
	// 门槛取「轮转间隔」与 3 天的较小值：服务器 7 天才轮到 fail 域名一次，
	// 本机这边最多等 3 天就能补位，不会因为 cron 刚扫过就一直拉不到。
	$wait = min( riven_health_ttl_days(), 3 );
	$cut  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $wait * DAY_IN_SECONDS );

	// 多取一些，因为下面要按「时间闸门」过滤掉已归档的域名（LIMIT 发生在过滤之前）
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT host FROM {$t} WHERE status='fail' AND fail_reason <> 'dns' AND checked_at < %s ORDER BY checked_at ASC LIMIT %d",
			$cut,
			max( absint( $limit ) * 3, 200 )
		)
	);
	$out = array();
	foreach ( (array) $rows as $r ) {
		if ( ! isset( $pool[ $r->host ] ) ) {
			continue;
		}
		// 1.10.0：过了人情味期的域名前台已经归档，不必再拉本机去抓
		if ( null === riven_health_cut_for_host( $r->host ) ) {
			continue;
		}
		$out[] = array( 'h' => $r->host, 'u' => $pool[ $r->host ] );
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

/** 处理本机助手的 pull / push（免登录，走 token） */
function riven_helper_handle() {
	if ( empty( $_GET['riven_helper'] ) ) {
		return;
	}
	$act = sanitize_key( wp_unslash( $_GET['riven_helper'] ) );
	$tok = isset( $_REQUEST['token'] ) ? (string) wp_unslash( $_REQUEST['token'] ) : '';

	// 这组接口必须永远拿到实时数据：通知缓存插件别缓存（配合请求里的 nowprocket=1）
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );

	if ( ! hash_equals( riven_helper_token(), $tok ) ) {
		status_header( 403 );
		echo wp_json_encode( array( 'ok' => false, 'error' => 'token 不对（可在后台重置）' ) );
		exit;
	}

	if ( 'script' === $act ) {
		$file = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : 'bat';

		if ( 'ps1' === $file ) {
			// 抓取脚本本体放在主题里（随更新包走），这里只负责发出去 ——
			// 好处：以后升级抓取逻辑，站长不用重新下载 bat。
			$path = RCG_DIR . 'assets/helper/link-helper.ps1';
			if ( ! file_exists( $path ) ) {
				status_header( 404 );
				echo wp_json_encode( array( 'ok' => false, 'error' => '脚本文件缺失：assets/helper/link-helper.ps1' ) );
				exit;
			}
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="link-helper.ps1"' );
			echo file_get_contents( $path );
			exit;
		}

		// bat：按当前令牌动态生成，双击就能跑（内容全英文，避免 cmd 代码页乱码）
		$bat = <<<'BAT'
@echo off
title riven link helper
set "SITE=__SITE__"
set "TOKEN=__TOKEN__"
set "PS1=%TEMP%\link-helper.ps1"
echo.
echo   [1/2] Fetching the latest scanner script ...
powershell -NoProfile -ExecutionPolicy Bypass -Command "try{Invoke-WebRequest -UseBasicParsing -Uri ($env:SITE + '?riven_helper=script&file=ps1&token=' + $env:TOKEN + '&nowprocket=1') -OutFile $env:PS1 -ErrorAction Stop}catch{exit 1}"
if errorlevel 1 (
  echo   [X] Download failed. Check your network, or re-download this file from your site admin.
  echo.
  pause
  exit /b 1
)
echo   [2/2] Scanning ...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS1%" -Site "%SITE%" -Token "%TOKEN%"
echo.
pause
BAT;
		$bat = str_replace(
			array( '__SITE__', '__TOKEN__' ),
			array( home_url( '/' ), riven_helper_token() ),
			$bat
		);
		// cmd 对 LF 换行的 bat 解析不可靠，统一转成 CRLF
		$bat = str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $bat ) );

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="link-helper.bat"' );
		echo $bat;
		exit;
	}

	if ( 'pull' === $act ) {
		$limit = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 100;
		$limit = max( 1, min( 200, $limit ) );
		$items = riven_helper_pending( $limit );
		echo wp_json_encode(
			array(
				'ok'    => true,
				'site'  => home_url(),
				'count' => count( $items ),
				'items' => $items,
			)
		);
		exit;
	}

	if ( 'push' === $act ) {
		$raw  = file_get_contents( 'php://input' );
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
			status_header( 400 );
			echo wp_json_encode( array( 'ok' => false, 'error' => '请求体不是合法 JSON，或没有 results' ) );
			exit;
		}
		// 只有一条结果时，某些 JSON 序列化器会把它压成单个对象而不是数组 —— 这里兜一下
		$results = $data['results'];
		if ( isset( $results['h'] ) ) {
			$results = array( $results );
		}

		global $wpdb;
		$pool = riven_health_collect_hosts();
		$t    = riven_health_table();
		$now  = current_time( 'mysql' );
		$st   = array( 'got' => 0, 'risk' => 0, 'junk' => 0, 'ok' => 0, 'fail' => 0, 'skip' => 0 );
		$hits = array();

		foreach ( $results as $r ) {
			if ( ! is_array( $r ) || empty( $r['h'] ) ) {
				$st['skip']++;
				continue;
			}
			$h = riven_link_guard_host_key( $r['h'] );
			// 只接受站内确实存在的域名 —— 避免有人拿到 token 往里灌数据
			if ( '' === $h || ! isset( $pool[ $h ] ) ) {
				$st['skip']++;
				continue;
			}

			if ( empty( $r['ok'] ) ) {
				// 本机也没抓到：只把 checked_at 往后推（排到队尾，不必反复试），
				// 其余字段一律不动 —— 抓不到永远不能作为降级的理由。
				$wpdb->update( $t, array( 'checked_at' => $now ), array( 'host' => $h ), array( '%s' ), array( '%s' ) );
				$st['fail']++;
				continue;
			}

			$signals = array();
			foreach ( (array) ( isset( $r['signals'] ) ? $r['signals'] : array() ) as $sg ) {
				$sg = sanitize_key( $sg );
				if ( in_array( $sg, array( 'rss', 'cms', 'path', 'generator', 'title', 'blogish' ), true ) ) {
					$signals[] = $sg;
				}
			}
			$date_ts = 0;
			if ( ! empty( $r['date'] ) && preg_match( '#^\d{4}-\d{2}-\d{2}$#', (string) $r['date'] ) ) {
				$date_ts = (int) strtotime( (string) $r['date'] . ' 12:00:00' );
			}

			$res = riven_health_finalize(
				$h,
				mb_substr( (string) ( isset( $r['title'] ) ? $r['title'] : '' ), 0, 300 ),
				mb_substr( (string) ( isset( $r['text'] ) ? $r['text'] : '' ), 0, 20000 ),
				absint( isset( $r['text_len'] ) ? $r['text_len'] : 0 ),
				$signals,
				$date_ts,
				! empty( $r['shell'] ),
				isset( $r['jump'] ) ? (string) $r['jump'] : ''
			);

			$st['got']++;
			if ( 'risk' === $res['status'] ) {
				$st['risk']++;
				$hits[] = $h;
			} elseif ( 'junk' === $res['status'] ) {
				$st['junk']++;
			} else {
				$st['ok']++;
			}
		}

		delete_transient( 'riven_health_hosts' );
		// 判定可能变化（降级 / 恢复都要立刻在前台生效）
		riven_health_purge_cache();

		update_option(
			'riven_helper_last',
			array(
				'time'  => time(),
				'total' => count( $results ),
				'got'   => $st['got'],
				'ok'    => $st['ok'],
				'risk'  => $st['risk'],
				'junk'  => $st['junk'],
				'fail'  => $st['fail'],
				'skip'  => $st['skip'],
			),
			false
		);

		echo wp_json_encode(
			array(
				'ok'      => true,
				'stat'    => $st,
				'risk'    => $hits,
				'pending' => riven_health_due_count(),
			)
		);
		exit;
	}

	status_header( 404 );
	echo wp_json_encode( array( 'ok' => false, 'error' => '未知操作' ) );
	exit;
}
add_action( 'init', 'riven_helper_handle', 1 );
