<?php

function api_word_lib_list() {
    wm_auth_user(true);
    $list = wm_fetch_all("SELECT id, lib_name, lib_desc, status FROM word_lib WHERE status=1 ORDER BY id ASC");
    wm_ok(array('list' => $list));
}
