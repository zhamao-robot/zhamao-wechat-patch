<?php


namespace Module\WechatPatch;


use Framework\ZMBuf;
use ZM\Annotation\CQ\CQBefore;
use ZM\Annotation\CQ\CQCommand;
use ZM\API\CQ;
use ZM\Exception\InvalidArgumentException;
use ZM\Exception\WaitTimeoutException;
use ZM\ModBase;

class WXTest extends ModBase
{
    /**
     * @CQCommand("wx_test")
     */
    public function onCommand(){
        $asd = [];
        foreach(ZMBuf::$events[CQBefore::class] as $k => $v) {
            $asd []= $k;
        }
        return implode("\n", $asd);
    }

    /**
     * @CQCommand("图片测试")
     */
    public function pict(){
        return CQ::image("https://zhamao.xin/file/hello.jpg")."\n这是一张示例图片，微信和QQ都能显示哦！";
    }

    /**
     * @CQCommand("wx_test_coroutine")
     * @throws InvalidArgumentException
     * @throws WaitTimeoutException
     */
    public function onCommand2(){
        $name = $this->waitMessage("请告诉我你的名字", 900);
        $this->reply("好的，我知道了，你叫 ".$name."，以后我就这么称呼你啦！");
    }
}
