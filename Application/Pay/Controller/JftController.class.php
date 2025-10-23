<?php
/**
 * Created by PhpStorm.
 * User: gaoxi
 * Date: 2017-05-18
 * Time: 11:33
 */
namespace Pay\Controller;
use MoneyCheck;
require_once("redis_util.class.php");
require_once("authorize.class.php");
class JftController extends PayController
{
    public function __construct()
    {
        parent::__construct();

    }

    public function index()
    {
        $response = $_GET;
        $this->writeLog('index------'.$response['money']);
        $wrapperUrl = 'http://alipay.020leader.com/index.php?g=Wap&m=CashierPayfreeApi&a=pay&merchant_num=000000000300808&money='.$response['money'];
        $encodedUrl = urlencode($wrapperUrl);

        $alipayScheme = "alipays://platformapi/startapp?appId=20000067&url=" . $encodedUrl;

        $this->assign('alipayScheme', $alipayScheme);
        $this->display("WeiXin/jft");
    }


}
