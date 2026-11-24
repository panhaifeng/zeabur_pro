-- 用户意见反馈表（小程序「提建议」）
CREATE TABLE IF NOT EXISTS `suggestion` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '提交用户ID，游客也可',
  `content` varchar(1000) NOT NULL DEFAULT '' COMMENT '建议内容',
  `contact` varchar(100) NOT NULL DEFAULT '' COMMENT '可选联系方式',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0未读 1已读',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户建议反馈';
