<?php

/*
 * This file is part of PHP CS Fixer.
 * (c) kcloze <pei.greet@qq.com>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Kcloze\Bot;

class Process
{
    const PROCESS_NAME_LOG = ' php: swoole-bot'; //shell脚本管理标示
    private $reserveProcess;
    private $workers;
    private $workNum = 2;
    private $config  = [];

    public function start($config)
    {
        \Swoole\Process::daemon();
        isset($config['swoole']['workNum']) && $this->workNum=$config['swoole']['workNum'];
        $this->config                                        = $config;
        //开启多个进程消费队列
        for ($i = 0; $i < $this->workNum; $i++) {
            $this->reserveBot($i);
            sleep(2);
        }
        $this->registSignal($this->workers);
    }

    //独立进程
    public function reserveBot($workNum)
    {
        $self = $this;
        $ppid = getmypid();
        //file_put_contents($this->config['logPath'] . '/master.pid.log', $ppid . "\n");
        $this->setProcessName('job master ' . $ppid . $self::PROCESS_NAME_LOG);
        $reserveProcess = new \Swoole\Process(function () use ($self, $workNum) {
            //设置进程名字
            $this->setProcessName('job ' . $workNum . $self::PROCESS_NAME_LOG);
            try {
                $job = new Robots($self->config);
                $job->run();
            } catch (Exception $e) {
                echo $e->getMessage();
            }
            echo 'reserve process ' . $workNum . " is working ...\n";
        });
        $pid                 = $reserveProcess->start();
        $this->workers[$pid] = $reserveProcess;
        echo "reserve start...\n";
    }

    //监控子进程
    public function registSignal($workers)
    {
        \Swoole\Process::signal(SIGTERM, function ($signo) {
            $this->exitMaster('收到退出信号,退出主进程');
        });
        \Swoole\Process::signal(SIGCHLD, function ($signo) use (&$workers) {
            while (true) {
                $ret = \Swoole\Process::wait(false);
                if ($ret) {
                    $pid           = $ret['pid'];
                    $child_process = $workers[$pid];
                    //unset($workers[$pid]);
                    echo "Worker Exit, kill_signal={$ret['signal']} PID=" . $pid . PHP_EOL;
                    $new_pid           = $child_process->start();
                    $workers[$new_pid] = $child_process;
                    unset($workers[$pid]);
                } else {
                    break;
                }
            }
        });
    }

    private function exitMaster()
    {
        @unlink($this->config['log']['system'] . '/master.pid.log');
        $this->log('Time: ' . microtime(true) . '主进程退出' . "\n");
        exit();
    }

    /**
     * 设置进程名.
     *
     * @param mixed $name
     */
    private function setProcessName($name)
    {
        //mac os不支持进程重命名
        if (function_exists('swoole_set_process_name') && PHP_OS !== 'Darwin') {
            swoole_set_process_name($name);
        }
    }

    private function log($txt)
    {
        file_put_contents($this->config['log']['system'] . '/worker.log', $txt . "\n", FILE_APPEND);
    }
}
