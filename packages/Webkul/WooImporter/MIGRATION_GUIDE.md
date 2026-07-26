# WooCommerce → Bagisto 部署指南

把 **urmotorparts**（WordPress + WooCommerce）的数据迁移到本仓库的 **Bagisto 2.4** 并部署。迁移逻辑封装在 `packages/Webkul/WooImporter` 包中：通过只读连接 `woocommerce` 读取旧库，使用 Bagisto 官方仓储（与后台创建商品完全相同的流程）写入新库。

> ⚠️ **安全提醒**：§1 同步源数据时**交互输入**源库密码（不写入文件/历史）。所有源站操作均为**只读**（SSH / `mysqldump`，不停止/重启进程、不锁表）。源服务器 IP、SSH 与数据库密码请勿写进本仓库；建议迁移完成后轮换这些凭据。

---

## 1. 准备 Woo 源数据（首次 / 换新机器时）

从源服务器把数据库与图片同步到本地（流式直传，**不在源服务器留临时文件**）。产物：仓库根目录的 `woo_dump.sql.gz` + `storage/woo-uploads/`。两种部署方式（生产容器 §2 / 本地开发 §3）都用这份数据。

```bash
REMOTE=root@<源服务器IP>                                    # 源服务器（填你的 IP / 域名）
REMOTE_WP=/usr/local/lighthouse/softwares/wordpress         # WordPress 根目录
read -rsp 'WooCommerce 数据库密码: ' WOO_DB_PASS; echo       # 交互输入，不落盘、不进历史

# 1a. 数据库：远程一致性快照流式落地为本地 woo_dump.sql.gz（只读、不锁表、不影响线上）
ssh "$REMOTE" "MYSQL_PWD='$WOO_DB_PASS' mysqldump --single-transaction --quick --no-tablespaces -u wordpress wordpress | gzip" > woo_dump.sql.gz

# 1b. 图片：远程打包 wp-content/uploads，流式解压到 storage/woo-uploads（去掉顶层 uploads/）
mkdir -p storage/woo-uploads
ssh "$REMOTE" "tar czf - -C $REMOTE_WP/wp-content uploads" | tar xzf - -C storage/woo-uploads --strip-components=1

# 1c. 校验
ls -lh woo_dump.sql.gz && find storage/woo-uploads -maxdepth 1 -type d | sort
```

> 已有本地 `woo_dump.sql.gz` + `storage/woo-uploads/` 时可跳过本节。

---

## 2. 生产部署（单容器镜像，从本 fork 构建）★ 推荐

用 `docker/production/` 的单容器镜像：**Nginx + PHP-FPM + 内置 MySQL + Supervisor**，一个容器搞定，无需独立 MySQL。镜像**从本仓库 fork 构建**（已含 WooImporter 与全部定制），真实商品数据在首次部署时一次性导入。**全部命令实测通过。**

> 镜像里不构建前端资源，依赖仓库内已提交的 `public/themes/*/build`（已跟踪）。在仓库根目录执行下列命令。

### 2.1 构建镜像（构建上下文 = 仓库根）

```bash
docker build -f docker/production/Dockerfile -t urmotorparts:prod .
```

> 约 5–10 分钟（apt + 编译 imagick + composer + 构建期安装）。`.dockerignore` 已把 vendor / node_modules / .git / `storage/woo-uploads` / `woo_dump.sql.gz` / `.env` 排除在构建上下文外。

### 2.2 首次部署：起容器 + 导入数据（一次性）

```bash
# ① 起容器：持久化卷(mysql/storage) + 只读挂载 Woo 图片(仅迁移用)
docker run -d --name urmotorparts -p 80:80 \
  -v urmotorparts-mysql:/var/lib/mysql \
  -v urmotorparts-storage:/var/www/bagisto/storage \
  -v "$PWD/storage/woo-uploads:/woo-uploads:ro" \
  -e APP_URL=https://your-domain.com \
  urmotorparts:prod

# 等内置 MySQL 就绪（root 走 socket 免密）
until docker exec urmotorparts mysqladmin ping -uroot --silent 2>/dev/null | grep -q alive; do sleep 2; done

# ② 把 Woo 源库灌进内置 MySQL，并授权 bagisto 用户
docker exec -i urmotorparts mysql -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS woocommerce_import CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON woocommerce_import.* TO 'bagisto'@'127.0.0.1';
GRANT ALL PRIVILEGES ON woocommerce_import.* TO 'bagisto'@'localhost';
FLUSH PRIVILEGES;
SQL
zcat woo_dump.sql.gz | docker exec -i urmotorparts mysql -uroot woocommerce_import

# ③ 配置 Woo 源连接（指向内置库），跑全量迁移（--uploads 指到挂载点）
#    ⚠️ artisan 命令一律加 -u www-data：否则以 root 运行会在 storage 里写出 root 属主
#    的缓存文件，导致之后 php-fpm(www-data) 渲染分类页时写缓存失败 → 500。
docker exec -u www-data urmotorparts bash -lc 'grep -q "^WOO_DB_DATABASE=" .env || cat >> .env <<EOF
WOO_DB_HOST=127.0.0.1
WOO_DB_PORT=3306
WOO_DB_DATABASE=woocommerce_import
WOO_DB_USERNAME=bagisto
WOO_DB_PASSWORD=bagisto
WOO_DB_PREFIX=wp_
EOF
php artisan config:clear'

docker exec -u www-data urmotorparts php artisan woocommerce:migrate --all --uploads=/woo-uploads
docker exec -u www-data urmotorparts php artisan indexer:index --mode=full
docker exec -u www-data urmotorparts php artisan optimize:clear

# ④ 迁移完成。这个容器带着迁移用的 woo-uploads 挂载、且没有重启策略——
#    请按 §2.3 重建为「正式运行容器」(去掉挂载 + 开机自启)。源库可删(数据已在 bagisto 库)：
# docker exec urmotorparts mysql -uroot -e "DROP DATABASE woocommerce_import;"
```

**访问**：前台 `https://your-domain.com/` ；后台 `/admin`（`admin@example.com` / `admin123`，登录后立即改密码）。

### 2.3 转为正式运行容器（开机自启 + 去掉迁移挂载）

迁移完成后,把容器重建为正式版本:**去掉只在迁移时用的 woo-uploads 挂载**,并加 `--restart always`(机器重启 / 容器崩溃 / Docker 守护进程重启后都自动拉起,即使之前被手动 `docker stop` 过)。数据持久在 `urmotorparts-mysql` + `urmotorparts-storage` 两个命名卷里,`docker rm` 不会丢、也无需再迁移:

```bash
docker rm -f urmotorparts
docker run -d --name urmotorparts --restart always -p 80:80 \
  -v urmotorparts-mysql:/var/lib/mysql \
  -v urmotorparts-storage:/var/www/bagisto/storage \
  -e APP_URL=https://your-domain.com \
  urmotorparts:prod
```

> - **开机自启**:`--restart always` + Docker 守护进程开机自启(确认 `sudo systemctl enable docker`),机器重启 / Docker 重启后容器都会自动恢复——**即使之前被 `docker stop` 过也会被拉起**(这是 `always` 与 `unless-stopped` 的区别)。
> - 已在运行的容器要改这个策略**无需重建**,直接 `docker update --restart always urmotorparts` 即可。
> - 这个正式容器**不再依赖**仓库里的 `storage/woo-uploads`——迁移源(`woo_dump.sql.gz` + `storage/woo-uploads`)确认无误后可删,释放空间。
> - 容器自带 nginx + php-fpm + mysql + supervisor,无需独立 MySQL;`docker rm` 后数据仍在卷里。
> - **HTTPS**:在容器前放一层反代/负载均衡做 TLS(或用云上 LB);改后台路径用 `-e APP_ADMIN_URL=backend`。
> - **外部 MySQL/RDS 模式**(`-e DB_HOST=...`)见 `docker/production/README.md` §11。

---

## 3. 本地开发（可选：宿主机 serve + Docker MySQL）

快速迭代用，不走容器构建。需要 PHP 8.3 + Composer + 一个 MySQL。

```bash
# MySQL 容器（含 bagisto + 待恢复的 woocommerce_import 两库）
docker run -d --name bagisto-mysql --restart unless-stopped \
  -e MYSQL_ROOT_PASSWORD=bagisto -e MYSQL_DATABASE=bagisto -e MYSQL_ROOT_HOST=% \
  -p 127.0.0.1:3306:3306 -v bagisto-mysql-data:/var/lib/mysql \
  mysql:8.0 --default-authentication-plugin=mysql_native_password
until docker exec bagisto-mysql mysqladmin ping -uroot -pbagisto --silent 2>/dev/null | grep -q alive; do sleep 1; done

# 恢复源库
docker exec bagisto-mysql mysql -uroot -pbagisto -e "CREATE DATABASE IF NOT EXISTS woocommerce_import CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
zcat woo_dump.sql.gz | docker exec -i bagisto-mysql mysql -uroot -pbagisto woocommerce_import

# .env 配好 DB_*（root/bagisto/bagisto）与 WOO_DB_*（woocommerce_import / wp_ 前缀）、
# WOO_UPLOADS_PATH=<仓库>/storage/woo-uploads，然后：
composer install
php artisan bagisto:install --skip-env-check --skip-cloud-promotion --no-interaction   # admin@example.com / admin123
php artisan woocommerce:migrate --all
php artisan indexer:index --mode=full && php artisan optimize:clear && php artisan responsecache:clear
```

后台常驻可用 systemd 托管 `php artisan serve`（开机自启 + 崩溃重启）：

```bash
sudo tee /etc/systemd/system/bagisto.service >/dev/null <<'EOF'
[Unit]
Description=Bagisto storefront (php artisan serve)
After=network.target docker.service
Wants=docker.service
[Service]
Type=simple
User=ziang
WorkingDirectory=/home/ziang/projects/bagisto
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
Restart=always
RestartSec=3
[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload && sudo systemctl enable --now bagisto
```

> `php artisan serve` 是开发服务器（单线程），**仅限本地/测试**；生产用 §2 的容器。换机时按目标机调整 unit 里的 `User`/`WorkingDirectory`/`php` 路径。

---

## 4. 命令参考

| 命令 | 作用 |
|------|------|
| `php artisan woocommerce:migrate` | 一键迁移。**默认只导**：分类 → 属性 → 商品（含图片）。其余靠 `--with-*` / `--all` 追加 |
| `php artisan woocommerce:migrate --all` | 全量：上述全部 + 客户 + 订单 + 优惠券 + 评论 + **视频** + storefront 内容（品牌 / hero / 服务条 / 页脚 / CMS 页）+ **税率（欧洲 15%）** |
| `php artisan woocommerce:migrate:categories` | 仅分类 |
| `php artisan woocommerce:migrate:attributes` | 仅可配置属性及选项 |
| `php artisan woocommerce:migrate:products` | 仅商品 |
| `php artisan woocommerce:migrate:customers` | 从订单邮箱创建客户 |
| `php artisan woocommerce:migrate-content` | 仅重建 storefront 内容（店名 / 品牌 / hero / 首页区块 / 页脚 / CMS 页） |

> ⚠️ **没有独立的视频子命令**。视频只能通过主命令 `--with-videos`（或 `--all`）迁移；订单 / 优惠券 / 评论 / 内容 / 税率同理，都是主命令开关。

`woocommerce:migrate` 的选项：

```
--all             迁移“所有东西”：客户、订单、优惠券、评论、视频、storefront 内容
--fresh           清空之前的迁移映射，重新导入
--skip-images     不导入图片（快速试跑）
--with-customers  从订单邮箱创建客户
--with-orders     迁移历史订单（依赖客户）
--with-coupons    把优惠券迁移为购物车规则
--with-reviews    迁移商品评论
--with-videos     迁移商品视频
--with-content    替换 demo storefront 内容（店名 / CMS 页 / 首页 / 页脚 / 品牌 / hero）
--with-tax        建立欧洲各国 15% 税率并设为默认商品税分类（见 woo-importer.php 的 tax 配置）
--uploads=PATH    指定 wp-content/uploads 的绝对路径（覆盖配置；容器内迁移用它指到挂载点）
--strategy=auto   变体映射策略：auto（默认）| configurable | flatten
--limit=N         仅导入前 N 个商品（试跑用）
```

试跑（快速、无图、前 20 个）：`php artisan woocommerce:migrate --skip-images --limit=20`

---

## 5. 故障排查

| 现象 | 处理 |
|------|------|
| `WooCommerce database not reachable` | 检查 `WOO_DB_*`，确认 `woocommerce_import` 已导入；`php artisan config:clear` |
| 商品无图片 | `WOO_UPLOADS_PATH`（或 `--uploads`）须指向**含年月子目录**的 uploads；重导图片加 `--fresh` |
| 前台改动/数据不生效 | `php artisan view:clear && php artisan responsecache:clear`（启用了 Spatie 整页响应缓存） |
| 前台看不到商品 | `php artisan indexer:index --mode=full && php artisan optimize:clear` |
| 想重头再来 | `php artisan woocommerce:migrate --fresh`（清空映射并重建） |
| 容器内 `mysql -uroot` 连不上 | 内置库 root 走 socket 免密；外部库模式见 README §11 |
| 分类页 500、日志报 `cache/data/...: Failed to open stream`（Permission denied） | storage 里有 root 属主缓存文件（曾以 root 跑过 `docker exec ... artisan`）。修复：`docker exec <c> chown -R www-data:www-data storage bootstrap/cache` 再 `docker restart <c>`（entrypoint 已会在每次启动自愈）。根治：容器内 artisan 一律加 `-u www-data` |

---

## 6. 迁移后手动配置（上线前）

迁移工具只负责**目录与 storefront 内容**。以下站点运营配置需在 Bagisto 后台/`.env` 重新设置：

- **支付方式**：后台 → 配置 → 销售 → 支付方式（内置 Stripe / PayPal / Razorpay / PayU，填入密钥）。
- **物流方式**：后台 → 配置 → 销售 → 配送方式（免运费 / 统一运费 / 按重量）。
- **税率**：欧洲 15% 已由 `--with-tax` / `--all` 自动建好（见 `woo-importer.php` 的 `tax` 配置）；其余税规则在后台 → 配置 → 销售 → 税 里加。
- **邮件 / SMTP**：在 `.env` 配置 `MAIL_*`。
- **SEO 301 重定向**：迁移已尽量沿用商品/分类 slug，建议在反代层为旧链接配置 301。
- **域名 / DNS / HTTPS**：见 §7。

---

## 7. 正式上线（域名 + HTTPS）

单容器只监听 HTTP。上线用 **Caddy 反向代理 + 自动 Let's Encrypt 证书**坐在容器前面。`www` 为规范域名，apex 301 跳 www。

**架构**：`用户 → Caddy(:443, TLS) → 反代 → 容器 127.0.0.1:8080`

### 7.1 容器：只监听本地 8080 + 用最终 HTTPS 域名做 APP_URL

> 重建容器只是换端口/环境变量,**数据在命名卷里不受影响**;`APP_KEY` 烤在镜像里,重建不变。⚠️ 复用你现有的卷名(本项目生产是 `urm-mysql` / `urm-storage`)。

```bash
docker rm -f urmotorparts
docker run -d --name urmotorparts --restart always \
  -p 127.0.0.1:8080:80 \
  -v urm-mysql:/var/lib/mysql \
  -v urm-storage:/var/www/bagisto/storage \
  -e APP_URL=https://www.urmotorparts.com -e TZ=UTC \
  urmotorparts:prod
```

`bootstrap/app.php` 已 `trustProxies(at: '*')`,配合 Caddy 传的 `X-Forwarded-Proto`,全站链接/分页/表单/Cookie 都会正确用 HTTPS。

### 7.2 装 Caddy 并配置

```bash
sudo apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl gnupg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt-get update && sudo apt-get install -y caddy
```

`/etc/caddy/Caddyfile`：

```caddyfile
{
	email urmotorparts@gmail.com
}

www.urmotorparts.com {
	encode zstd gzip
	reverse_proxy 127.0.0.1:8080 {
		header_up Host {host}
		header_up X-Forwarded-Proto {scheme}
		header_up X-Forwarded-For {remote_host}
	}
}

urmotorparts.com {
	redir https://www.urmotorparts.com{uri} permanent
}
```

```bash
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl reload caddy
```

### 7.3 DNS + 防火墙（在各自控制台做）

- **云厂商安全组**：放通 **443** 入站（80 一般已通）。
- **DNS（本域名解析在阿里云/万网）**：把 A 记录指到新服务器 IP——`www`、`@`、以及泛解析 `*` 各一条 A → 新 IP。精确的 `www` 记录会优先于 `*`。
- DNS 生效后 `sudo systemctl reload caddy` 触发签发;证书 Let's Encrypt 自动续期。

### 7.4 校验

```bash
curl -sI https://www.urmotorparts.com/ | head -1          # 200
curl -so /dev/null -w '%{http_code} -> %{redirect_url}\n' https://urmotorparts.com/   # 301 -> www
curl -s https://www.urmotorparts.com/ | grep -o 'http://[0-9.]*' | sort -u           # 应为空
```

> ⚠️ **别把服务器 IP/裸 IP 当 APP_URL 迁移**。迁移器写内容时用**相对路径**(hero 图、页脚链接),CMS 正文里的旧站绝对链接也会被转相对,所以内容不含主机名、天然适配任何域名。若历史数据里仍残留某个旧的绝对基址,可全局替换(扫描所有文本列)：
> `UPDATE <表> SET <列> = REPLACE(<列>, 'http://OLD-BASE', 'https://www.urmotorparts.com')`,改完 `php artisan responsecache:clear`。
