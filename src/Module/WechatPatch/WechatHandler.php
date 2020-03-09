<?php /** @noinspection DuplicatedCode */

namespace Module\WechatPatch;

use Co;
use DOMDocument;
use Framework\Console;
use Framework\ZMBuf;
use ZM\Annotation\CQ\CQAfter;
use ZM\Annotation\CQ\CQBefore;
use ZM\Annotation\CQ\CQMessage;
use ZM\Annotation\Http\RequestMapping;
use ZM\Annotation\Module\SaveBuffer;
use ZM\Annotation\Swoole\SwooleEventAt;
use ZM\Event\EventHandler;
use ZM\ModBase;
use ZM\ModHandleType;

/**
 * Class WechatHandler
 * @package Module\WechatPatch
 * @SaveBuffer(buf_name="html_content",sub_folder="WechatHandler")
 */
class WechatHandler extends ModBase
{
    // 请将这个地址的IP修改为你的框架反向代理地址。
    // 例如你的框架端口部署到了 20001 端口，80 端口是 nginx 服务器。
    // 你需要将此地址设置为 http://你的IP/wechat/
    // 反向代理的地址应该设置为 location /wechat
    // 因为微信公众号只能设置开发者模式访问链接在 80 和 443 端口上。
    const WX_HTTP_ADDR = "http://127.0.0.1/wechat/";

    /**
     * 微信的 HTTP 请求触发接收
     * @SwooleEventAt(type="request",rule="containsGet:signature,timestamp,nonce")
     */
    public function onEvent() {
        if (!$this->checkSignature($this->request->get)) return $this->response->end("Invalid signature");
        if (isset($this->request->get["echostr"])) return $this->response->end($this->request->get["echostr"]);
        $xml_data = $this->request->rawContent();
        $xml_tree = new DOMDocument('1.0', 'utf-8');
        $xml_tree->loadXML($xml_data);
        $msg_type = $xml_tree->getElementsByTagName("MsgType")->item(0)->nodeValue;
        $content = '';
        $self_if = $xml_tree->getElementsByTagName("ToUserName")->item(0)->nodeValue;
        switch ($msg_type) {
            case "text":
                $content = $xml_tree->getElementsByTagName("Content")->item(0)->nodeValue;
                break;
            case "image":
                $content = $xml_tree->getElementsByTagName("MediaId")->item(0)->nodeValue;
                break;
            case "event":
                $content = $xml_tree->getElementsByTagName("Event")->item(0)->nodeValue;
                break;
            case "voice":
                $msg_type = "text";
                $content = preg_replace("/[，。]/", "", $xml_tree->getElementsByTagName("Recognition")->item(0)->nodeValue);
                break;
        }
        $data = [
            "zm_req_type" => "wechat",
            "timestamp" => time(),
            "type" => $msg_type,
            "user_id" => $xml_tree->getElementsByTagName("FromUserName")->item(0)->nodeValue,
            "content" => $content,
            "self_id" => $self_if
        ];
        $this->setBlock();
        ZMBuf::$atomics["in_count"]->add(1);
        if (!isset($data["content"]) || !isset($data["user_id"])) {
            $this->response->end("Unable to execute your request. Please check your parameter or contact admin.");
            return null;
        }

        if($data["type"] == "event") {
            Console::info("关注公众号事件：".$data["user_id"]);
            $hello_msg = "here to write your subscription event message";
            $this->wx_reply[]= $hello_msg;
            $this->data = $data;
            $this->response->end($this->formatWXReply());
        } else {
            Console::info("公众号消息：".$data["content"]);
            $req_package["post_type"] = "message";
            $req_package["message_type"] = "wechat";
            $req_package["message"] = $data["content"];
            $req_package["user_id"] = $data["user_id"];
            $req_package["self_id"] = $data["self_id"];
            $req_package["time"] = time();
            $this->data = $req_package;
            EventHandler::callCQEvent($this->data, $this->response, 0);
        }
        return null;
    }

    /**
     * @param $param
     * @RequestMapping("/wechat/{page_id}")
     */
    public function newsPage($param){
        if(isset(ZMBuf::get("html_content", [])[$param["page_id"]])) {
            $this->response->end(ZMBuf::get("html_content")[$param["page_id"]]);
        } else {
            $this->response->status(404);
            $this->response->end("Content not found.");
        }
    }

    private function checkSignature($get) {
        $signature = $get["signature"] ?? "";
        $timestamp = $get["timestamp"] ?? "";
        $nonce = $get["nonce"] ?? "";
        $tmp_arr = array("abcde", $timestamp, $nonce);
        sort($tmp_arr, SORT_STRING);
        $tmp_str = implode($tmp_arr);
        $tmp_str = sha1($tmp_str);
        return $signature == $tmp_str ? true : false;
    }

    /**
     * @CQBefore("message")
     * @return bool
     */
    public function onBefore() {
        foreach (ZMBuf::get("wait_api", []) as $k => $v) {
            if($this->data["user_id"] == $v["user_id"] &&
                $this->data["self_id"] == $v["self_id"] &&
                $this->data["message_type"] == $v["message_type"] &&
                ($this->data[$this->data["message_type"]."_id"] ?? $this->data["user_id"]) ==
                ($v[$v["message_type"]."_id"] ?? $v["user_id"])){
                $v["result"] = $this->data["message"];
                if($this->data["message_type"] == "wechat") $v["wx_response"] = $this->connection;
                ZMBuf::appendKey("wait_api", $k, $v);
                $this->setBlock();
                Co::resume($v["coroutine"]);
                $this->wx_response = null;
                return false;
            }
        }
        foreach (ZMBuf::$events[CQBefore::class][CQMessage::class] ?? [] as $v) {
            $c = $v->class;
            if($v->class != WechatHandler::class) {
                $class = new $c([
                    "data" => $this->data,
                    "connection" => $this->response
                ], ModHandleType::CQ_MESSAGE);
                $r = call_user_func_array([$class, $v->method], []);
                if (!$r || $class->block_continue) return false;
            }
        }

        return true;
    }

    /**
     * @CQAfter("message")
     */
    public function onAfter(){
        if($this->getMessageType() == "wechat" && !$this->connection->isEnd()) {
            $this->connection->end("success");
        }
    }
}
