# CDN 部署说明（接入 CDN 的配置要点）

本系统是动态 PHP 应用，页面带有会话与 CSRF 令牌，因此必须采用"动态内容不缓存、静态资源长缓存"的组合策略。代码层已做好防护，本文件说明 CDN / Web 服务器侧需要配合的配置。

## 一、代码层已内置的 CDN 兼容处理

1. 所有 PHP 页面统一发送禁用缓存响应头（includes/functions.php 顶部）：

    Cache-Control: no-store, no-cache, must-revalidate, max-age=0
    Pragma: no-cache
    Vary: Cookie, Accept-Encoding

   作用：CDN 不会缓存到带有他人会话信息或过期 CSRF 令牌的页面；Vary: Cookie 进一步按登录态区分。

2. 静态资源自动带版本号：CSS / JS 通过 assetUrl() 输出 ?v=文件修改时间，文件更新后浏览器与 CDN 会自动拉取新版本。

3. 真实客户端 IP：getRequestIp() 依次识别 CF-Connecting-IP、True-Client-IP、X-Real-IP、X-Forwarded-For、REMOTE_ADDR，因此员工登录设备记录不会变成 CDN 节点 IP。

4. 上传文件不可变命名：头像与站点图标文件名包含随机串，可安全长缓存。

5. CSRF 令牌随会话：所有表单带 csrf_token，CDN 只需透传 Cookie 与 POST 请求。

## 二、缓存策略（务必照此配置）

    | 路径                  | 缓存策略        | 说明 |
    | --------------------- | --------------- | ---- |
    | *.php（所有页面）     | 不缓存 / 回源   | 动态页面，响应头已声明 no-store |
    | /api/*                | 不缓存 / 回源   | 实时版本号接口，用于页面自动刷新 |
    | /assets/css/*         | 长缓存（1 年）  | 带 ?v= 版本号 |
    | /assets/js/*          | 长缓存（1 年）  | 带 ?v= 版本号 |
    | /assets/uploads/**    | 长缓存（1 年）  | 文件名含随机串，内容不可变 |
    | /data/*、/storage/*   | 禁止访问        | 数据库与会话文件，必须拒绝 |

## 三、Nginx 源站示例

    server {
        listen 80;
        server_name cafe.example.com;
        root /var/www/coffee-mgmt;
        index index.php;

        # 敏感目录直接拒绝（务必保留）
        location ~ ^/(data|storage)/ { deny all; return 403; }

        # 静态资源：长缓存
        location ~* ^/assets/(css|js)/ {
            expires 1y;
            add_header Cache-Control "public, max-age=31536000, immutable";
            access_log off;
        }
        location ~* ^/assets/uploads/ {
            expires 1y;
            add_header Cache-Control "public, max-age=31536000, immutable";
            access_log off;
        }

        # PHP 动态：交给 PHP-FPM（代码已声明 no-store）
        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_read_timeout 120s;
        }

        # 目录式 URL（/consumption/ 等），router.php 已实现同等逻辑
        location / { try_files $uri $uri/ /router.php?$query_string; }
    }

## 四、Apache 源站示例（.htaccess）

    # 敏感目录
    RedirectMatch 403 ^/(data|storage)/

    # 静态资源长缓存
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css "access plus 1 year"
        ExpiresByType application/javascript "access plus 1 year"
        ExpiresByType image/jpeg "access plus 1 year"
        ExpiresByType image/png "access plus 1 year"
        ExpiresByType image/webp "access plus 1 year"
    </IfModule>

    <IfModule mod_headers.c>
        <FilesMatch "\.(css|js|jpg|jpeg|png|webp|gif|ico)$">
            Header set Cache-Control "public, max-age=31536000, immutable"
        </FilesMatch>
    </IfModule>

## 五、CDN 控制台配置要点（Cloudflare / 阿里云 / 腾讯云等通用）

1. 缓存规则：默认"绕过缓存（Bypass）"用于 *.php 与 /api/*；仅对 /assets/ 开启缓存。
2. 透传 Cookie：必须开启，且不要改写 PHPSESSID（登录状态、CSRF 令牌、并发登录检测都依赖它）。
3. 透传 POST 与请求体：上传头像 / CSV 导入依赖请求体，勿开启请求体过滤。
4. 真实 IP 头：开启 X-Forwarded-For 或厂商标头（Cloudflare 为 CF-Connecting-IP），代码已适配。
5. HTTPS：CDN 开启 HTTPS 后建议源站也启用 HTTPS，避免回源明文；系统内部资源全部为绝对路径，不会出现混合内容。
6. 上传大小：头像与 CSV 导入限制为 2MB，CDN 请求体上限请设置 4MB 以上。
7. 超时：单次请求超时建议不低于 120 秒（导出 CSV、报表偶发耗时较长）。
8. 不要开启：除 HTML 压缩外的内容注入 / 页面 DOM 改写（会破坏表单令牌）；也不要对 /api/ 开启缓存。

## 六、接入 CDN 后的验证清单

1. 打开首页，响应头应包含 Cache-Control: no-store。
2. 登录后刷新仍保持登录；退出后 CDN 不应返回上一个用户的页面。
3. 开发者工具 Network 中，style.css?v=xxxxxxxx 应命中 CDN（cf-cache-status: HIT 之类），而 PHP 页面应为 MISS / BYPASS / DYNAMIC。
4. 提交任意表单（注册会员、发布公告等）应成功。若提示"页面已过期，请刷新后重试"，说明 CDN 缓存了 HTML，请检查缓存规则。
5. 员工设备页中的 IP 应显示真实客户端 IP，而不是 CDN 节点 IP。
6. 直接访问 /data/database.json 应返回 403。

## 八、自动适应机制（无需任何 CDN 配置）

系统已内置自适应逻辑，即使 CDN 按默认行为缓存了 HTML 页面，也能正常工作：

1. **CSRF 令牌自动刷新**（assets/js/session-sync.js + /api/csrf_token.php）
   - 页面加载后，前端请求带随机参数的令牌接口（CDN 无法命中该 URL 的缓存），拿到**当前会话**的最新令牌并回填所有 POST 表单；
   - 提交瞬间再次写入最新令牌；
   - 因此即使页面 HTML 是 CDN 的陈旧副本（令牌已过期），表单仍能提交成功。

2. **跨会话缓存自动纠正**
   - 页面内嵌当前会话指纹，前端与令牌接口返回的指纹比对；
   - 一旦发现页面来自**其他会话的缓存**，立即用带随机参数的地址绕过缓存重新加载，避免看到他人数据。

3. **静态资源自动失效**
   - CSS / JS 均带 ?v=修改时间，文件更新后无需手动刷新 CDN 缓存。

4. **JSON 接口不被缓存**
   - /api/csrf_token.php 与 /api/record_version.php 的请求都附加随机参数，并带 no-store 响应头。

**结论**：只要 CDN 保持默认行为（透传 Cookie 与 POST 请求），系统无需任何 CDN 端配置即可正常使用。第三、四、五节的配置属于性能优化（减少回源、提升速度），不做也不影响功能。

## 九、常见问题

- 提交表单提示"页面已过期"：CDN 缓存了含令牌的 HTML。请对 *.php 关闭缓存（响应头已声明 no-store，多数 CDN 会自动遵守）。
- 页面数据不刷新：确认 /api/record_version.php 未被缓存（该项必须回源）。
- 登录后又跳回登录页：CDN 未透传 Cookie，或改写了会话 Cookie 名称。
- 头像上传后看不到：若覆盖了同名图标文件，请手动刷新 CDN 缓存；头像文件名带随机串通常不受影响。
