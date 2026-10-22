-- 数据库：zeabur_pro
-- 在 phpstudy 的 MySQL 里执行本文件即可
-- 默认账号 admin  密码 123456

CREATE DATABASE IF NOT EXISTS `zeabur_pro` DEFAULT CHARACTER SET utf8;
USE `zeabur_pro`;

CREATE TABLE IF NOT EXISTS `user_db` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '登录名',
  `password` char(32) NOT NULL COMMENT 'md5密码',
  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1正常 0禁用',
  `create_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='用户表';

CREATE TABLE IF NOT EXISTS `system_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `config_key` varchar(50) NOT NULL COMMENT '配置键',
  `config_value` varchar(255) NOT NULL DEFAULT '' COMMENT '配置值',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='系统配置';

INSERT INTO `user_db` (`username`, `password`, `nickname`, `status`, `create_time`)
VALUES ('admin', 'e10adc3949ba59abbe56e057f20f883e', '管理员', 1, NOW());

INSERT INTO `system_config` (`config_key`, `config_value`) VALUES
('site_name', '后台管理系统'),
('site_notice', '欢迎登录');
