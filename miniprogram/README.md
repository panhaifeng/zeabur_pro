# AI 对话小程序页面（原生）

将本目录 `pages/chat` 复制到你的微信原生小程序工程，并在 `app.json` 的 `pages` 中注册：

```json
"pages/chat/chat"
```

后端接口（ThinkPHP5.1）：

- `POST https://haiwaiting.cn/index/ai/streamChat` 流式 SSE
- `POST https://haiwaiting.cn/index/ai/chat` 普通一次性返回

微信公众平台请配置 request 合法域名：`https://haiwaiting.cn`

基础库建议 `>= 2.21.0`。
