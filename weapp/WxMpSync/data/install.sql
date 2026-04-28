CREATE TABLE IF NOT EXISTS `ey_weapp_wxmp_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `aid` int(11) NOT NULL COMMENT '文章ID',
  `title` varchar(255) DEFAULT NULL COMMENT '文章标题',
  `media_id` varchar(255) DEFAULT NULL COMMENT '公众号草稿media_id',
  `publish_id` varchar(255) DEFAULT NULL COMMENT '发布ID',
  `status` tinyint(1) DEFAULT 0 COMMENT '0待同步 1草稿成功 2发布成功 -1失败',
  `err_msg` text COMMENT '错误信息',
  `add_time` int(11) DEFAULT 0,
  `update_time` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aid` (`aid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ey_weapp_wxmp_sync_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '配置名称',
  `value` text COMMENT '配置值',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
