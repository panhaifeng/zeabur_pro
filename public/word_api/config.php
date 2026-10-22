<?php
/**
 * 单词打地鼠 - 配置（PHP5.1 兼容写法）
 */
$WM_CONFIG = array(
    'db_host' => getenv('MYSQL_HOST') ? getenv('MYSQL_HOST') : '127.0.0.1',
    'db_user' => getenv('MYSQL_USERNAME') ? getenv('MYSQL_USERNAME') : (getenv('MYSQL_USER') ? getenv('MYSQL_USER') : 'root'),
    'db_pass' => (getenv('MYSQL_PASSWORD') !== false && getenv('MYSQL_PASSWORD') !== '') ? getenv('MYSQL_PASSWORD') : 'root',
    'db_name' => getenv('MYSQL_DATABASE') ? getenv('MYSQL_DATABASE') : 'zeabur_pro',
    'db_port' => getenv('MYSQL_PORT') ? getenv('MYSQL_PORT') : '3306',
    'db_charset' => 'utf8mb4',
    // 微信小程序（请替换为真实 AppID / AppSecret）
    'wx_appid' => getenv('WX_APPID') ? getenv('WX_APPID') : '',
    'wx_secret' => getenv('WX_SECRET') ? getenv('WX_SECRET') : '',
    'token_salt' => 'word_mole_salt_2026',
);
