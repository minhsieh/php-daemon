<?php
namespace Minhsieh\PHPDaemon;

class Daemon
{
    const VER = '2.0';
    const SYSTEM = 0;
    const DEBUG = 1;
    const INFO  = 2;
    const ERROR = 3;
    
    private $pid_file_path;         // 放置 pid 暫存路徑
    private $processNum = 3;        // 最大 Worker 數量
    private $processCount = 0;      // 目前運行中 Woker 數量
    private $fid;                   // 老闆(父) pid
    
    private $stop_flag = 0;         // 是否停止所有Worker: 0 沒有 1 停止
    private $sigint = 0;            // 是否有收到SIGINT; 0 沒有 1 有，停止所有程序
    private $boss_sleep = 60 * 30;       // 老闆程序休息10分鐘
    private $log_level = 0;         // log記錄等級
    private $is_sig = 0;            // 是否有接收到信號
    private $last_sig = 0;          // 最後接收到的信號

    private $options = [];      // 執行輸入 options
    private $status = [];       // 記錄狀態
    private $workers = [];      // 進程 Workers 陣列
    private $worker_map = [];   // 進程 Workers key and pid map

    private $fd;        // File Process
    private $socket;    // Socket Process

	public function __construct($name = "", $path = "") {
        global $argc;
        
        if (!extension_loaded('pcntl')) {
			die('這個Daemon程序需要php支援pcntl extension');
		}
		if ('cli' != php_sapi_name()) {
			die('這個Daemon程序只能運作於CLI mode.');
		}
        
        $this->fid = posix_getpid();
        $this->msg("Construct bass pid: ".$this->fid);
        //註冊訊號
        declare(ticks = 1);
        pcntl_signal(SIGINT, array($this,"sig_handler"));
        pcntl_signal(SIGCLD, array($this,"sig_handler"));
        pcntl_signal(SIGCHLD, array($this,"sig_handler"));
        pcntl_signal(SIGUSR1, array($this,"sig_handler"));
        
        //處理 input options
        $this->options = getopt("Ht:o:d:", explode(",","stop,daemon,start,restart"));

        //預設pid存放位置
        $this->pid_file_path = (string) $path;
	}
	
	//设置PID文件路径
	public function setPidFilePath($file_path) {
		$this->pid_file_path = $file_path;
	}
	
	//设置处理函数
	public function setHandler($handler) {
		$this->handler = $handler;
	}
	
	//設置進程數量
	public function setProcessNum($num) {
		$this->processNum = $num;
	}

    //設置老闆程序休眠時間（秒）
    public function setBossSleep($seconds){
        $this->boss_sleep = $seconds;
    }

    public function initWorker()
    {
        for($i = 0 ; $i < $this->processNum ; $i ++){
            $this->workers[$i] = [
                "pid" => -1 ,
                "max_jobs" => 5,
                "job_count" => 0
            ];
        }
    }

    public function initSocket($domain = AF_INET, $type = SOCK_STREAM, $proto = SOL_TCP, $address = "127.0.0.1", $port = 0, $MaxListen = 0)
    {
        if(!($socket = socket_create($domain, $type, $proto))){
            $this->msg("建立socket失敗:".socket_strerror(socket_last_error()),3);
            exit;
        }
        $rval = socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if(!socket_bind($socket, $address, $port)){
            $this->msg("設定socket失敗 ".$address.":".$port."[".socket_strerror(socket_last_error()),3);
            socket_close($socket);
            exit;
        }
        if( (AF_INET == $domain && SOL_TCP == $proto) && !socket_listen($socket, $MaxListen)) {
            $this->msg("設定監聽socket最大值失敗 ".$socket.":".$port."[".socket_strerror(socket_last_error())."]",3);
            socket_close($socket);
            exit;
        }
        $this->socket = $socket;
    }

	
	//開始運行
	public function run() {
        global $argc;
        if(isset($this->options["H"]) || $argc == 1) {
            $this->usage();
            exit;
        }
        if(isset($this->options["stop"])){
            $this->stop();
            exit;
        }
        if(isset($this->options['restart'])){
            $this->restart();
            exit;
        }
        if(isset($this->options['start'])){
            if (empty($this->handler)) {
                $this->msg("Process handler unregistered."); 
                exit(-1);
            }
            $this->start();
        }
	}
	
	private function start() {
        $this->initWorker();
        //$this->initSocket(AF_INET, SOCK_STREAM, SOL_TCP,"10.100.103.51",12348, 50);

        // Check if need daemon mode.
        if(isset($this->options['daemon'])){
            $pid = pcntl_fork();
            if($pid == -1) die("fork 執行失敗\n");
            if($pid){
                $this->msg("老闆程序脫殼成功, pid:#$pid ",0);
                exit;
            } 
            $this->fid = posix_getpid();
        }

        // Save Pid
        $this->savePid();
        $this->msg("老闆啟動 fid => ".$this->fid);

        while(1){
            $this->msg(str_pad('', 25, '-')."[Loop Start]".str_pad('', 25, '-'));
            if($this->stop_flag == 0){
                foreach($this->workers as $k => $v){
                    $this->msg("開始建立工作者 #$k ....");
                    if($v['pid'] != -1) continue; //此worker已經產出，跳過
                    if($this->processCount == $this->processNum){
                        $this->msg("超過最大工作者");
                        break;
                    }

                    $pid = pcntl_fork();
                    usleep(100000);
                    if($pid == -1) die("fork 錯誤\n");
                    if($pid){
                        // 父程序 產出新的Worker
                        $this->msg("紀錄工作者 #$k pid => $pid...");
                        $this->workers[$k]['pid'] = $pid;
                        $this->worker_map[$pid] = $k;
                        $this->processCount ++;
                    }else{
                        // Worker working
                        pcntl_sigprocmask(SIG_BLOCK, array(SIGUSR1,SIGINT));
                        pcntl_signal(SIGUSR2,array($this,"sig_handler"));
                        $cpid = posix_getpid();
                        $this->handle($k , $v);
                        exit;
                    }
                    $this->msg("建立工作者 #$k 結束...");
                }
            }else{
                $this->msg("已經收到停止工作命令,不再建立工作者...");
                if($this->processCount == 0){
                    $this->msg("工作都已經停下來...");
                    exit;
                }
            }

            //判斷是否有堵塞信號,有的話要恢復可以接收信號
            if(is_array($oldset)) {
                $this->msg("解除堵塞信號....");
                pcntl_sigprocmask(SIG_SETMASK, $oldset);
                unset($oldset);
            }

            while(0 == $this->is_sig  &&  0 == sleep($this->boss_sleep) ){
                $this->msg("老闆睡覺中,睡: ".$this->boss_sleep." 秒,目前process: ".$this->processCount."...");
            }

            $this->is_sig = 0;
            $this->msg("建立堵塞信號....");
            //堵塞信號
            pcntl_sigprocmask(SIG_BLOCK, array(SIGINT, SIGUSR1, SIGCLD, SIGCHLD), $oldset);
            
            //是否接收到SIGINT
            if($this->sigint == 1){
                $this->msg("收到 Ctrl+C 信號殺死所有工作者....");
                //殺死所有Worker
                foreach($this->workers as $k => $v){
                    if($this->workers[$k]['pid'] == -1) continue;
                    $this->msg("殺死工作者 SIGUSR2 pid => ".$v['pid'].".....");
                    posix_kill($v["pid"] , SIGUSR2);
                }
                $this->sigint = 0;
                if(!empty($this->socket)){
                    @socket_close($this->socket);
                    unset($this->socket);
                }
            }
            
            
            $this->msg("準備判斷工作者是否死亡......");

            while(($wpid = pcntl_waitpid(0, $status, WNOHANG))){
                $this->msg("判斷工作者是否死亡....".$wpid);
                //比對是否有工作者死亡
                if(isset($this->worker_map[$wpid])){
                    $this->msg("工作者死亡回收: $wpid");
                    $id = $this->worker_map[$wpid];
                    $this->workers[$id]['pid'] = -1;
                    unset($this->worker_map[$wpid]);
                    $this->processCount--;
                }else{
                    if($wpid == -1){
                        // 工作者都正常，跳出檢查迴圈
                        break;
                    }
                    $this->msg("工作者死亡 ".$wpid." ,但比對不到原來紀錄的工作者 pid，終止老闆程序");
                    exit;
                }

                //確認回收狀態
                if(pcntl_wifexited($status)){
                    $this->msg("工作者回收成功");
                    if(0 == $this->processCount && 1 == $this->stop_flag){
                        $this->msg("已經收到停止工作命令,工作者都回收成功...終止老闆程序");
                        exit;
                    } 
                }else{
                    $this->msg("工作者回收失敗 GG");
                }
            }
            $this->msg(str_pad('', 25, '-')."[Loop End]".str_pad('', 25, '-'));
        }
	}

    public function savePid()
    {
        $this->msg("儲存fid位置 => ".$this->pid_file_path." [".$this->fid."]");
        if(!($fd = @fopen($this->pid_file_path, "w"))){
            $this->msg("無法儲存fid [".$this->pid_file_path."]",3);
            exit;
        }
        fputs($fd, $this->fid);
        fclose($fd);
        return true;
    }


	//停止
	private function stop() {
        $this->msg("收到殺死老闆工作命令");
        if(($fd = @fopen($this->pid_file_path, "r"))){
            if( $pid = trim(fgets($fd, 100)) ){
                $this->msg("殺死老闆 fid $pid");
                posix_kill($pid , SIGINT);
                @unlink($this->pid_file_path);
            }
        }else{
            $this->msg("打開存放老闆fid失敗 位置=>".$this->pid_file_path);
        }
	}
	
	//重启
	private function restart() {
		$this->stop();
        sleep(1);
        $this->start();
	}
	
	//使用示例
	private function usage() {
		global $argv;
		echo str_pad('', 50, '-')."\n";
		echo "PHPDaemon v".self::VER."\n";
		echo str_pad('', 50, '-')."\n";
		echo "Usage:\n";
		echo "\t{$argv[0]} -H \t| 使用幫助\n";
		echo "\t{$argv[0]} --stop \t| 停止程序\n";
		echo "\t{$argv[0]} --start \t| 啟動程序\n";
        echo "\t{$argv[0]} --restart \t| 重新啟動程序\n";
        echo "\t{$argv[0]} -o <log檔名>\n";
        echo "\t{$argv[0]} --daemon \t| 啟用Daemon守護進程模式\n";
		echo str_pad('', 50, '-')."\n";
	}

    public function sig_handler($signo)
    {
        $this->is_sig = 1;
        switch ($signo) {
            case SIGUSR1:
                $this->msg("SIGUSR1...");
                break;
            case SIGUSR2:
                $this->msg("SIGUSR2...");
                exit;
            case SIGCHLD:
                $this->msg("SIGCHLD...");
                break;
            case SIGINT:
                $this->msg("SIGINT...");
                $this->sigint = 1;
                $this->stop_flag = 1;
                break;
            default:
                $this->msg("catch signal:".$signo);
                exit;
        }
    }
	
	//执行用户处理函数
	private function handle($pno , $worker , $object = NULL) {
		if ($this->handler) {
			call_user_func($this->handler, $pno , $worker , $object);
		}
	}
	
	private function msg($msg, $msgno = 0) {
		if ($msgno == 0) {
			fprintf(STDIN, $msg . "\n");
		} else {
			fprintf(STDIN, $msg . " ...... ");
			if ($msgno == 1) {
				fprintf(STDIN, $this->colorize('success', 'green'));
			} else {
				fprintf(STDIN, $this->colorize('failed', 'red'));
				//exit;
			} 
			fprintf(STDIN, "\n");
		}
	}

	private function colorize($text, $color, $bold = FALSE) {
		$colors = array_flip(array(30 => 'gray', 'red', 'green', 'yellow', 'blue', 'purple', 'cyan', 'white', 'black'));
		return "\033[" . ($bold ? '1' : '0') . ';' . $colors[$color] . "m$text\033[0m";
	}

    
}