<?php
/* *****************************************************************
 * @Author: wushuiyong
 * @Created Time : 一  9/21 13:48:30 2015
 *
 * @File Name: WalleController.php
 * @Description:
 * *****************************************************************/

namespace app\controllers;

use yii;
use yii\data\Pagination;
use app\components\Command;
use app\components\Folder;
use app\components\Git;
use app\components\Task as WalleTask;
use app\components\Controller;
use app\models\Task;
use app\models\Record;
use app\models\Project;
use app\models\User;

class WalleController extends Controller {

    /**
     * 项目配置
     */
    protected $conf;

    /**
     * 上线任务配置
     */
    protected $task;

    public $enableCsrfValidation = false;


    /**
     * 发起上线
     *
     * @throws \Exception
     */
    public function actionStartDeploy() {
        $taskId = \Yii::$app->request->post('taskId');
        if (!$taskId) {
            static::renderJson([], -1, '任务号不能为空：）');
        }
        $this->task = Task::findOne($taskId);
        if (!$this->task) {
            throw new \Exception('任务号不存在：）');
        }
        if ($this->task->user_id != $this->uid) {
            throw new \Exception('不可以操作其它人的任务：）');
        }
        // 任务失败或者审核通过时可发起上线
        if (!in_array($this->task->status, [Task::STATUS_PASS, Task::STATUS_FAILED])) {
            throw new \Exception('任务不能被重复执行：）');
        }

        // 配置
        $this->conf = Project::getConf($this->task->project_id);
        // db配置
        $dbConf = Project::findOne($this->task->project_id);

        try {
            if ($this->task->action == Task::ACTION_ONLINE) {
                $this->_makeVersion();
                $this->_initWorkspace();
                $this->_gitUpdate();
                $this->_preDeploy();
                $this->_postDeploy();
                $this->_rsync();
                $this->_postRelease();
                $this->_link($this->task->link_id);
                $this->_cleanUp($this->task->link_id);
            } else {
                $this->_link($this->task->ex_link_id);
            }

            /** 至此已经发布版本到线上了，需要做一些记录工作 */

            // 记录此次上线的版本（软链号）和上线之前的版本
            ///对于回滚的任务不记录线上版本
            if ($this->task->action == Task::ACTION_ONLINE) {
                $this->task->ex_link_id = $dbConf->version;
            }
            $this->task->status = Task::STATUS_DONE;
            $this->task->save();

            // 记录当前线上版本（软链）回滚则是回滚的版本，上线为新版本
            $dbConf->version = $this->task->link_id;
            $dbConf->save();
        } catch (\Exception $e) {
            $this->task->status = Task::STATUS_FAILED;
            $this->task->save();
            // 清理本地部署空间
            $this->_cleanUp($this->task->link_id);

            throw $e;
        }
    }


    /**
     * 提交任务
     *
     * @return string
     */
    public function actionCheck() {
        $projects = Project::find()->asArray()->all();
        return $this->render('check', [
            'projects' => $projects,
        ]);
    }


    /**
     * 获取线上文件md5
     *
     * @param $projectId
     */
    public function actionFileMd5($projectId, $file) {
        // 配置
        $this->conf = Project::getConf($projectId);

        $cmd = new Folder();
        $cmd->setConfig($this->conf);
        $projectDir = $this->conf->release_to;
        $file = sprintf("%s/%s", rtrim($projectDir, '/'), $file);

        $cmd->getFileMd5($file);
        $log = $cmd->getExeLog();

        $this->renderJson(join("<br>", explode(PHP_EOL, $log)));
    }

    /**
     * 获取branch分支列表
     *
     * @param $projectId
     */
    public function actionGetBranch($projectId) {
        $git = new Git();
        $conf = Project::getConf($projectId);
        $git->setConfig($conf);
        $list = $git->getBranchList();

        $this->renderJson($list);
    }

    /**
     * 获取commit历史
     *
     * @param $projectId
     */
    public function actionGetCommitHistory($projectId, $branch = 'master') {
        $git = new Git();
        $conf = Project::getConf($projectId);
        $git->setConfig($conf);
        if ($conf->git_type == Project::GIT_TAG) {
            $list = $git->getTagList();
        } else {
            $list = $git->getCommitList($branch);
        }
        $this->renderJson($list);
    }

    /**
     * 上线管理
     *
     * @param $taskId
     * @return string
     * @throws \Exception
     */
    public function actionDeploy($taskId) {
        $this->task = Task::findOne($taskId);
        if (!$this->task) {
            throw new \Exception('任务号不存在：）');
        }
        if ($this->task->user_id != $this->uid) {
            throw new \Exception('不可以操作其它人的任务：）');
        }

        return $this->render('deploy', [
            'task' => $this->task,
        ]);
    }

    /**
     * 获取上线进度
     *
     * @param $taskId
     */
    public function actionGetProcess($taskId) {
        $record = Record::find()
            ->select(['percent' => 'action', 'status', 'memo', 'command'])
            ->where(['task_id' => $taskId,])
            ->orderBy('id desc')
            ->asArray()->one();
        $record['memo'] = stripslashes($record['memo']);
        $record['command'] = stripslashes($record['command']);

        static::renderJson($record);
    }

    /**
     * 产生一个上线版本
     */
    private function _makeVersion() {
        $version = date("Ymd-His", time());
        $this->task->link_id = $version;
        return $this->task->save();
    }

    /**
     * 检查目录和权限，工作空间的准备
     * 每一个版本都单独开辟一个工作空间，防止代码污染
     *
     * @return bool
     * @throws \Exception
     */
    private function _initWorkspace() {
        $folder = new Folder();
        $sTime = Command::getMs();
        $folder->setConfig($this->conf);
        // 本地宿主机工作区初始化
        $folder->initLocalWorkspace($this->task->link_id);
        // 本地宿主机代码仓库检查

        // 远程目标目录检查，并且生成版本目录
        $ret = $folder->initRemoteVersion($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($folder, $this->task->id, Record::ACTION_PERMSSION, $duration);

        if (!$ret) throw new \Exception('初始化部署隔离空间出错');
        return true;
    }

    /**
     * 更新代码文件
     *
     * @return bool
     * @throws \Exception
     */
    private function _gitUpdate() {
        // 更新代码文件
        $git = new Git();
        $sTime = Command::getMs();
        $ret = $git->setConfig($this->conf)
            ->updateToVersion($this->task->commit_id, $this->task->link_id); // 更新到指定版本
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($git, $this->task->id, Record::ACTION_CLONE, $duration);

        if (!$ret) throw new \Exception('更新代码文件出错');
        return true;
    }

    /**
     * 部署前置触发任务
     * 在部署代码之前的准备工作，如git的一些前置检查、vendor的安装（更新）
     *
     * @return bool
     * @throws \Exception
     */
    private function _preDeploy() {
        $task = new WalleTask();
        $sTime = Command::getMs();
        $task->setConfig($this->conf);
        $ret = $task->preDeploy($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($task, $this->task->id, Record::ACTION_PRE_DEPLOY, $duration);

        if (!$ret) throw new \Exception('前置任务操作失败');
        return true;
    }


    /**
     * 部署后置触发任务
     * git代码检出之后，可能做一些调整处理，如vendor拷贝，配置环境适配（mv config-test.php config.php）
     *
     * @return bool
     * @throws \Exception
     */
    private function _postDeploy() {
        $task = new WalleTask();
        $sTime = Command::getMs();
        $task->setConfig($this->conf);
        $ret = $task->postDeploy($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($task, $this->task->id, Record::ACTION_POST_DEPLOY, $duration);

        if (!$ret) throw new \Exception('后置任务操作失败');
        return true;
    }

    /**
     * 同步完所有目标机器时触发任务
     * 所有目标机器都部署完毕之后，做一些清理工作，如删除缓存、重启服务（nginx、php、task）
     *
     * @return bool
     * @throws \Exception
     */
    private function _postRelease() {
        $task = new WalleTask();
        $sTime = Command::getMs();
        $task->setConfig($this->conf);
        $ret = $task->postRelease($this->task->link_id);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($task, $this->task->id, Record::ACTION_POST_RELEASE, $duration);

        if (!$ret) throw new \Exception('同步代码后置任务操作失败');
        return true;
    }

    /**
     * 同步文件到服务器
     *
     * @return bool
     * @throws \Exception
     */
    private function _rsync() {
        $folder = new Folder();
        $folder->setConfig($this->conf);
        // 同步文件
        foreach (Project::getHosts() as $remoteHost) {
            $sTime = Command::getMs();
            $ret = $folder->syncFiles($remoteHost, $this->task->link_id);
            // 记录执行时间
            $duration = Command::getMs() - $sTime;
            Record::saveRecord($folder, $this->task->id, Record::ACTION_SYNC, $duration);
            if (!$ret) throw new \Exception('同步文件到服务器出错');
        }
        return true;
    }

    /**
     * 软链接
     */
    private function _link($version = null) {
        // 创建链接指向
        $folder = new Folder();
        $sTime = Command::getMs();
        $ret = $folder->setConfig($this->conf)
            ->link($version);
        // 记录执行时间
        $duration = Command::getMs() - $sTime;
        Record::saveRecord($folder, $this->task->id, Record::ACTION_LINK, $duration);

        if (!$ret) throw new \Exception('创建链接出错');
        return true;
    }

    /**
     * 收尾工作
     */
    private function _cleanUp($version = null) {
        // 创建链接指向
        $folder = new Folder();
        $folder->setConfig($this->conf)
            ->cleanUp($version);
        return true;
    }

}
