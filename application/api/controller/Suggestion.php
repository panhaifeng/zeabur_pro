<?php
namespace app\api\controller;

use think\Db;

/**
 * 小程序用户意见反馈
 */
class Suggestion extends Base
{
    /**
     * 提交建议
     * POST /api/suggestion/submit  content
     * 不收集手机号等联系方式/身份信息
     */
    public function submit()
    {
        $content = trim(input('content', ''));

        if ($content === '') {
            $this->jsonOut(1, '请填写建议内容');
        }
        if (mb_strlen($content) > 1000) {
            $this->jsonOut(1, '建议内容请控制在 1000 字以内');
        }

        $userId = isset($this->user['id']) ? intval($this->user['id']) : 0;
        $id = Db::name('suggestion')->insertGetId(array(
            'user_id'     => $userId,
            'content'     => $content,
            'contact'     => '',
            'status'      => 0,
            'create_time' => date('Y-m-d H:i:s'),
        ));

        if (!$id) {
            $this->jsonOut(1, '提交失败，请稍后重试');
        }

        $this->jsonOut(0, '提交成功，感谢你的建议', array('id' => intval($id)));
    }
}
