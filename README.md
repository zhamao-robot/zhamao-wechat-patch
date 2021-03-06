# zhamao-wechat-patch
[炸毛框架](https://github.com/zhamao-robot/zhamao-framework) 的微信公众号兼容层模块

## 介绍
这个模块是一个特殊的炸毛框架模块，用于让框架兼容 **微信公众号开发者平台** 的被动消息回复。

目前仅简单支持被动消息回复，关于多媒体消息（如图片、图文混合、链接等），未微信认证的公众号也可以以多媒体消息格式发送。

## 特点
- 在安装兼容层后，所有微信公众号的消息都可以适应 `@CQMessage`，`@CQCommand` 等绑定事件，无需二次编写逻辑代码
- 使用 `waitMessage()` 或 `getArgs()` 进行多文本获取也是没问题的
- 支持 HTML 富文本返回

## 安装
1. 将 `src/Module/WechatPatch` 目录拷贝到 `zhamao-framework` 框架下的 `src/Module/` 目录下

2. 将 `ModBase.php` 覆盖到 `src/ZM/ModBase.php`，因为兼容层需要加入 `wechat` 类型事件的处理，需要重写部分模块函数。

## 微信公众号端设置
1. 登录公众号，打开开发者模式

2. 将框架所在的服务器 IP 地址加入白名单

3. 请将模块内 `WechatHandler` 中的 `WX_HTTP_ADDR` 的地址修改为你的框架反向代理地址。

> 例如你的框架端口部署到了 20001 端口，80 端口是 nginx 服务器。
> 你需要将此地址设置为 http://你的IP/wechat/ 。
> 反向代理的地址应该设置为 location /wechat
> 因为微信公众号只能设置开发者模式访问链接在 80 和 443 端口上。

4. 将微信公众号的服务器地址设置为你上面设置的反向代理地址

5. 选择明文模式或兼容模式
