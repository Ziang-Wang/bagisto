# WooCommerce → Bagisto 迁移与部署指南

本指南帮助你把 **urmotorparts**（WordPress 6.9.4 + WooCommerce 10.6.1）迁移到本仓库的 **Bagisto 2.4** 电商系统，并完成部署。

> ⚠️ **安全提醒**：你在沟通中以明文提供了源服务器 SSH 密码与数据库密码。迁移完成后请**立即修改**这些密码。本指南中的所有源服务器操作都是**只读**的，不会停止/重启任何进程。

---

## 1. 源站现状（已分析）

| 项目 | 值 |
|------|-----|
| 平台 | WordPress 6.9.4 + WooCommerce 10.6.1 |
| 源码路径 | `/usr/local/lighthouse/softwares/wordpress` |
| 数据库 | `wordpress`（用户 `wordpress`，前缀 `wp_`，utf8mb4） |
| 数据库大小 | 约 63 MB（66 张表） |
| 图片资源 | `wp-content/uploads` 约 594 MB（1545 个附件） |
| 货币 / 国家 | USD / CN，重量 kg、尺寸 cm |
| 商品 | 569 个（全部是 variable 类型） |
| 变体 | 704 个（**484 个商品仅 1 个变体**，85 个有多个变体） |
| 分类 | 16 个 `product_cat`（两级层级），55 个标签 |
| 订单 | 16 个（全部访客下单，HPOS 存储） |
| 注册客户 | 0（仅 1 个后台管理员） |

### 映射策略（自动）

- **单变体商品（484 个）→ Bagisto 简单商品（simple）**，使用变体的价格/SKU/库存。
- **多变体商品（85 个）→ Bagisto 可配置商品（configurable）**，每个 WooCommerce 变体属性会被建成一个 Bagisto 全局 `select` 属性（如 `brake_disc`、`color_option`），并生成对应选项。
- **图片**：商品主图 + 图集 + 变体图，按 `_thumbnail_id` 解析复制，导入时自动转 webp。
- **订单**：默认不迁移（16 个历史访客订单，价值低、风险高，建议在旧站归档）。如需，可用可选命令把订单邮箱建成客户。

---

## 2. 迁移工具说明

迁移逻辑封装在新增的 Bagisto 包 **`packages/Webkul/WooImporter`** 中，通过一个独立的只读数据库连接 `woocommerce` 读取旧数据，使用 Bagisto 官方仓储（与后台创建商品完全相同的流程）写入新库。

提供的 Artisan 命令：

| 命令 | 作用 |
|------|------|
| `php artisan woocommerce:migrate` | 一键迁移：分类 → 属性 → 商品（→ 客户，可选） |
| `php artisan woocommerce:migrate:categories` | 仅迁移分类 |
| `php artisan woocommerce:migrate:attributes` | 仅迁移可配置属性及选项 |
| `php artisan woocommerce:migrate:products` | 仅迁移商品 |
| `php artisan woocommerce:migrate:customers` | 从订单邮箱创建客户（可选） |

常用选项：

```
--fresh           清空之前的迁移映射，重新导入（幂等可重跑）
--skip-images     不导入图片（快速试跑）
--with-customers  顺带从订单邮箱创建客户
--uploads=PATH    指定本机 wp-content/uploads 的绝对路径
--strategy=auto   变体映射策略：auto（默认）| configurable | flatten
--limit=N         仅导入前 N 个商品（试跑用）
```

> 迁移工具是**幂等**的：已迁移的记录会被跳过（依赖 `woo_import_maps` 映射表），可安全多次运行。重新来过时加 `--fresh`。

---

## 3. 完整迁移流程

### 步骤 0 · 准备一台目标机

目标机需要：PHP 8.3（含 `gd`/`imagick`、`intl`、`mbstring`、`pdo_mysql`、`curl`、`openssl`、`zip`、`bcmath`、`calendar`、`tokenizer`）、Composer 2、MySQL 8、Node.js 18+ 与 npm。

### 步骤 1 · 从源服务器导出数据（只读，生产安全）

在**你的目标机**上执行（通过 SSH 远程导出再拉取，全程只读）：

```bash
# 1a. 远程导出数据库到源服务器的临时文件（mysqldump 只读，不锁生产可加 --single-transaction）
ssh root@49.51.231.170 \
  'mysqldump --single-transaction --quick --no-tablespaces \
     -u wordpress -p"*7zKxv*R24L2" wordpress \
     | gzip > /tmp/woocommerce_dump.sql.gz'

# 1b. 拉取数据库到本机
scp root@49.51.231.170:/tmp/woocommerce_dump.sql.gz ./woocommerce_dump.sql.gz

# 1c. 打包并拉取图片资源（只读）
ssh root@49.51.231.170 \
  'cd /usr/local/lighthouse/softwares/wordpress/wp-content && tar czf /tmp/woo_uploads.tar.gz uploads'
scp root@49.51.231.170:/tmp/woo_uploads.tar.gz ./woo_uploads.tar.gz

# 1d.（可选）清理源服务器上的临时文件，避免占用空间
ssh root@49.51.231.170 'rm -f /tmp/woocommerce_dump.sql.gz /tmp/woo_uploads.tar.gz'
```

> `--single-transaction` 让导出在一致性快照中进行，不会锁表、不影响线上下单。

### 步骤 2 · 安装 Bagisto（在目标机本仓库根目录）

```bash
# 2a. 安装 PHP 依赖（会自动加载新包的 autoload）
composer install

# 2b. 生成 .env
cp .env.example .env

# 2c. 编辑 .env，填写 Bagisto 主库与站点信息（见下方 .env 示例）
#     至少配置 APP_URL、APP_KEY、DB_*

# 2d. 一键安装（建表 + 基础数据 + 资源构建）
php artisan bagisto:install
```

安装时按提示填写后台账号、默认语言（建议 `en`）、货币（建议 `USD`）等。安装完成后会有一个空的商城骨架。

### 步骤 3 · 把旧库导入到一个独立的临时数据库

```bash
# 3a. 新建临时库
mysql -u root -p -e "CREATE DATABASE woocommerce_import CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3b. 导入旧库 dump
gunzip -c woocommerce_dump.sql.gz | mysql -u root -p woocommerce_import
```

### 步骤 4 · 解压图片并配置迁移参数

```bash
# 4a. 解压图片到任意目录（记下 uploads 的绝对路径）
mkdir -p storage/woo-uploads
tar xzf woo_uploads.tar.gz -C storage/woo-uploads --strip-components=1
# 解压后应存在：storage/woo-uploads/2024/... 等年月目录
```

在 `.env` 末尾追加旧库连接与图片路径（连接已在 `config/database.php` 预置好名为 `woocommerce` 的只读连接）：

```dotenv
# ---- WooCommerce 迁移源（只读）----
WOO_DB_HOST=127.0.0.1
WOO_DB_PORT=3306
WOO_DB_DATABASE=woocommerce_import
WOO_DB_USERNAME=root
WOO_DB_PASSWORD=你的MySQL密码
WOO_DB_PREFIX=wp_

# 本机 wp-content/uploads 的绝对路径
WOO_UPLOADS_PATH=/绝对路径/到/bagisto/storage/woo-uploads

# 变体策略：auto | configurable | flatten
WOO_VARIATION_STRATEGY=auto
# 不管理库存的商品默认库存数量
WOO_DEFAULT_STOCK=1000
```

> 改完 `.env` 后执行 `php artisan config:clear` 确保配置生效。

### 步骤 5 · 运行迁移

**先小规模试跑**（不导图片、只导 20 个商品）确认无误：

```bash
php artisan config:clear
php artisan woocommerce:migrate --skip-images --limit=20
```

确认分类、属性、商品都正确创建后，**正式全量迁移**：

```bash
php artisan woocommerce:migrate
# 如需顺带创建客户：php artisan woocommerce:migrate --with-customers
```

> 图片导入较耗时（约 1500 张，会逐张转 webp）。如果先跑过 `--skip-images`，可直接再次运行（幂等），或单独跑 `php artisan woocommerce:migrate:products`（已存在的商品会跳过，但图片需要 `--fresh` 重导）。

### 步骤 6 · 重建索引

```bash
php artisan indexer:index --mode=full
php artisan optimize:clear
```

### 步骤 7 · 验证

- 后台（`/admin`）→ 目录 → 商品：应有约 569 个商品（含可配置）。
- 后台 → 目录 → 分类：应有 16 个分类，层级正确。
- 后台 → 目录 → 属性：应有新建的 `brake_disc`、`color_option` 等 select 属性。
- 前台分类页/商品页应能正常浏览、可配置商品能选择变体。

迁移完成后，临时库 `woocommerce_import` 与 `storage/woo-uploads` 可在确认无误后删除。

---

## 4. 生产部署

### 方式 A：Docker（仓库已自带，推荐）

仓库 `docker/production/` 已提供 Nginx + PHP-FPM + MySQL 的生产镜像与配置：

```bash
# 构建并启动（按需调整 docker-compose 中的环境变量与端口）
docker compose -f docker-compose.yml up -d --build
```

数据迁移可在容器内执行上面的 `woocommerce:migrate` 流程（把 dump 与 uploads 挂载进容器，或拷贝进容器后运行）。

### 方式 B：传统 Nginx + PHP-FPM + MySQL

1. 将代码部署到服务器（如 `/var/www/bagisto`），`composer install --no-dev --optimize-autoloader`。
2. 配置 `.env`（生产值：`APP_ENV=production`、`APP_DEBUG=false`、真实 `APP_URL`、数据库、邮件、Redis 等）。
3. 构建前端资源：
   ```bash
   cd packages/Webkul/Admin && npm ci && npm run build && cd -
   cd packages/Webkul/Shop  && npm ci && npm run build && cd -
   ```
4. 权限：`storage/` 与 `bootstrap/cache/` 对 PHP-FPM 用户可写。
5. 缓存优化：`php artisan optimize`（config/route/view 缓存）。
6. Nginx `root` 指向 `public/`，PHP 请求转发到 php-fpm；参考 `docker/production/nginx.conf`。
7. 计划任务（队列、cron）：
   ```cron
   * * * * * cd /var/www/bagisto && php artisan schedule:run >> /dev/null 2>&1
   ```
   并用 supervisor 守护 `php artisan queue:work`（参考 `docker/production/supervisord.conf`）。

---

## 5. 迁移后需要手动处理的配置

迁移工具只负责**目录数据**。以下属于站点运营配置，需在 Bagisto 后台重新设置（不会自动从 WooCommerce 带过来）：

- **支付方式**：源站用 Stripe / PayPal / Payoneer。Bagisto 内置 Stripe、PayPal、Razorpay、PayU，在 后台 → 配置 → 销售 → 支付方式 中填入你的密钥。
- **物流方式**：源站用了运单跟踪等插件，Bagisto 在 后台 → 配置 → 销售 → 配送方式 中配置（免运费/统一运费/按重量等）。
- **税率**：后台 → 配置 → 销售 → 税。
- **货币/区域**：确认默认货币 USD、面向国家。
- **邮件/SMTP**：源站用 WP Mail SMTP，在 `.env` 配置 `MAIL_*`。
- **SEO 301 重定向**：WooCommerce 与 Bagisto 的 URL 结构不同。迁移已尽量沿用商品/分类 slug，建议在 Nginx 层为旧链接配置 301，保住搜索引擎权重。
- **域名/DNS**：测试无误后，把 `www.urmotorparts.com` 解析切到新服务器，并配置 HTTPS 证书。

---

## 6. 订单 / 客户迁移（可选）

源站只有 16 个历史**访客**订单、无注册客户。建议：

- **保留旧站只读归档**一段时间用于历史订单查询，新订单从 Bagisto 开始；或
- 运行 `php artisan woocommerce:migrate:customers` 把订单中的客户邮箱建成 Bagisto 客户（随机密码、未验证，可让其走找回密码流程）。

> 订单本身涉及 Bagisto 多张 Sales 表（订单/发票/发货/退款），自动迁移收益低且风险高，本工具未包含。如确有需要可在确认目录迁移无误后单独评估。

---

## 7. 故障排查

| 现象 | 处理 |
|------|------|
| `WooCommerce database not reachable` | 检查 `.env` 的 `WOO_DB_*`，确认临时库已导入；运行 `php artisan config:clear` |
| 商品无图片 | 确认 `WOO_UPLOADS_PATH` 指向解压后**含年月子目录**的 uploads 路径；重导图片需 `woocommerce:migrate:products --fresh` |
| 想重头再来 | `php artisan woocommerce:migrate --fresh`（会清空映射并重建） |
| 前台看不到商品 | 运行 `php artisan indexer:index --mode=full` 与 `php artisan optimize:clear` |
| 图片转码报错 | 确认 PHP 已装 `gd` 或 `imagick` 扩展 |

---

## 8. 命令速查

```bash
# 试跑（快速、无图、前20个）
php artisan woocommerce:migrate --skip-images --limit=20

# 全量迁移
php artisan woocommerce:migrate

# 重头再来
php artisan woocommerce:migrate --fresh

# 分步执行
php artisan woocommerce:migrate:categories
php artisan woocommerce:migrate:attributes
php artisan woocommerce:migrate:products --uploads=/path/to/uploads
php artisan woocommerce:migrate:customers

# 重建索引
php artisan indexer:index --mode=full
```
