# ============================================================
#  外链体检 · 本机抓取助手
# ------------------------------------------------------------
#  作用：用你本机的网络，去抓「站点服务器抓不到」的那些博客，
#        把页面特征报回站点。
#
#  为什么要这样：体检是从站点服务器发起的，而服务器在海外，
#  对国内博客常常连不上。实测那批「抓不到」的域名里，
#  服务器只能读懂 6.7%，从国内网络能读懂 24.7%（3.7 倍）。
#
#  分工：本脚本只负责「抓 + 提取特征」；
#        打分、判定、落库全在站点端做（词库和规则只有一份），
#        所以本机抓到什么都不会绕过任何规则。
#
#  用法：不用手动跑这个文件 —— 双击后台下载的 link-helper.bat 即可，
#        它会自动把本脚本取到临时目录并执行。
# ============================================================

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Site,
    [Parameter(Mandatory = $true)][string]$Token,
    [int]$Batch = 100,          # 每轮拉多少个域名
    [int]$Concurrency = 20,     # 并发数
    [int]$MaxRounds = 8,        # 最多跑几轮（防呆）
    [int]$TimeoutSec = 15       # 单个请求超时
)

$ErrorActionPreference = 'Continue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11

$Site = $Site.TrimEnd('/')

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'

# ---------- HTTP 客户端（复用，避免每请求新建连接） ----------
# 注意：PowerShell 5.1 默认没有加载 System.Net.Http 这个程序集，必须显式加载，
# 否则 New-Object 会报 TypeNotFound（实测踩过）。
try {
    Add-Type -AssemblyName System.Net.Http -ErrorAction Stop
} catch {
    Write-Host ''
    Write-Host '  [X] 本机缺少 .NET 组件 System.Net.Http，无法运行。' -ForegroundColor Red
    Write-Host '      请装 .NET Framework 4.6 以上（Windows 10/11 自带）。'
    Write-Host ''
    exit 1
}

$handler = New-Object System.Net.Http.HttpClientHandler
$handler.AllowAutoRedirect = $true
$handler.UseCookies = $false
$handler.AutomaticDecompression = [System.Net.DecompressionMethods]::GZip -bor [System.Net.DecompressionMethods]::Deflate
$client = New-Object System.Net.Http.HttpClient($handler)
$client.Timeout = [TimeSpan]::FromSeconds($TimeoutSec)
[void]$client.DefaultRequestHeaders.TryAddWithoutValidation('User-Agent', $UA)
[void]$client.DefaultRequestHeaders.TryAddWithoutValidation('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
[void]$client.DefaultRequestHeaders.TryAddWithoutValidation('Accept-Language', 'zh-CN,zh;q=0.9,en;q=0.8')

# ---------- 与站点通信 ----------
function Invoke-Pull([int]$limit) {
    $url = "$Site/?riven_helper=pull&token=$Token&limit=$limit&nowprocket=1"
    $wc = New-Object System.Net.WebClient
    $wc.Encoding = [System.Text.Encoding]::UTF8
    try {
        $txt = $wc.DownloadString($url)
    } catch {
        return [pscustomobject]@{ ok = $false; error = ('无法连接站点：' + $_.Exception.Message) }
    } finally { $wc.Dispose() }
    try { return ($txt | ConvertFrom-Json) } catch { return [pscustomobject]@{ ok = $false; error = '站点返回的不是 JSON（可能被安全拦截或需要登录）' } }
}

function Invoke-Push([object[]]$results) {
    $body = @{ results = @($results) } | ConvertTo-Json -Depth 6 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
    $url = "$Site/?riven_helper=push&token=$Token&nowprocket=1"
    $wc = New-Object System.Net.WebClient
    $wc.Headers.Add('Content-Type', 'application/json; charset=utf-8')
    try {
        $resp = $wc.UploadData($url, 'POST', $bytes)
        $txt = [System.Text.Encoding]::UTF8.GetString($resp)
    } catch {
        return [pscustomobject]@{ ok = $false; error = ('上报失败：' + $_.Exception.Message) }
    } finally { $wc.Dispose() }
    try { return ($txt | ConvertFrom-Json) } catch { return [pscustomobject]@{ ok = $false; error = '站点返回的不是 JSON' } }
}

# ---------- 并发抓取 ----------
function Invoke-FetchBatch([object[]]$targets) {
    $result = @{}
    if ($targets.Count -eq 0) { return $result }

    for ($i = 0; $i -lt $targets.Count; $i += $Concurrency) {
        $end   = [Math]::Min($i + $Concurrency - 1, $targets.Count - 1)
        $chunk = @($targets[$i..$end])
        $pairs = @()

        foreach ($t in $chunk) {
            $result[$t.host] = @{ ok = $false; code = 0; body = ''; err = 'not started' }
            try {
                $req  = New-Object System.Net.Http.HttpRequestMessage([System.Net.Http.HttpMethod]::Get, $t.url)
                $task = $client.SendAsync($req, [System.Net.Http.HttpCompletionOption]::ResponseContentRead)
                $pairs += , @{ host = $t.host; task = $task }
            } catch {
                $result[$t.host] = @{ ok = $false; code = 0; body = ''; err = 'request' }
            }
        }

        if ($pairs.Count -gt 0) {
            $tl = New-Object 'System.Collections.Generic.List[System.Threading.Tasks.Task]'
            foreach ($p in $pairs) { [void]$tl.Add($p.task) }
            try { [void][System.Threading.Tasks.Task]::WaitAll($tl.ToArray(), ($TimeoutSec * 1000 + 4000)) } catch { }

            foreach ($p in $pairs) {
                $h = $p.host
                if (-not $p.task.IsCompleted) {
                    $result[$h] = @{ ok = $false; code = 0; body = ''; err = 'timeout' }
                    continue
                }
                try {
                    $resp = $p.task.Result
                    $code = [int]$resp.StatusCode
                    $body = $resp.Content.ReadAsStringAsync().Result
                    $resp.Dispose()
                    if ($code -ge 200 -and $code -lt 400 -and $body.Length -ge 200) {
                        $result[$h] = @{ ok = $true; code = $code; body = $body; err = '' }
                    } else {
                        $result[$h] = @{ ok = $false; code = $code; body = ''; err = "HTTP $code" }
                    }
                } catch {
                    $result[$h] = @{ ok = $false; code = 0; body = ''; err = 'fetch' }
                }
            }
        }
    }
    return $result
}

# ---------- 特征提取（与站点端 riven_health_analyze_html 对齐） ----------
function Test-DateParts([int]$y, [int]$mo, [int]$d) {
    if ($y -lt 2000 -or $mo -lt 1 -or $mo -gt 12 -or $d -lt 1 -or $d -gt 31) { return $null }
    try {
        $dt = New-Object DateTime($y, $mo, $d)
    } catch { return $null }
    if ($dt -gt (Get-Date).AddDays(2)) { return $null }
    return $dt
}

function Get-LatestDate([string]$text) {
    $best = $null
    # 2026-09-17 / 2026/09/17 / 2026.09.17
    foreach ($m in [regex]::Matches($text, '(20\d{2})[-/.](\d{1,2})[-/.](\d{1,2})')) {
        $dt = Test-DateParts ([int]$m.Groups[1].Value) ([int]$m.Groups[2].Value) ([int]$m.Groups[3].Value)
        if ($dt -and (-not $best -or $dt -gt $best)) { $best = $dt }
    }
    # 2026年9月17日
    foreach ($m in [regex]::Matches($text, '(20\d{2})年(\d{1,2})月(\d{1,2})日')) {
        $dt = Test-DateParts ([int]$m.Groups[1].Value) ([int]$m.Groups[2].Value) ([int]$m.Groups[3].Value)
        if ($dt -and (-not $best -or $dt -gt $best)) { $best = $dt }
    }
    # RFC822（RSS 的 pubDate）：Wed, 17 Sep 2026 00:00:00 +0800
    foreach ($m in [regex]::Matches($text, '\w{3},\s+(\d{1,2})\s+(\w{3})\s+(20\d{2})\s+\d{2}:\d{2}:\d{2}')) {
        try {
            $dt = [datetime]::Parse(($m.Value -replace '^\w{3},\s+', ''), [Globalization.CultureInfo]::InvariantCulture)
            if ($dt.Year -ge 2000 -and $dt -lt (Get-Date).AddDays(2) -and (-not $best -or $dt -gt $best)) { $best = $dt }
        } catch { }
    }
    # ISO8601：2026-09-17T08:00:00+08:00
    foreach ($m in [regex]::Matches($text, '(20\d{2}-\d{2}-\d{2})T\d{2}:\d{2}')) {
        try {
            $dt = [datetime]::Parse($m.Groups[1].Value, [Globalization.CultureInfo]::InvariantCulture)
            if ($dt -and (-not $best -or $dt -gt $best)) { $best = $dt }
        } catch { }
    }
    return $best
}

function Get-Feature([string]$html, [string]$baseUrl) {
    $title = ''
    $m = [regex]::Match($html, '(?is)<title[^>]*>(.*?)</title>')
    if ($m.Success) { $title = ([regex]::Replace($m.Groups[1].Value, '\s+', ' ')).Trim() }
    $title = [System.Net.WebUtility]::HtmlDecode($title)

    $desc = ''
    $m2 = [regex]::Match($html, '(?is)<meta[^>]+name=["'']description["''][^>]+content=["''](.*?)["'']')
    if ($m2.Success) { $desc = $m2.Groups[1].Value }

    $body = [regex]::Replace($html, '(?is)<(script|style|noscript)[^>]*>.*?</\1>', ' ')
    $body = [regex]::Replace($body, '(?s)<[^>]+>', ' ')
    $body = [System.Net.WebUtility]::HtmlDecode($body)
    $body = [regex]::Replace($body, '\s+', ' ')
    $textLen = $body.Length

    # ===== 博客特征（2026-10-17 连修两处误判，1.9.4）=====
    # ⚠️ 这段必须和 inc/link-health.php 的 riven_health_analyze_html() 保持一致 ——
    #    两边不一致会出现"服务器判正常、本机判风险"的分裂结论。
    #    ① 先挖掉 <script>/<style> 的**内容**（inline JS 里的英文单词会假命中，
    #       如 Wix 页面的组件名 GhostComp 命中 ghost）；只挖内容、保留标签本身，
    #       WordPress 的 /wp-content/ 常只出现在 <script src> / <link href> 属性里。
    #    ② CMS 名字只在 head + HTML 注释里找（正文里的 "WordPress主题检测"、
    #       CSS 类名 btn-ghost 这类噪音一律不算）。
    #    ③ generator 要 content 里真写着 CMS 名才算，并且要吃得下**无引号属性**
    #       —— Hugo/Hexo 输出的是 <meta name=generator content="Hugo 0.168.0">。
    $markup = [regex]::Replace($html, '(?is)(<(script|style)\b[^>]*>).*?(</\2>)', '$1$3')

    $cmsZone = ''
    $hm2 = [regex]::Match($markup, '(?is)<head\b[^>]*>(.*?)</head>')
    if ($hm2.Success) { $cmsZone = $hm2.Groups[1].Value }
    elseif ($markup.Length -gt 30000) { $cmsZone = $markup.Substring(0, 30000) }
    else { $cmsZone = $markup }
    $ci = 0
    foreach ($c in [regex]::Matches($markup, '(?s)<!--(.*?)-->')) {
        if ($ci -ge 40) { break }
        $cmsZone += ' ' + $c.Groups[1].Value
        $ci++
    }

    $cmsRe = '(?i)(wordpress|typecho|hexo|hugo|z[-\s]?blog|emlog|ghost|jekyll|movabletype|drupal|halo|gridea|astro|gatsby)'
    $gen = ''
    $gm = [regex]::Match($markup, '(?i)<meta\b[^>]*\bname\s*=\s*["'']?generator["'']?[^>]*>')
    if ($gm.Success) {
        $gc = [regex]::Match($gm.Value, '(?i)content\s*=\s*["'']?([^"''>]+)["'']?')
        if ($gc.Success) { $gen = $gc.Groups[1].Value.Trim() }
    }

    $signals = New-Object System.Collections.ArrayList
    if ($markup -match '(?i)type\s*=\s*["'']application/(rss|atom)\+xml') { [void]$signals.Add('rss') }
    if ($cmsZone -match $cmsRe) { [void]$signals.Add('cms') }
    if ($gen -and ($gen -match $cmsRe)) {
        [void]$signals.Add('cms')
        [void]$signals.Add('generator')
    }
    if ($markup -match '(?i)(/wp-content/|/wp-includes/|/usr/themes/|/usr/plugins/|/typecho/)') { [void]$signals.Add('path') }
    if ($title -match '(博客|blog|笔记|随笔|小站|空间|记录|折腾)') { [void]$signals.Add('title') }
    # 兜底：博客圈黑话（和 inc/link-health.php 里的词表保持一致）
    foreach ($bw in @('友链', '博主', '博友', '留言板', '独立博客', '个人博客', '博客园', '自留地')) {
        if ($body.Contains($bw)) { [void]$signals.Add('blogish'); break }
    }
    $signals = @($signals | Select-Object -Unique)

    # RSS 地址（拿到后由调用方再抓一次，取更准的更新日期）
    $rss = ''
    foreach ($lt in [regex]::Matches($html, '(?i)<link[^>]+>')) {
        $tag = $lt.Value
        if ($tag -match '(?i)application/(rss|atom)\+xml') {
            $hm = [regex]::Match($tag, '(?i)href\s*=\s*["'']([^"'']+)["'']')
            if ($hm.Success) {
                $u = [System.Net.WebUtility]::HtmlDecode($hm.Groups[1].Value)
                if ($u.StartsWith('//')) { $u = 'http:' + $u }
                elseif ($u.StartsWith('/')) {
                    try { $b = [uri]$baseUrl; $u = $b.Scheme + '://' + $b.Host + $u } catch { }
                }
                elseif ($u -notmatch '^https?://') { $u = $baseUrl.TrimEnd('/') + '/' + $u.TrimStart('/') }
                $rss = $u
                break
            }
        }
    }

    $text = $title + ' ' + $desc + ' ' + $body
    if ($text.Length -gt 8000) { $text = $text.Substring(0, 8000) }

    # 空壳页（域名停靠 / 占位页）：没有正文，但整页也很小、且不是 JS 应用。
    # 与站点端 riven_health_analyze_html 里的判断保持一致。
    $shell = $false
    if ($textLen -lt 30 -and $html.Length -lt 6000) {
        if (-not ($html -match '(?i)(id=["'']app["'']|__NUXT__|__NEXT_DATA__|vite|webpackJsonp|data-server-rendered|window\.__INITIAL)')) {
            $shell = $true
        }
    }

    # 跳转目标（JS 重定向 / meta refresh）—— 停靠商惯用手法，跟过去才看得到真实页面
    $jump = ''
    $jm = [regex]::Match($html, '(?i)location\.(?:href|replace|assign)\s*[=(]\s*["'']([^"'']+)["'']')
    if ($jm.Success) {
        $jump = $jm.Groups[1].Value
    } else {
        $jm2 = [regex]::Match($html, '(?i)http-equiv\s*=\s*["'']refresh["''][^>]*content\s*=\s*["''][^"'']*url\s*=\s*([^"''\s>;]+)')
        if ($jm2.Success) { $jump = $jm2.Groups[1].Value }
    }
    $jump = $jump.Trim()
    if ($jump -eq '') {
        # 无跳转
    } elseif ($jump.StartsWith('#')) {
        $jump = ''
    } elseif ($jump.StartsWith('//')) {
        $jump = 'http:' + $jump
    } elseif ($jump.StartsWith('/')) {
        try { $bu = [uri]$baseUrl; $jump = $bu.Scheme + '://' + $bu.Host + $jump } catch { $jump = '' }
    } elseif ($jump -notmatch '^https?://') {
        $jump = $baseUrl.TrimEnd('/') + '/' + $jump.TrimStart('/')
    }

    return [pscustomobject]@{
        title    = $title
        desc     = $desc
        text     = $text
        text_len = $textLen
        signals  = @($signals)
        rss      = $rss
        date     = (Get-LatestDate ($body + ' ' + $html))
        shell    = $shell
        jump     = $jump
    }
}

# ============================================================
#  主流程
# ============================================================
Write-Host ''
Write-Host '  ============================================================' -ForegroundColor Cyan
Write-Host '    外链体检 · 本机抓取助手' -ForegroundColor Cyan
Write-Host '  ============================================================' -ForegroundColor Cyan
Write-Host ("    站点：{0}" -f $Site)
Write-Host ''

$grand = @{ total = 0; got = 0; ok = 0; risk = 0; junk = 0; fail = 0 }
$riskHosts = New-Object System.Collections.ArrayList
$startTime = Get-Date

for ($round = 1; $round -le $MaxRounds; $round++) {
    $list = Invoke-Pull $Batch
    if (-not $list.ok) {
        Write-Host ("  [X] {0}" -f $list.error) -ForegroundColor Red
        break
    }

    $items = @()
    if ($null -ne $list.items) { $items = @($list.items) }

    if ($items.Count -eq 0) {
        if ($round -eq 1) { Write-Host '  目前没有需要本机帮忙的域名（服务器都能抓到）。' -ForegroundColor Green }
        break
    }

    Write-Host ("  [第 {0} 轮] 待抓 {1} 个，开始抓取（并发 {2}）..." -f $round, $items.Count, $Concurrency) -ForegroundColor Yellow

    $targets = @()
    foreach ($it in $items) { $targets += @{ host = $it.h; url = $it.u } }

    # 第一遍：按评论里填的那个地址抓
    $res = Invoke-FetchBatch $targets

    # 第二遍：失败的换协议再试一次（老站常只支持其中一种）
    $retry = @()
    foreach ($t in $targets) {
        if (-not $res[$t.host].ok) {
            $alt = $t.url
            if ($alt -match '^https://') { $alt = $alt -replace '^https://', 'http://' }
            elseif ($alt -match '^http://') { $alt = $alt -replace '^http://', 'https://' }
            if ($alt -ne $t.url) { $retry += @{ host = $t.host; url = $alt } }
        }
    }
    if ($retry.Count -gt 0) {
        Write-Host ("         其中 {0} 个换了协议再试一次..." -f $retry.Count)
        $res2 = Invoke-FetchBatch $retry
        foreach ($k in $res2.Keys) { if ($res2[$k].ok) { $res[$k] = $res2[$k] } }
    }

    # 再补一遍：裸域抓不到时改抓 www（与服务器端 1.11.4 同步）
    #
    # 站点端的 host 是归一化过的（去掉 www.），但很多站**只给 www 配了 A 记录**，
    # 裸域压根不解析 —— 照裸域抓必然失败，回传后被判成 dns 死链，前台无辜降级。
    # 老张 2026-10-17 报的 zahui.top 就是这种：www.zahui.top 正常，zahui.top 没有记录。
    $wwwRetry = @()
    foreach ($t in $targets) {
        if ($res[$t.host].ok) { continue }
        $u = [string]$t.url
        $m = [regex]::Match($u, '^(https?)://([^/]+)')
        if (-not $m.Success) { continue }
        $bare = $m.Groups[2].Value
        if ($bare -match '^www\.') { continue }
        # 根路径优先：判的是"域名还活着吗"，不是"那条旧文章还在吗"
        $wwwRetry += @{ host = $t.host; url = ("{0}://www.{1}/" -f $m.Groups[1].Value, $bare) }
    }
    if ($wwwRetry.Count -gt 0) {
        Write-Host ("         其中 {0} 个改抓 www 再试一次..." -f $wwwRetry.Count)
        $res3 = Invoke-FetchBatch $wwwRetry
        foreach ($k in $res3.Keys) { if ($res3[$k].ok) { $res[$k] = $res3[$k] } }
    }

    # 第三遍：抓到的站，如果有 RSS，再抓一次 RSS 取准确的更新日期
    $rssJobs = @()
    $feat = @{}
    foreach ($t in $targets) {
        $r = $res[$t.host]
        if (-not $r.ok) { continue }
        $f = Get-Feature $r.body $t.url
        $feat[$t.host] = $f
        if ($f.rss) { $rssJobs += @{ host = $t.host; url = $f.rss } }
    }
    if ($rssJobs.Count -gt 0) {
        $rres = Invoke-FetchBatch $rssJobs
        foreach ($j in $rssJobs) {
            if ($rres[$j.host].ok) {
                $d = Get-LatestDate $rres[$j.host].body
                if ($d -and (-not $feat[$j.host].date -or $d -gt $feat[$j.host].date)) { $feat[$j.host].date = $d }
            }
        }
    }

    # 第四遍：空壳页带了跳转的，跟过去 —— 停靠页 / 待售页 / 甚至黄赌站都在那一跳之后
    $jumpJobs = @()
    foreach ($t in $targets) {
        $f = $feat[$t.host]
        if ($f -and $f.shell -and $f.jump) { $jumpJobs += @{ host = $t.host; url = $f.jump } }
    }
    if ($jumpJobs.Count -gt 0) {
        Write-Host ("         其中 {0} 个是空壳页，跟着跳转看真实内容..." -f $jumpJobs.Count)
        $jres = Invoke-FetchBatch $jumpJobs
        foreach ($j in $jumpJobs) {
            if ($jres[$j.host].ok) {
                $feat[$j.host] = Get-Feature $jres[$j.host].body $j.url
            }
        }
    }

    # 组装并上报
    $payload = @()
    foreach ($t in $targets) {
        $r = $res[$t.host]
        if (-not $r.ok) {
            $payload += @{ h = $t.host; ok = $false }
            continue
        }
        $f = $feat[$t.host]
        $payload += @{
            h        = $t.host
            ok       = $true
            title    = $f.title
            text     = $f.text
            text_len = $f.text_len
            signals  = @($f.signals)
            date     = $(if ($f.date) { $f.date.ToString('yyyy-MM-dd') } else { '' })
            shell    = [bool]$f.shell
            # 1.9.4：跳转目标必须一起上报 —— 服务器端要靠它区分"带跳转的停靠页（降级）"
            # 和"没跳转的空壳页（看不清，放行）"
            jump     = [string]$f.jump
        }
    }

    $push = Invoke-Push $payload
    if (-not $push.ok) {
        Write-Host ("  [X] 上报失败：{0}" -f $push.error) -ForegroundColor Red
        break
    }

    $s = $push.stat
    Write-Host ("         抓回 {0}/{1} 个 （正常 {2} · 风险 {3} · 待售 {4} · 没抓到 {5}）" -f $s.got, $items.Count, $s.ok, $s.risk, $s.junk, $s.fail)
    if ($null -ne $push.risk -and @($push.risk).Count -gt 0) {
        foreach ($rh in @($push.risk)) { [void]$riskHosts.Add($rh) }
        Write-Host ("         ★ 新发现风险域名：{0}" -f (@($push.risk) -join '、')) -ForegroundColor Red
    }

    $grand.total += $items.Count
    $grand.got   += $s.got
    $grand.ok    += $s.ok
    $grand.risk  += $s.risk
    $grand.junk  += $s.junk
    $grand.fail  += $s.fail

    if ($items.Count -lt $Batch) { break }   # 已经不足一批，说明本次清单抓完了
}

$elapsed = [int]((Get-Date) - $startTime).TotalSeconds

Write-Host ''
Write-Host '  ------------------------------------------------------------'
Write-Host ("    本次共尝试 {0} 个域名，抓回 {1} 个，用时 {2} 秒" -f $grand.total, $grand.got, $elapsed)
Write-Host ("    正常 {0} · 风险 {1} · 待售 {2} · 没抓到 {3}" -f $grand.ok, $grand.risk, $grand.junk, $grand.fail)
if ($riskHosts.Count -gt 0) {
    Write-Host ''
    Write-Host '    发现的风险域名（已自动降级，建议到后台「外链体检」里拉黑）:' -ForegroundColor Red
    foreach ($h in $riskHosts) { Write-Host ("      - {0}" -f $h) -ForegroundColor Red }
}
Write-Host ''
Write-Host '    结果已回传站点。可以到后台左侧「外链守卫 → 外链体检」查看。' -ForegroundColor Green
Write-Host '    想再抓一批？再双击一次 link-helper.bat 即可。'
Write-Host '  ------------------------------------------------------------'
Write-Host ''

try { $client.Dispose() } catch { }
