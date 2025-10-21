<?php
namespace Admin\Controller;

class CurrencyController extends BaseController {

    // 货币列表
    public function index() {
        $list = M('Currency')
            ->order('sort DESC, id ASC')
            ->select();

        $this->assign('list', $list);
        $this->display();
    }

    public function addCurrency()
    {
        if(IS_POST){
            $params = array(
                'code' => I('code','','trim'),
                'name'=>I('name','','trim'),
                'symbol'=>I('symbol','','trim'),
                'sort'=>I('sort','','trim'),
                'status' => 1,
            );
            if(!$params['code']){
                $this->ajaxReturn(['status'=>0,'msg'=>'请输入货币代码!']);
            }
            if(!$params['name']){
                $this->ajaxReturn(['status'=>0,'msg'=>'请输入货币名称!']);
            }
            if(!$params['symbol']){
                $this->ajaxReturn(['status'=>0,'msg'=>'请输入货币符号!']);
            }
            $CurrencyModel = M('Currency');


            $add_result = $CurrencyModel->add($params);
            $this->ajaxReturn(['status'=>$add_result]);
        }else{
            $this->display();
        }

    }


    public function editCurrency($id)
    {
        if(IS_POST){
            $params = array(
                'id' => I('id','','intval'),
                'code' => I('code','','trim'),
                'name'=>I('name','','trim'),
                'symbol'=>I('symbol','','trim'),
                'sort'=>I('sort','','trim'),
                'status' => 1,
            );
            if(!$params['code']){
                $this->ajaxReturn(['status'=>0,'msg'=>'请输入货币代码!']);
            }
            if(!$params['name']){
                $this->ajaxReturn(['status'=>0,'msg'=>'请输入货币名称!']);
            }
            if(!$params['symbol']){
                $this->ajaxReturn(['status'=>0,'msg'=>'请输入货币符号!']);
            }
            $CurrencyModel = M('Currency');

            $add_result = $CurrencyModel->where(array('id' => $params['id']))->save($params);

            $this->ajaxReturn(['status'=>$add_result]);
        }else{
            $data = M('Currency')->where(['id' => $id])->find();
            $this->assign('pa', $data);
            $this->display();
        }

    }

    // 修改货币状态
    public function changeStatus() {
        $id = I('post.id', 0, 'intval');
        $status = I('post.status', 0, 'intval');

        if($id) {
            M('Currency')->where(['id'=>$id])->save(['status'=>$status]);
            $this->success('操作成功');
        } else {
            $this->error('参数错误');
        }
    }
}