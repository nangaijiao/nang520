# WxMpSync（EYOUCMS 插件）

## 1. 插件说明
WxMpSync 用于将 EYOUCMS 已发布文章自动同步到微信公众号草稿箱，支持：

- 自动同步（发布后/定时）
- 后台手动同步
- 同步日志管理
- 可选自动发布（默认关闭）

> 默认只创建公众号草稿，不自动群发。

## 2. 目录结构

```text
weapp/WxMpSync/
├── WxMpSync.php
├── config.php
├── controller/
│   └── WxMpSync.php
├── logic/
│   └── WxMpSyncLogic.php
├── model/
│   └── WxMpSyncModel.php
├── template/
│   ├── index.htm
│   ├── log.htm
│   └── config.htm
├── data/
│   ├── install.sql
│   └── uninstall.sql
├── static/
│   └── css/
└── README.md
```

## 3. 安装步骤
1. 将 `weapp/WxMpSync` 上传到站点插件目录。
2. 进入 EYOUCMS 后台 -> 插件管理，安装并启用 `WxMpSync`。
3. 在插件配置中填写：
   - AppID / AppSecret
   - 网站域名
   - 默认作者
   - 自动同步/自动发布开关
   - 默认封面图
4. 打开“手动同步”页面，输入文章ID测试。

## 4. 同步流程
1. 读取 `ey_archives` 与 `ey_article_content`。
2. 获取 `access_token`（本地缓存）。
3. 上传封面图为永久素材。
4. 上传正文图片并替换地址。
5. 创建微信公众号草稿。
6. 记录日志。
7. 若开启自动发布，调用发布接口。

## 5. 定时任务
脚本：`cli/wx_sync_cron.php`

宝塔计划任务示例：

```bash
cd /www/wwwroot/网站目录 && php cli/wx_sync_cron.php
```

## 6. 异常处理
以下情况均会写入日志：
- AppID/AppSecret 未配置
- access_token 获取失败
- 文章不存在/未发布/正文为空
- 封面图不存在
- 微信接口错误
- 图片上传失败
- 草稿创建失败
- 自动发布失败
- 已同步文章重复提交

## 7. 兼容性
- PHP 7.2+
- Linux / Nginx / 宝塔
- EYOUCMS（插件方式，不修改核心文件）
