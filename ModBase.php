<?php


namespace ZM;


use Co;
use Framework\Console;
use Framework\ZMBuf;
use Module\WechatPatch\WechatHandler;
use Swoole\Http\Request;
use ZM\API\CQAPI;
use ZM\Connection\WSConnection;
use ZM\Exception\InvalidArgumentException;
use ZM\Exception\WaitTimeoutException;
use ZM\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use ZM\Utils\ZMUtil;

abstract class ModBase
{
    /** @var Server */
    protected $server;
    /** @var Frame */
    protected $frame;
    /** @var array */
    protected $data;
    /** @var Request */
    protected $request;
    /** @var Response */
    protected $response;
    /** @var int */
    protected $fd;
    /** @var int */
    protected $worker_id;
    /** @var WSConnection|Response */
    protected $connection;

    protected $handle_type = ModHandleType::CQ_MESSAGE;

    public $block_continue = false;
    /**
     * @var array
     */
    public $wx_reply = [];

    /** @var Response */
    public $wx_response = null;

    public function __construct($param0 = [], $handle_type = 0) {
        if (isset($param0["server"])) $this->server = $param0["server"];
        if (isset($param0["frame"])) $this->frame = $param0["frame"];
        if (isset($param0["data"])) $this->data = $param0["data"];
        if (isset($param0["request"])) $this->request = $param0["request"];
        if (isset($param0["response"])) $this->response = $param0["response"];
        if (isset($param0["fd"])) $this->fd = $param0["fd"];
        if (isset($param0["worker_id"])) $this->worker_id = $param0["worker_id"];
        if (isset($param0["connection"])) {
            if ($param0["connection"] instanceof Response)
                $this->wx_response = $param0["connection"];
            $this->connection = $param0["connection"];
        }
        $this->handle_type = $handle_type;
    }

    /**
     * only can used by cq->message event function
     * @param $msg
     * @param bool $yield
     * @return mixed
     */
    public function reply($msg, $yield = false) {
        switch ($this->data["message_type"]) {
            case "group":
            case "private":
            case "discuss":
                return CQAPI::quick_reply($this->connection, $this->data, $msg, $yield);
            case "wechat":
                $this->wx_reply [] = $msg;
                //Console::warning("微信回复添加了" . $msg);
                return true;
        }
        return false;
    }

    public function finalReply($msg, $yield = false) {
        $this->block_continue = true;
        if ($msg == "") return true;
        return $this->reply($msg, $yield);
    }

    /**
     * @param string $prompt
     * @param int $timeout
     * @param string $timeout_prompt
     * @return string
     * @throws InvalidArgumentException
     * @throws WaitTimeoutException
     */
    public function waitMessage($prompt = "", $timeout = 600, $timeout_prompt = "") {
        if ($prompt != "") $this->reply($prompt);
        if (!isset($this->data["user_id"], $this->data["message"], $this->data["self_id"]))
            throw new InvalidArgumentException("协程等待参数缺失");
        if (($this->data["message_type"] ?? null) == "wechat") {
            Console::warning("当前fd：" . $this->connection->fd);
            $this->processWXResponse();
            $this->wx_response = null;
            $this->wx_reply = [];
        }
        $cid = Co::getuid();
        $api_id = ZMBuf::$atomics["wait_msg_id"]->get();
        ZMBuf::$atomics["wait_msg_id"]->add(1);
        $hang = [
            "coroutine" => $cid,
            "user_id" => $this->data["user_id"],
            "message" => $this->data["message"],
            "self_id" => $this->data["self_id"],
            "message_type" => $this->data["message_type"],
            "result" => null
        ];
        if ($hang["message_type"] == "group" || $hang["message_type"] == "discuss") {
            $hang[$hang["message_type"] . "_id"] = $this->data[$this->data["message_type"] . "_id"];
        }
        ZMBuf::appendKey("wait_api", $api_id, $hang);
        $id = swoole_timer_after($timeout * 1000, function () use ($api_id, $timeout_prompt) {
            $r = ZMBuf::get("wait_api")[$api_id] ?? null;
            if ($r !== null) {
                Co::resume($r["coroutine"]);
            }
        });
        //Console::info("从这里挂起～");
        Co::suspend();
        //Console::info("俺恢复了！");
        $sess = ZMBuf::get("wait_api")[$api_id];
        if ($sess["message_type"] == "wechat") {
            $this->connection = $sess["wx_response"];
            $this->wx_response = $this->connection;
            //Console::warning("替换后的fd：".$this->connection->fd."，回复的消息：".$sess["result"]);
        }
        ZMBuf::unsetByValue("wait_api", $api_id);
        $result = $sess["result"];
        if (isset($id)) swoole_timer_clear($id);
        if ($result === null) throw new WaitTimeoutException($this, $timeout_prompt);
        return $result;
    }

    /**
     * @param $arg
     * @param $mode
     * @param $prompt_msg
     * @return mixed|string
     * @throws InvalidArgumentException
     * @throws WaitTimeoutException
     */
    public function getArgs(&$arg, $mode, $prompt_msg) {
        switch ($mode) {
            case ZM_MATCH_ALL:
                $p = $arg;
                array_shift($p);
                return trim(implode(" ", $p)) == "" ? $this->waitMessage($prompt_msg) : trim(implode(" ", $p));
            case ZM_MATCH_NUMBER:
                foreach ($arg as $k => $v) {
                    if (is_numeric($v)) {
                        array_splice($arg, $k, 1);
                        return $v;
                    }
                }
                return $this->waitMessage($prompt_msg);
            case ZM_MATCH_FIRST:
                if (isset($arg[1])) {
                    $a = $arg[1];
                    array_splice($arg, 1, 1);
                    return $a;
                } else {
                    return $this->waitMessage($prompt_msg);
                }
        }
        throw new InvalidArgumentException();
    }

    public function getMessage() {
        return $this->data["message"] ?? null;
    }

    public function getUserId() {
        return $this->data["user_id"] ?? null;
    }

    public function getGroupId() {
        return $this->data["group_id"] ?? null;
    }

    public function getMessageType() {
        return $this->data["message_type"] ?? null;
    }

    public function getRobotId() {
        return $this->data["self_id"];
    }

    public function getConnection() {
        return $this->connection;
    }

    public function setBlock($result = true) {
        $this->block_continue = $result;
    }

    public function formatWXReply() {
        if ($this->wx_reply == []) return "";
        else {
            $ls = [];
            $have_image = false;
            foreach ($this->wx_reply as $v) {
                while (($cq = ZMUtil::getCQ($v)) !== null) {
                    if ($cq["type"] == "image" && (substr($cq["params"]["file"], 0, 7) == "http://" || substr($cq["params"]["file"], 0, 8) == "https://")) {
                        $v = str_replace(mb_substr($v, $cq["start"], $cq["end"] - $cq["start"] + 1), "<img src='" . $cq["params"]["file"] . "'>", $v);
                        $have_image = true;
                    } else {
                        $v = str_replace(mb_substr($v, $cq["start"], $cq["end"] - $cq["start"] + 1), "  ", $v);
                    }
                }
                $ls [] = $v;
            }
            if ($have_image === true) {
                $msgs = implode("\n\n", $ls);
                $key = md5($msgs);
                ZMBuf::appendKey("html_content", $key, "<html lang=\"en\"><head><meta charset='utf-8'><title></title></head><body><pre>" . $msgs . "</pre></body></html>");
                return $this->replyWechatNews("点击查看", "多媒体消息", "", WechatHandler::WX_HTTP_ADDR . $key);
            }
            $msg = implode("\n\n", $ls);
            $user_id = "<![CDATA[" . $this->data["user_id"] . "]]>";
            $from = "<![CDATA[".$this->data["self_id"]."]]>";
            $type = "<![CDATA[text]]>";
            $content = "<![CDATA[" . $msg . "]]>";
            $this->wx_reply = [];
            /** @noinspection HtmlDeprecatedTag */
            return "\n<xml><ToUserName>$user_id</ToUserName><FromUserName>$from</FromUserName><CreateTime>" . time() . "</CreateTime><MsgType>$type</MsgType><Content>$content</Content></xml>";
        }
    }

    /**
     * 用图文信息方式回复微信消息
     * @param $title
     * @param $description
     * @param $pic_url
     * @param $url
     * @return bool
     */
    public function replyWechatNews($title, $description, $pic_url, $url) {
        //$this->setFunctionCalled();
        $user_id = "<![CDATA[" . $this->data["user_id"] . "]]>";
        $from = "<![CDATA[" . $this->data["self_id"] . "]]>";
        $type = "<![CDATA[news]]>";
        //$content = "<![CDATA[" . $media_id . "]]>";
        $title = "<![CDATA[" . $title . "]]>";
        $description = "<![CDATA[" . $description . "]]>";
        $url = "<![CDATA[" . $url . "]]>";
        $pic_url = "<![CDATA[" . $pic_url . "]]>";
        return "\n<xml><ToUserName>$user_id</ToUserName><FromUserName>$from</FromUserName><CreateTime>" . time() . "</CreateTime><MsgType>$type</MsgType><ArticleCount>1</ArticleCount><Articles><item><Title>$title</Title><Description>$description</Description><PicUrl>$pic_url</PicUrl><Url>$url</Url></item></Articles></xml>";
    }

    public function processWXResponse($raw = "") {
        if ($this->wx_response === null) return;
        //else Console::info("还行，没丢，这个的fd是" . $this->wx_response->fd);
        if ($raw == "") {
            if (($reply = $this->formatWXReply()) !== "") {
                $this->wx_response->end($reply);
                ZMBuf::$atomics["out_count"]->add(1);
            } else {
                $this->wx_response->end("success");
            }
        } else {
            $this->wx_response->end($raw);
            ZMBuf::$atomics["out_count"]->add(1);
        }
    }

    public function __destruct() {
        if ($this->wx_reply != []) {
            $this->processWXResponse();
        }
    }
}
