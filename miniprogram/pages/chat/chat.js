/**
 * AI 流式对话页
 * 基础库建议 >= 2.21.0，使用 enableChunked + onChunkReceived 接收 SSE
 * 所有密钥仅在后端，前端绝不出现密钥
 */
const BASE_URL = 'https://haiwaiting.cn'

Page({
  data: {
    messages: [],
    inputText: '',
    sending: false,
    scrollInto: '',
    openid: ''
  },

  // 粘包缓冲
  _sseBuffer: '',

  onLoad() {
    // 若本地存过 openid（内容安全 v2 需要），可在此读取
    try {
      const user = wx.getStorageSync('mole_user') || {}
      if (user.openid) {
        this.setData({ openid: user.openid })
      }
    } catch (e) {}
  },

  onInput(e) {
    this.setData({ inputText: e.detail.value })
  },

  scrollBottom() {
    this.setData({ scrollInto: '' })
    setTimeout(() => {
      this.setData({ scrollInto: 'msg-bottom' })
    }, 30)
  },

  onSend() {
    const text = (this.data.inputText || '').trim()
    if (!text || this.data.sending) return

    const messages = this.data.messages.concat([
      { role: 'user', content: text },
      { role: 'assistant', content: '' }
    ])
    this.setData({
      inputText: '',
      messages,
      sending: true
    })
    this._sseBuffer = ''
    this.scrollBottom()
    this.startStream(text, messages.length - 1)
  },

  /**
   * 流式请求：responseType 配合 enableChunked 分片回调
   */
  startStream(text, aiIndex) {
    const that = this
    const task = wx.request({
      url: BASE_URL + '/index/ai/streamChat',
      method: 'POST',
      enableChunked: true,
      responseType: 'arraybuffer',
      header: {
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'text/event-stream'
      },
      data: {
        message: text,
        openid: that.data.openid || ''
      },
      success() {
        that.setData({ sending: false })
        that.scrollBottom()
      },
      fail() {
        that.patchAi(aiIndex, '网络异常，请稍后重试')
        that.setData({ sending: false })
      }
    })

    if (task && typeof task.onChunkReceived === 'function') {
      task.onChunkReceived((res) => {
        const chunk = that.decodeChunk(res.data)
        if (chunk) {
          that.consumeSse(chunk, aiIndex)
        }
      })
    } else {
      // 基础库过低不支持分片时，降级普通接口
      that.fallbackOnce(text, aiIndex)
    }
  },

  decodeChunk(data) {
    if (!data) return ''
    if (typeof data === 'string') return data
    try {
      const arr = data instanceof ArrayBuffer ? new Uint8Array(data) : new Uint8Array(data)
      if (typeof TextDecoder !== 'undefined') {
        return new TextDecoder('utf-8').decode(arr)
      }
      let str = ''
      for (let i = 0; i < arr.length; i++) {
        str += String.fromCharCode(arr[i])
      }
      try {
        return decodeURIComponent(escape(str))
      } catch (e) {
        return str
      }
    } catch (e) {
      return ''
    }
  },

  consumeSse(chunk, aiIndex) {
    this._sseBuffer += chunk
    const parts = this._sseBuffer.split('\n')
    this._sseBuffer = parts.pop() || ''
    parts.forEach((line) => {
      const trimmed = (line || '').trim()
      if (!trimmed || trimmed.indexOf('data:') !== 0) return
      const payload = trimmed.slice(5).trim()
      if (!payload || payload === '[DONE]') return
      let json
      try {
        json = JSON.parse(payload)
      } catch (e) {
        return
      }
      this.handleEvent(json, aiIndex)
    })
  },

  handleEvent(json, aiIndex) {
    if (!json) return
    if (json.code === 0) {
      if (json.delta) {
        const key = 'messages[' + aiIndex + '].content'
        const cur = (this.data.messages[aiIndex] && this.data.messages[aiIndex].content) || ''
        this.setData({ [key]: cur + json.delta })
        this.scrollBottom()
      }
      if (json.done) {
        this.setData({ sending: false })
        this.scrollBottom()
      }
      return
    }
    const tip = json.msg || '请求失败'
    if (json.retract) {
      this.patchAi(aiIndex, tip)
    } else {
      const cur = this.data.messages[aiIndex]
      if (cur && !cur.content) {
        this.patchAi(aiIndex, tip)
      } else {
        wx.showToast({ icon: 'none', title: tip })
      }
    }
    this.setData({ sending: false })
  },

  fallbackOnce(text, aiIndex) {
    const that = this
    wx.request({
      url: BASE_URL + '/index/ai/chat',
      method: 'POST',
      header: { 'Content-Type': 'application/x-www-form-urlencoded' },
      data: { message: text, openid: that.data.openid || '' },
      success(res) {
        const body = res.data || {}
        if (body.code === 0 && body.data && body.data.content) {
          that.patchAi(aiIndex, body.data.content)
        } else {
          that.patchAi(aiIndex, body.msg || '请求失败')
        }
        that.setData({ sending: false })
      },
      fail() {
        that.patchAi(aiIndex, '网络异常，请稍后重试')
        that.setData({ sending: false })
      }
    })
  },

  patchAi(aiIndex, text) {
    this.setData({
      ['messages[' + aiIndex + '].content']: text
    })
    this.scrollBottom()
  }
})
