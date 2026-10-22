<?php
namespace app\index\controller;

use think\Controller;
use think\facade\Cache;
use think\facade\Session;

/**
 * AI 流式对话控制器
 * 接口：
 *   POST /index/ai/streamChat  SSE 流式打字机输出
 *   POST /index/ai/chat        一次性完整返回（调试用）
 *
 * 业务码约定：
 *   0     成功
 *   400   参数错误
 *   401   密钥/鉴权错误（不暴露原始报错）
 *   429   IP 请求过于频繁
 *   4001  内容安全违规
 *   4002  请求超时
 *   5000  上游模型服务异常
 *   5001  系统异常
 */
class Ai extends Controller
{
    // ===================== 配置从 .env 读取，禁止硬编码密钥、禁止输出到前端/日志 =====================
    /** @var string 火山方舟 API Key（ARK_API_KEY） */
    private $arkApiKey = '';

    /** @var string 火山方舟聊天补全地址（ARK_API_URL） */
    private $arkApiUrl = '';

    /** @var string 火山方舟模型 ID（ARK_MODEL_ID） */
    private $arkModelId = '';

    /** @var string 微信小程序 AppID（WX_APPID） */
    private $wxAppId = '';

    /** @var string 微信小程序 AppSecret（WX_SECRET） */
    private $wxAppSecret = '';

    /** @var string 系统提示词 */
    private $systemPrompt = '你是友好简洁的AI助手，回答通俗易懂，尽量简短';

    /** @var int 连接超时（秒） */
    private $connectTimeout = 10;

    /** @var int 总超时（秒） */
    private $requestTimeout = 60;

    /** @var string 会话历史在 Session 中的键名 */
    private $historyKey = 'ai_chat_history';

    protected function initialize()
    {
        // 跨域预检（本地调试 / 部分网关场景）
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, token, Authorization, X-Requested-With');
        if (request()->isOptions()) {
            exit;
        }

        // 密钥与配置一律从环境变量 /.env 读取，不写死在代码里
        // 兼容：TP env()、系统 getenv()、以及已有 config/wechat.php（仅 AppId/Secret）
        $this->arkApiKey = $this->envVal('ARK_API_KEY', 'ark_api_key');
        $this->arkApiUrl = $this->envVal('ARK_API_URL', 'ark_api_url', 'https://ark.cn-beijing.volces.com/api/v3/chat/completions');
        $this->arkModelId = $this->envVal('ARK_MODEL_ID', 'ark_model_id', 'doubao-seed-2-1-lite-260915');
        $this->wxAppId = $this->envVal('WX_APPID', 'wx_appid', (string)config('wechat.appid'));
        $this->wxAppSecret = $this->envVal('WX_SECRET', 'wx_secret', (string)config('wechat.appsecret'));
    }

    /**
     * 读取环境变量：优先 .env / Env，其次系统 getenv（兼容 Zeabur 不带 PHP_ 前缀）
     */
    private function envVal($sysKey, $tpKey, $default = '')
    {
        $v = env($tpKey, null);
        if ($v !== null && $v !== '') {
            return (string)$v;
        }
        $g = getenv($sysKey);
        if ($g !== false && $g !== '') {
            return (string)$g;
        }
        // Zeabur / 部分面板可能注入小写名
        $g2 = getenv(strtolower($sysKey));
        if ($g2 !== false && $g2 !== '') {
            return (string)$g2;
        }
        return (string)$default;
    }

    /**
     * 校验关键密钥是否已配置（不回传密钥内容）
     */
    private function assertSecretsReady()
    {
        if ($this->arkApiKey === '' || $this->arkModelId === '' || $this->arkApiUrl === '') {
            return array('ok' => false, 'code' => 401, 'msg' => '服务鉴权失败，请稍后再试');
        }
        if ($this->wxAppId === '' || $this->wxAppSecret === '') {
            return array('ok' => false, 'code' => 4001, 'msg' => '内容安全服务暂不可用，请稍后再试');
        }
        return array('ok' => true, 'code' => 0, 'msg' => 'ok');
    }

    /**
     * 写运行日志（不含密钥），方便排查微信/方舟调用失败
     */
    private function aiLog($msg)
    {
        try {
            $line = date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL;
            // ThinkPHP 5.1 使用常量 RUNTIME_PATH（无 runtime_path() 助手函数）
            $dir = (defined('RUNTIME_PATH') ? RUNTIME_PATH : (dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR))
                . 'log' . DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($dir . 'ai_' . date('Ym') . '.log', $line, FILE_APPEND);
        } catch (\Exception $e) {
            // 忽略日志失败
        }
    }

    /**
     * 流式 SSE 对话
     * POST: message 用户输入；openid 可选（内容安全 v2）；reset=1 清空本会话历史
     */
    public function streamChat()
    {
        // 关闭 PHP 缓冲，保证 SSE 能边生成边推送
        $this->prepareStreamEnv();

        $secretCheck = $this->assertSecretsReady();
        if ($secretCheck['ok'] !== true) {
            $this->sseEvent(array('code' => $secretCheck['code'], 'msg' => $secretCheck['msg']));
            exit;
        }

        if (!$this->checkRateLimit()) {
            $this->sseEvent(array('code' => 429, 'msg' => '请求过于频繁，请稍后再试'));
            exit;
        }

        $message = $this->getInputMessage();
        $openid = trim((string)input('openid', ''));
        $reset = (int)input('reset', 0);

        if ($reset === 1) {
            Session::set($this->historyKey, array());
        }

        if ($message === '') {
            $this->sseEvent(array('code' => 400, 'msg' => '请输入内容'));
            exit;
        }

        // 用户输入内容安全校验
        $userCheck = $this->checkMsgSec($message, $openid);
        if ($userCheck !== true) {
            $this->sseEvent(array('code' => 4001, 'msg' => $userCheck));
            exit;
        }

        $history = $this->getHistory();
        $messages = $this->buildMessages($history, $message);

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        // 跨域：小程序业务域名内一般不需要，本地调试可放开
        header('Access-Control-Allow-Origin: *');

        $fullReply = '';
        $result = $this->callArkStream($messages, function ($delta) use (&$fullReply) {
            if ($delta === '') {
                return;
            }
            $fullReply .= $delta;
            $this->sseEvent(array(
                'code'  => 0,
                'delta' => $delta,
            ));
        });

        if ($result['ok'] !== true) {
            $this->sseEvent(array(
                'code' => $result['code'],
                'msg'  => $result['msg'],
            ));
            exit;
        }

        $fullReply = trim($fullReply);
        if ($fullReply === '') {
            $this->sseEvent(array('code' => 5000, 'msg' => 'AI 未返回有效内容'));
            exit;
        }

        // AI 输出内容安全校验（整段）
        $aiCheck = $this->checkMsgSec($fullReply, $openid);
        if ($aiCheck !== true) {
            $this->sseEvent(array(
                'code'    => 4001,
                'msg'     => $aiCheck,
                'retract' => 1, // 前端可据此撤回本轮 AI 气泡
            ));
            exit;
        }

        // 通过安全校验后再写入本会话历史
        $history[] = array('role' => 'user', 'content' => $message);
        $history[] = array('role' => 'assistant', 'content' => $fullReply);
        // 控制上下文长度，避免 token 膨胀（保留最近 20 条消息）
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }
        Session::set($this->historyKey, $history);

        $this->sseEvent(array(
            'code'    => 0,
            'done'    => true,
            'content' => $fullReply,
        ));
        exit;
    }

    /**
     * 普通一次性对话（调试）
     * POST: message；openid 可选；reset=1 清空历史
     */
    public function chat()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');

        $secretCheck = $this->assertSecretsReady();
        if ($secretCheck['ok'] !== true) {
            $this->jsonBiz($secretCheck['code'], $secretCheck['msg']);
        }

        if (!$this->checkRateLimit()) {
            $this->jsonBiz(429, '请求过于频繁，请稍后再试');
        }

        $message = $this->getInputMessage();
        $openid = trim((string)input('openid', ''));
        $reset = (int)input('reset', 0);

        if ($reset === 1) {
            Session::set($this->historyKey, array());
        }

        if ($message === '') {
            $this->jsonBiz(400, '请输入内容');
        }

        $userCheck = $this->checkMsgSec($message, $openid);
        if ($userCheck !== true) {
            $this->jsonBiz(4001, $userCheck);
        }

        $history = $this->getHistory();
        $messages = $this->buildMessages($history, $message);

        $result = $this->callArkOnce($messages);
        if ($result['ok'] !== true) {
            $this->jsonBiz($result['code'], $result['msg']);
        }

        $reply = trim($result['content']);
        if ($reply === '') {
            $this->jsonBiz(5000, 'AI 未返回有效内容');
        }

        $aiCheck = $this->checkMsgSec($reply, $openid);
        if ($aiCheck !== true) {
            $this->jsonBiz(4001, $aiCheck);
        }

        $history[] = array('role' => 'user', 'content' => $message);
        $history[] = array('role' => 'assistant', 'content' => $reply);
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }
        Session::set($this->historyKey, $history);

        $this->jsonBiz(0, 'success', array(
            'content' => $reply,
        ));
    }

    // ===================== 输入与会话 =====================

    /**
     * 读取用户消息（兼容 form / json body）
     */
    private function getInputMessage()
    {
        $message = trim((string)input('message', ''));
        if ($message !== '') {
            return $message;
        }
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json) && isset($json['message'])) {
                return trim((string)$json['message']);
            }
        }
        return '';
    }

    /**
     * 读取本会话历史
     */
    private function getHistory()
    {
        $history = Session::get($this->historyKey);
        return is_array($history) ? $history : array();
    }

    /**
     * 组装发给方舟的 messages（system + 历史 + 本轮用户）
     */
    private function buildMessages(array $history, $userMessage)
    {
        $messages = array(
            array('role' => 'system', 'content' => $this->systemPrompt),
        );
        foreach ($history as $item) {
            if (empty($item['role']) || !isset($item['content'])) {
                continue;
            }
            if ($item['role'] !== 'user' && $item['role'] !== 'assistant') {
                continue;
            }
            $messages[] = array(
                'role'    => $item['role'],
                'content' => (string)$item['content'],
            );
        }
        $messages[] = array('role' => 'user', 'content' => $userMessage);
        return $messages;
    }

    // ===================== IP 限流 =====================

    /**
     * 同一 IP 10 秒内最多 2 次请求
     * @return bool true=通过 false=超限
     */
    private function checkRateLimit()
    {
        $ip = request()->ip();
        $key = 'ai_rate_' . md5($ip);
        $now = time();
        $list = Cache::get($key);
        if (!is_array($list)) {
            $list = array();
        }
        // 去掉 10 秒窗口外的记录
        $fresh = array();
        foreach ($list as $ts) {
            if (($now - (int)$ts) < 10) {
                $fresh[] = (int)$ts;
            }
        }
        if (count($fresh) >= 2) {
            return false;
        }
        $fresh[] = $now;
        Cache::set($key, $fresh, 10);
        return true;
    }

    // ===================== 微信内容安全 =====================

    /**
     * 文本内容安全检测
     * @param string $content
     * @param string $openid 有 openid 时走 msg_sec_check v2，更符合审核要求
     * @return true|string true=通过，字符串=拦截提示
     */
    private function checkMsgSec($content, $openid = '')
    {
        $content = trim((string)$content);
        if ($content === '') {
            return '内容不能为空';
        }
        // 超长截断检测（微信接口有长度限制）
        if (function_exists('mb_substr')) {
            $checkText = mb_substr($content, 0, 2500, 'UTF-8');
        } else {
            $checkText = substr($content, 0, 2500);
        }

        $token = $this->getWxAccessToken();
        if ($token === '') {
            // 拿不到 token 时：为满足审核，保守拦截并给业务提示（不暴露密钥问题细节）
            return '内容安全服务暂不可用，请稍后再试';
        }

        $url = 'https://api.weixin.qq.com/wxa/msg_sec_check?access_token=' . urlencode($token);
        if ($openid !== '') {
            $payload = array(
                'version' => 2,
                'openid'  => $openid,
                'scene'   => 2, // 2=论坛场景，适合对话
                'content' => $checkText,
            );
        } else {
            // 兼容无 openid 的调试：使用旧版字段（仅 content）
            $payload = array(
                'content' => $checkText,
            );
        }

        $resp = $this->httpPostJson($url, $payload, 8);
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->aiLog('msg_sec_check invalid_json resp_len=' . strlen((string)$resp));
            return '内容安全校验失败，请稍后再试';
        }

        $errcode = isset($json['errcode']) ? (int)$json['errcode'] : -1;
        // 0 通过；87014 内容含违规
        if ($errcode === 0) {
            // v2 还可能带 result.suggest = risky/review/pass
            if (isset($json['result']['suggest'])) {
                $suggest = $json['result']['suggest'];
                if ($suggest === 'risky') {
                    return '内容含有违规信息，请修改后重试';
                }
            }
            return true;
        }
        if ($errcode === 87014) {
            return '内容含有违规信息，请修改后重试';
        }
        $errmsg = isset($json['errmsg']) ? $json['errmsg'] : '';
        $this->aiLog('msg_sec_check fail errcode=' . $errcode . ' errmsg=' . $errmsg
            . ' has_openid=' . ($openid !== '' ? '1' : '0'));

        // access_token 失效则清缓存并提示重试（不回传微信原始错误）
        if ($errcode === 40001 || $errcode === 42001) {
            Cache::rm('wx_access_token_ai');
            return '内容安全服务暂不可用，请稍后再试';
        }
        // openid 无效时降级重试旧版接口（无 openid）
        if ($openid !== '' && ($errcode === 40003 || $errcode === 61010 || $errcode === 43104)) {
            return $this->checkMsgSec($content, '');
        }
        return '内容安全校验失败，请稍后再试';
    }

    /**
     * 获取并缓存微信 access_token（密钥不写日志、不输出前端）
     */
    private function getWxAccessToken()
    {
        if ($this->wxAppId === '' || $this->wxAppSecret === '') {
            $this->aiLog('getWxAccessToken: WX_APPID/WX_SECRET empty');
            return '';
        }
        $cacheKey = 'wx_access_token_ai';
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential'
            . '&appid=' . urlencode($this->wxAppId)
            . '&secret=' . urlencode($this->wxAppSecret);
        $resp = $this->httpGet($url, 8);
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json['access_token'])) {
            $errcode = is_array($json) && isset($json['errcode']) ? $json['errcode'] : 'na';
            $errmsg = is_array($json) && isset($json['errmsg']) ? $json['errmsg'] : 'empty_or_invalid_json';
            // 只记错误码/文案，不记 secret
            $this->aiLog('getWxAccessToken fail errcode=' . $errcode . ' errmsg=' . $errmsg
                . ' appid_suffix=' . substr($this->wxAppId, -6)
                . ' resp_len=' . strlen((string)$resp));
            return '';
        }
        $token = $json['access_token'];
        $expires = isset($json['expires_in']) ? (int)$json['expires_in'] : 7200;
        // 提前 5 分钟过期，避免临界失效
        Cache::set($cacheKey, $token, max(60, $expires - 300));
        return $token;
    }

    // ===================== 火山方舟调用 =====================

    /**
     * 流式调用方舟，通过回调推送 delta 文本
     * @param array $messages
     * @param callable $onDelta function(string $delta)
     * @return array{ok:bool,code:int,msg:string}
     */
    private function callArkStream(array $messages, $onDelta)
    {
        $body = json_encode(array(
            'model'    => $this->arkModelId,
            'messages' => $messages,
            'stream'   => true,
        ), JSON_UNESCAPED_UNICODE);

        $buffer = '';
        $httpCode = 0;
        $curlErrno = 0;
        $authFailed = false;
        $upstreamErr = '';

        $ch = curl_init($this->arkApiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->arkApiKey,
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buffer, &$authFailed, &$upstreamErr, $onDelta) {
            $len = strlen($data);
            $buffer .= $data;

            // 非 SSE 的错误 JSON 整包缓冲后解析
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') {
                    continue;
                }
                // 兼容 data:xxx / data: xxx
                if (stripos($line, 'data:') === 0) {
                    $payload = trim(substr($line, 5));
                    if ($payload === '[DONE]') {
                        continue;
                    }
                    $json = json_decode($payload, true);
                    if (!is_array($json)) {
                        continue;
                    }
                    // 方舟错误结构
                    if (isset($json['error'])) {
                        $msg = isset($json['error']['message']) ? (string)$json['error']['message'] : '';
                        $type = isset($json['error']['type']) ? (string)$json['error']['type'] : '';
                        if (stripos($msg, 'auth') !== false || stripos($type, 'auth') !== false
                            || stripos($msg, 'api key') !== false || stripos($msg, 'Unauthorized') !== false) {
                            $authFailed = true;
                        } else {
                            $upstreamErr = '模型服务异常，请稍后再试';
                        }
                        continue;
                    }
                    $delta = '';
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $delta = (string)$json['choices'][0]['delta']['content'];
                    } elseif (isset($json['choices'][0]['message']['content'])) {
                        $delta = (string)$json['choices'][0]['message']['content'];
                    }
                    if ($delta !== '' && is_callable($onDelta)) {
                        call_user_func($onDelta, $delta);
                    }
                } else {
                    // 可能是非流式错误 JSON 行
                    $json = json_decode($line, true);
                    if (is_array($json) && isset($json['error'])) {
                        $msg = isset($json['error']['message']) ? (string)$json['error']['message'] : '';
                        if (stripos($msg, 'api key') !== false || stripos($msg, 'Unauthorized') !== false
                            || stripos($msg, 'auth') !== false) {
                            $authFailed = true;
                        } else {
                            $upstreamErr = '模型服务异常，请稍后再试';
                        }
                    }
                }
            }
            return $len;
        });

        $ok = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno === 28) {
            return array('ok' => false, 'code' => 4002, 'msg' => '请求超时，请稍后再试');
        }
        if ($ok === false && $curlErrno !== 0) {
            return array('ok' => false, 'code' => 5000, 'msg' => '模型服务异常，请稍后再试');
        }
        if ($authFailed || $httpCode === 401) {
            return array('ok' => false, 'code' => 401, 'msg' => '服务鉴权失败，请稍后再试');
        }
        if ($upstreamErr !== '') {
            return array('ok' => false, 'code' => 5000, 'msg' => $upstreamErr);
        }
        if ($httpCode >= 400) {
            return array('ok' => false, 'code' => 5000, 'msg' => '模型服务异常，请稍后再试');
        }
        return array('ok' => true, 'code' => 0, 'msg' => 'success');
    }

    /**
     * 非流式一次性调用
     * @return array{ok:bool,code:int,msg:string,content?:string}
     */
    private function callArkOnce(array $messages)
    {
        $body = json_encode(array(
            'model'    => $this->arkModelId,
            'messages' => $messages,
            'stream'   => false,
        ), JSON_UNESCAPED_UNICODE);

        $ch = curl_init($this->arkApiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->arkApiKey,
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno === 28) {
            return array('ok' => false, 'code' => 4002, 'msg' => '请求超时，请稍后再试');
        }
        if ($resp === false) {
            return array('ok' => false, 'code' => 5000, 'msg' => '模型服务异常，请稍后再试');
        }
        if ($httpCode === 401) {
            return array('ok' => false, 'code' => 401, 'msg' => '服务鉴权失败，请稍后再试');
        }

        $json = json_decode($resp, true);
        if (!is_array($json)) {
            return array('ok' => false, 'code' => 5000, 'msg' => '模型服务异常，请稍后再试');
        }
        if (isset($json['error'])) {
            $msg = isset($json['error']['message']) ? (string)$json['error']['message'] : '';
            if (stripos($msg, 'api key') !== false || stripos($msg, 'Unauthorized') !== false
                || stripos($msg, 'auth') !== false) {
                return array('ok' => false, 'code' => 401, 'msg' => '服务鉴权失败，请稍后再试');
            }
            return array('ok' => false, 'code' => 5000, 'msg' => '模型服务异常，请稍后再试');
        }
        $content = '';
        if (isset($json['choices'][0]['message']['content'])) {
            $content = (string)$json['choices'][0]['message']['content'];
        }
        return array('ok' => true, 'code' => 0, 'msg' => 'success', 'content' => $content);
    }

    // ===================== HTTP / 输出工具 =====================

    /**
     * 为 SSE 关闭各层缓冲（兼容 output_buffering / zlib）
     */
    private function prepareStreamEnv()
    {
        // 关闭 zlib 压缩，避免整包压缩导致无法流式
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'Off');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);
        // 忽略客户端断开后继续跑完安全校验与落库逻辑可选；流式场景允许中断
        ignore_user_abort(true);
        set_time_limit(0);
    }

    /**
     * 输出一条 SSE 事件并立刻刷新
     */
    private function sseEvent(array $data)
    {
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * JSON 业务响应并结束
     */
    private function jsonBiz($code, $msg, $data = null)
    {
        $out = array(
            'code' => (int)$code,
            'msg'  => (string)$msg,
        );
        if ($data !== null) {
            $out['data'] = $data;
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function httpGet($url, $timeout = 10)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp === false ? '' : $resp;
    }

    private function httpPostJson($url, array $data, $timeout = 10)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp === false ? '' : $resp;
    }
}
