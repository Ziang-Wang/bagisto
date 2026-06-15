# WooCommerce → Bagisto 部署指南

把 **urmotorparts**（WordPress + WooCommerce）的数据迁移到本仓库的 **Bagisto 2.4** 并部署。迁移逻辑封装在 `packages/Webkul/WooImporter` 包中：通过一个只读连接 `woocommerce` 读取旧库，使用 Bagisto 官方仓储（与后台创建商品完全相同的流程）写入新库。

> ⚠️ **安全提醒**：⓪ 同步源数据时**交互输入**源库密码（不写入文件/历史）。所有源站操作均为**只读**（SSH / `mysqldump`，不停止/重启进程、不锁表）。源服务器 IP、SSH 与数据库密码请勿写进本仓库；建议在迁移完成后轮换这些凭据。

---

## 1. 从零一键部署

> 一条龙：从源服务器全量同步资源（⓪）→ 起库恢复源数据 → 安装 Bagisto → 全量导入 → 启动。命令经过实测，跑完即得到完整站点（URmotorparts 品牌、图片 hero、6 项服务条、定制页脚，约 569 商品 / 16 订单 / 35 评论）。
>
> **架构**：宿主机 `php artisan serve`（:8000）+ 一个 Docker MySQL 容器（`bagisto-mysql`），容器内含两个库——`bagisto`（目标）与 `woocommerce_import`（Woo 源）。

**前置**：已安装 Docker、PHP 8.3（含 `gd`/`imagick`、`intl`、`mbstring`、`pdo_mysql`、`curl`、`zip`、`bcmath`）、Composer 2；能 SSH 到源服务器（若需 ⓪ 同步）。

**`.env` 关键项**（本机默认密码均为 `bagisto`）：

```dotenv
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bagisto
DB_USERNAME=root
DB_PASSWORD=bagisto

# ---- WooCommerce 迁移源（只读）----
WOO_DB_HOST=127.0.0.1
WOO_DB_PORT=3306
WOO_DB_DATABASE=woocommerce_import
WOO_DB_USERNAME=root
WOO_DB_PASSWORD=bagisto
WOO_DB_PREFIX=wp_
WOO_UPLOADS_PATH=/绝对路径/到/bagisto/storage/woo-uploads
WOO_VARIATION_STRATEGY=auto
WOO_DEFAULT_STOCK=1000
```

**部署命令**（在仓库根目录执行）：

```bash
# ── ⓪ 全量从源服务器同步资源（首次/换新机器时执行；已有本地资源则跳过）──
REMOTE=root@<源服务器IP>                                    # 源服务器（填你的 IP / 域名）
REMOTE_WP=/usr/local/lighthouse/softwares/wordpress         # WordPress 根目录
read -rsp 'WooCommerce 数据库密码: ' WOO_DB_PASS; echo       # 交互输入，不落盘、不进历史

# 0a. 数据库：远程一致性快照流式落地为本地 woo_dump.sql.gz（只读、不锁表、不影响线上下单）
ssh "$REMOTE" "MYSQL_PWD='$WOO_DB_PASS' mysqldump --single-transaction --quick --no-tablespaces -u wordpress wordpress | gzip" > woo_dump.sql.gz

# 0b. 图片：远程打包 wp-content/uploads，流式解压到 storage/woo-uploads（去掉顶层 uploads/，保留年月子目录）
mkdir -p storage/woo-uploads
ssh "$REMOTE" "tar czf - -C $REMOTE_WP/wp-content uploads" | tar xzf - -C storage/woo-uploads --strip-components=1

# 0c. 校验：dump 大小 + 图片年月目录
ls -lh woo_dump.sql.gz && find storage/woo-uploads -maxdepth 1 -type d | sort

# ── (可选) 完全重置：删除旧 MySQL 容器 + 数据卷（会清空 bagisto 与 woocommerce_import 两个库）──
docker stop bagisto-mysql && docker rm bagisto-mysql && docker volume rm bagisto-mysql-data

# ① 起 MySQL 容器（数据持久化在命名卷 bagisto-mysql-data；仅监听本机 3306）
docker run -d \
  --name bagisto-mysql \
  --restart unless-stopped \
  -e MYSQL_ROOT_PASSWORD=bagisto \
  -e MYSQL_DATABASE=bagisto \
  -e MYSQL_ROOT_HOST=% \
  -p 127.0.0.1:3306:3306 \
  -v bagisto-mysql-data:/var/lib/mysql \
  mysql:8.0 --default-authentication-plugin=mysql_native_password

# 等待就绪
until docker exec bagisto-mysql mysqladmin ping -uroot -pbagisto --silent 2>/dev/null | grep -q alive; do sleep 1; done

# ② 恢复 Woo 源库 woocommerce_import（导入器从这里读取旧数据）
docker exec bagisto-mysql mysql -uroot -pbagisto \
  -e "CREATE DATABASE IF NOT EXISTS woocommerce_import CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
zcat woo_dump.sql.gz | docker exec -i bagisto-mysql mysql -uroot -pbagisto woocommerce_import

# ③ PHP 依赖（首次或依赖变动时）
composer install

# ④ 全新安装 Bagisto（非交互；自动建管理员 admin@example.com / admin123）
php artisan bagisto:install --skip-env-check --skip-cloud-promotion --no-interaction

# ⑤ 全量导入（--all = 分类/属性/商品+图片/客户/订单/优惠券/评论/视频/storefront 内容+品牌+hero banner）
php artisan woocommerce:migrate --all

# ⑥ 重建索引 + 清缓存（含整页响应缓存）
php artisan indexer:index --mode=full
php artisan optimize:clear
php artisan responsecache:clear

# ⑦ 启动应用 —— 注册为 systemd 服务（后台运行 + 开机自启 + 崩溃自动重启）
#    （首次需创建单元；换机时按目标机调整 User/Group/WorkingDirectory/ExecStart 中的 php 路径）
sudo tee /etc/systemd/system/bagisto.service >/dev/null <<'EOF'
[Unit]
Description=Bagisto storefront (php artisan serve)
After=network.target docker.service
Wants=docker.service

[Service]
Type=simple
User=ziang
Group=ziang
WorkingDirectory=/home/ziang/projects/bagisto
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now bagisto        # 开机自启 + 立即启动
systemctl status bagisto --no-pager        # 查看状态
```

> **服务管理**：`sudo systemctl restart bagisto`（重启）、`stop`/`start`、`disable`（取消开机自启）；日志 `journalctl -u bagisto -f`。
> **开机自启全链路**：MySQL 容器已带 `--restart unless-stopped`，开机由 Docker 守护自动拉起；`bagisto.service` 经 `enable` 后由 systemd 在开机时启动——两者都会在重启后自动恢复。
> **注意**：`php artisan serve` 是开发服务器（单线程），仅适合本地/测试。正式生产用 `docker/production/` 的单容器镜像或 Nginx + PHP-FPM。

**访问**：前台 http://localhost:8000/ ；后台 http://localhost:8000/admin （`admin@example.com` / `admin123`）。

> - ⓪ 流式同步**不**在源服务器留临时文件；`woo_dump.sql.gz`（约 4.5 MB）落到仓库根目录，图片落到 `storage/woo-uploads/`（约 600 MB）。
> - 换新机器只需带上**代码仓库**，资源由 ⓪ 现拉；或把已有的 `woo_dump.sql.gz` + `storage/woo-uploads/` 一并拷过去并跳过 ⓪。两种方式结果一致——品牌 logo、hero banner（`branding.hero_banner` 指向 uploads 内的相对路径）等都由导入器自动复现。
> - 导入幂等；已迁移记录会跳过（依赖 `woo_import_maps` 映射表），可安全重跑。想重头再来加 `--fresh`。约 569 商品带图，导入耗时数分钟。
> - **生产部署**（Nginx + PHP-FPM + 内置 MySQL 的单容器镜像）见 `docker/production/README.md`。

---

## 2. 命令参考

| 命令 | 作用 |
|------|------|
| `php artisan woocommerce:migrate` | 一键迁移。**默认只导**：分类 → 属性 → 商品（含图片）。通过 `--with-*` / `--all` 追加其余内容 |
| `php artisan woocommerce:migrate --all` | 全量：上述全部 + 客户 + 订单 + 优惠券 + 评论 + **视频** + storefront 内容（品牌 / hero / 服务条 / 页脚 / CMS 页） |
| `php artisan woocommerce:migrate:categories` | 仅分类 |
| `php artisan woocommerce:migrate:attributes` | 仅可配置属性及选项 |
| `php artisan woocommerce:migrate:products` | 仅商品 |
| `php artisan woocommerce:migrate:customers` | 从订单邮箱创建客户 |
| `php artisan woocommerce:migrate-content` | 仅重建 storefront 内容（店名 / 品牌 / hero / 首页区块 / 页脚 / CMS 页） |

> ⚠️ **没有独立的视频子命令**。视频只能通过主命令 `--with-videos`（或 `--all`）迁移；订单 / 优惠券 / 评论 / 内容同理，都是主命令开关。

`woocommerce:migrate` 的选项：

```
--all             迁移“所有东西”：客户、订单、优惠券、评论、视频、storefront 内容（打开下面全部 --with-*）
--fresh           清空之前的迁移映射，重新导入
--skip-images     不导入图片（快速试跑）
--with-customers  从订单邮箱创建客户（注册客户 + 访客下单邮箱）
--with-orders     迁移历史订单（依赖客户）
--with-coupons    把优惠券迁移为购物车规则
--with-reviews    迁移商品评论
--with-videos     迁移商品视频
--with-content    替换 demo storefront 内容（店名 / CMS 页 / 首页 / 页脚 / 品牌 / hero）
--uploads=PATH    指定本机 wp-content/uploads 的绝对路径（覆盖配置）
--strategy=auto   变体映射策略：auto（默认）| configurable | flatten
--limit=N         仅导入前 N 个商品（试跑用）
```

试跑（快速、无图、前 20 个）：`php artisan woocommerce:migrate --skip-images --limit=20`

---

## 3. 故障排查

| 现象 | 处理 |
|------|------|
| `WooCommerce database not reachable` | 检查 `.env` 的 `WOO_DB_*`，确认 `woocommerce_import` 已导入；`php artisan config:clear` |
| 商品无图片 | 确认 `WOO_UPLOADS_PATH` 指向**含年月子目录**的 uploads 路径；重导图片需 `woocommerce:migrate:products --fresh` |
| 前台改动/数据不生效 | `php artisan view:clear && php artisan responsecache:clear`（本项目启用了 Spatie 整页响应缓存） |
| 前台看不到商品 | `php artisan indexer:index --mode=full && php artisan optimize:clear` |
| 想重头再来 | `php artisan woocommerce:migrate --fresh`（清空映射并重建） |
| 图片转码报错 | 确认 PHP 已装 `gd` 或 `imagick` 扩展 |

---

## 4. 迁移后手动配置（上线前）

迁移工具只负责**目录与 storefront 内容**。以下站点运营配置需在 Bagisto 后台/`.env` 重新设置：

- **支付方式**：后台 → 配置 → 销售 → 支付方式（内置 Stripe / PayPal / Razorpay / PayU，填入密钥）。
- **物流方式**：后台 → 配置 → 销售 → 配送方式（免运费 / 统一运费 / 按重量）。
- **税率**：后台 → 配置 → 销售 → 税。
- **邮件 / SMTP**：在 `.env` 配置 `MAIL_*`。
- **SEO 301 重定向**：迁移已尽量沿用商品/分类 slug，建议在 Nginx 层为旧链接配置 301。
- **域名 / DNS / HTTPS**：测试无误后切换解析并配置证书。
