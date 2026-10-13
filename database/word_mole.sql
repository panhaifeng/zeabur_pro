-- 单词打地鼠（兼容 MySQL 5.6+ / 8.0）
-- 在库 zeabur_pro 中执行

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '用户主键',
  `openid` varchar(100) DEFAULT NULL COMMENT '微信openid，游客为NULL',
  `anonymous_id` varchar(64) DEFAULT NULL COMMENT '游客ID',
  `nickname` varchar(50) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_openid` (`openid`),
  UNIQUE KEY `uk_anonymous` (`anonymous_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `word_lib` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lib_name` varchar(50) NOT NULL COMMENT '词库名称',
  `lib_desc` varchar(200) DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1启用 0关闭',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `word_item` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lib_id` int NOT NULL COMMENT '词库id',
  `word` varchar(50) NOT NULL COMMENT '英文单词',
  `phonetic` varchar(100) DEFAULT NULL COMMENT '音标',
  `meaning` varchar(100) NOT NULL COMMENT '正确释义',
  `distract` varchar(255) DEFAULT NULL COMMENT '干扰释义，逗号分隔',
  PRIMARY KEY (`id`),
  KEY `idx_lib` (`lib_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `game_record` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT '用户ID',
  `lib_id` int NOT NULL,
  `difficult` varchar(20) NOT NULL COMMENT '简单/普通/困难',
  `score` int NOT NULL DEFAULT '0',
  `right_count` int NOT NULL DEFAULT '0',
  `total_count` int NOT NULL DEFAULT '10',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `uid` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_error_word` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `word_item_id` int NOT NULL,
  `add_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_word` (`user_id`,`word_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_badge` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `badge_key` varchar(50) NOT NULL,
  `badge_name` varchar(50) NOT NULL,
  `unlock_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_badge` (`user_id`,`badge_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 词库
INSERT INTO `word_lib` (`id`, `lib_name`, `lib_desc`, `status`) VALUES
(1, '初中词汇', '趣味练习常用初中词汇', 1),
(2, '高中词汇', '趣味练习常用高中词汇', 1),
(3, '四级词汇', '趣味练习常用四级词汇', 1)
ON DUPLICATE KEY UPDATE lib_name=VALUES(lib_name);

-- 初中
INSERT INTO `word_item` (`lib_id`, `word`, `phonetic`, `meaning`, `distract`) VALUES
(1, 'apple', '/ˈæpl/', '苹果', '香蕉,橙子,葡萄'),
(1, 'book', '/bʊk/', '书', '笔,桌子,书包'),
(1, 'cat', '/kæt/', '猫', '狗,鸟,鱼'),
(1, 'dog', '/dɒɡ/', '狗', '猫,马,羊'),
(1, 'water', '/ˈwɔːtə/', '水', '火,牛奶,果汁'),
(1, 'school', '/skuːl/', '学校', '医院,公园,商店'),
(1, 'happy', '/ˈhæpi/', '开心的', '难过的,生气的,害怕的'),
(1, 'friend', '/frend/', '朋友', '老师,家人,同学'),
(1, 'green', '/ɡriːn/', '绿色', '红色,蓝色,黄色'),
(1, 'music', '/ˈmjuːzɪk/', '音乐', '电影,舞蹈,绘画'),
(1, 'family', '/ˈfæməli/', '家庭', '学校,公司,班级'),
(1, 'run', '/rʌn/', '跑', '走,跳,坐'),
(1, 'eat', '/iːt/', '吃', '喝,睡,看'),
(1, 'sleep', '/sliːp/', '睡觉', '工作,学习,说话'),
(1, 'teacher', '/ˈtiːtʃə/', '老师', '学生,医生,司机');

-- 高中
INSERT INTO `word_item` (`lib_id`, `word`, `phonetic`, `meaning`, `distract`) VALUES
(2, 'achieve', '/əˈtʃiːv/', '达到；完成', '放弃,开始,忘记'),
(2, 'benefit', '/ˈbenɪfɪt/', '益处；受益', '伤害,损失,风险'),
(2, 'challenge', '/ˈtʃælɪndʒ/', '挑战', '机会,任务,困难'),
(2, 'develop', '/dɪˈveləp/', '发展；开发', '破坏,停止,减少'),
(2, 'environment', '/ɪnˈvaɪrənmənt/', '环境', '气候,天气,温度'),
(2, 'freedom', '/ˈfriːdəm/', '自由', '规则,秩序,纪律'),
(2, 'generate', '/ˈdʒenəreɪt/', '产生；生成', '消耗,减少,销毁'),
(2, 'habit', '/ˈhæbɪt/', '习惯', '爱好,技能,兴趣'),
(2, 'improve', '/ɪmˈpruːv/', '改善；提高', '降低,恶化,保持'),
(2, 'knowledge', '/ˈnɒlɪdʒ/', '知识', '经验,智慧,信息'),
(2, 'opportunity', '/ˌɒpəˈtjuːnəti/', '机会', '危机,挑战,压力'),
(2, 'protect', '/prəˈtekt/', '保护', '攻击,破坏,放弃'),
(2, 'quality', '/ˈkwɒləti/', '质量；品质', '数量,价格,速度'),
(2, 'responsible', '/rɪˈspɒnsəbl/', '负责的', '随意的,粗心的,被动的'),
(2, 'success', '/səkˈses/', '成功', '失败,努力,计划');

-- 四级
INSERT INTO `word_item` (`lib_id`, `word`, `phonetic`, `meaning`, `distract`) VALUES
(3, 'abandon', '/əˈbændən/', '放弃；遗弃', '坚持,继续,保留'),
(3, 'accurate', '/ˈækjərət/', '准确的', '模糊的,错误的,大概的'),
(3, 'candidate', '/ˈkændɪdət/', '候选人', '评委,观众,教练'),
(3, 'decade', '/ˈdekeɪd/', '十年', '一年,一月,一周'),
(3, 'efficient', '/ɪˈfɪʃnt/', '高效的', '缓慢的,浪费的,复杂的'),
(3, 'frequent', '/ˈfriːkwənt/', '频繁的', '偶尔的,罕见的,固定的'),
(3, 'guarantee', '/ˌɡærənˈtiː/', '保证；担保', '怀疑,否认,猜测'),
(3, 'identical', '/aɪˈdentɪkl/', '完全相同的', '不同的,相似的,相反的'),
(3, 'justify', '/ˈdʒʌstɪfaɪ/', '证明正当', '批评,反对,质疑'),
(3, 'maintain', '/meɪnˈteɪn/', '维持；维护', '破坏,放弃,忽略'),
(3, 'negotiate', '/nɪˈɡəʊʃieɪt/', '谈判；协商', '争吵,命令,拒绝'),
(3, 'obstacle', '/ˈɒbstəkl/', '障碍', '帮助,机会,捷径'),
(3, 'perspective', '/pəˈspektɪv/', '视角；观点', '事实,证据,结果'),
(3, 'reliable', '/rɪˈlaɪəbl/', '可靠的', '危险的,虚假的,随意的'),
(3, 'significant', '/sɪɡˈnɪfɪkənt/', '重要的；显著的', '微小的,普通的,临时的');
