<?php
// 单词打地鼠 API 路由（兼容带连字符的路径）

Route::get('api/user/get-info', 'api/user/getInfo');
Route::post('api/user/wx-login', 'api/user/wxLogin');
Route::get('api/user/error-word', 'api/user/errorWord');
Route::post('api/user/remove-word', 'api/user/removeWord');
Route::get('api/user/record-list', 'api/user/recordList');
Route::get('api/user/badge', 'api/user/badge');

Route::get('api/word/lib-list', 'api/word/libList');

Route::post('api/game/start', 'api/game/start');
Route::post('api/game/save-round', 'api/game/saveRound');

return [];
