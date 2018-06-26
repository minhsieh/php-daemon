<?php
namespace Daemon;

class PHPDaemon
{
    const ERR = -1;
    const SUC = 1;
    const PIDFNAME = 'daemon.pid';

    private $pid;
    private $childPids;
    private $pidFile;
    private $handle;
    private $processNum;
    private $argv;

    public function __construct($pid_path = "")
    {
        $this->processNum = 1;
        $this->childPids = [];
        $this->argv = $argv;
        
        //set default pid path
        if(empty($pid_path)) $pid_path = dirname(__FILE__)."/";
        $this->setPidFile($pid_path.self::PIDFNAME);

        //Check Enviroment
        if(! extension_loaded('pcntl')){
            throw new Exception("PHPDaemon must need pcntl extension");
        }
        if(php_sapi_name() != 'cli'){
            throw new Exception("PHPDaemon only works with PHP CLI mode");
        }
    }

    public function setPidFile($filename)
    {
        if(! is_file($filename)){
            throw new Exception("pid file path not exist: ".$filename);
        }
        if(! is_writable($filename)){
            throw new Exception("pid file path is not writable: ".$filename);
        }
        $this->pidFile = $filename;
    }

    public function setHandle($handle)
    {
        $this->handle = $handle;
    }

    public function setProcessNum($num = 1)
    {
        if($num < 1){
            throw new Exception("process nums must above 1");
        }
        $this->processNum = $num;
    }

    public function run()
    {
        switch($this->argv[1]){
            case "start":
                $this->start(); break;
            case "stop":
                $this->stop(); break;
            case "restart":
                $this->restart(); break;
            default:
                $this->usage(); break;
        }
    }

    public function start()
    {
        if(is_file($this->pidFile)){
            $this->msg("{$this->argv[0]} is already running with (".file_get_contents($this->pidFile).")");
        }else{
            if(empty($this->handle)){
                $this->msg("process handle unregistered",self::ERR);
                exit(-1);
            }

            $this->demonize();
            for($i=1; $i <= $this->processNum; $i ++){
                $pid = pcntl_fork();
                if($pid == -1){
                    $this->msg("fork() process ${$i}", self::ERR);
                }elseif($pid){
                    $this->childPids[$pid] = $i;
                }else{
                    return $this->handle($i);
                }
            }
        }

        // Wait child process
        while(count($this->childPids)){
            $waipid = pcntl_waitpid(-1 , $status , WNOHANG);
            unset($this->childPids[$waipid]);
            $this->checkPidFile();
            usleep(1000000);
        }
    }

    private function stop()
    {
        if(!is_file($this->pidFile)){
            $this->msg("{$this->argv[0]} is not running", self::ERR);
        }else{
            $pid = file_get_contents($this->pidFile);
            if(!@unlink($this->pidFile)) {
                $this->msg("remove pid file failed: $this->pidFile" , self::ERR);
            }
            sleep(1);
            $this->msg("stopping {$this->argv[0]} ({$pid})" , self::SUC);
        }
    }

    private function restart()
    {
        $this->stop();
        sleep(1);
        $this->start();
    }

    private function usage()
    {
        global $argv;
        echo str_pad('', 50, '-').PHP_EOL;
        echo "PHP Daemon - PHP daemon process class".PHP_EOL;
        echo "author minhsieh <side112358@gmail.com>".PHP_EOL;
        echo str_pad('', 50, '-').PHP_EOL;
        echo "Usage:".PHP_EOL;
        echo "\t{$argv[0]} start\t".$this->colorize("Start this daemon process in background.","yellow").PHP_EOL;
        echo "\t{$argv[0]} stop\t".$this->colorize("Stop this daemon.","yellow").PHP_EOL;
        echo "\t{$argv[0]} restart\t".$this->colorize("Restart this daemon.","yellow").PHP_EOL;
        echo str_pad('', 50, '-').PHP_EOL;
    }

    private function checkPidFile()
    {
        clearstatcache();
        if(!is_file($this->pidFile)){
            foreach($this->childPids as $pid => $pno){
                posix_kill($pid, SIGKILL);
            }
            exit;
        }
    }

    private function demonize() 
    {
        $pid = pcntl_fork();
        if($pid == -1){
            $this->msg("create main process", self::ERR);
        }elseif($pid){
            $this->msg("Starting {$this->argv[0]}", self::SUC);
            exit;
        }else{
            posix_setsid();
            $this->pid = posix_getpid();
            file_put_contents($this->pidFile, $this->pid);
        }
    }

    private function handle($pno)
    {
        if($this->handle){
            call_user_func($this->handle, $pon);
        }
    }

    private function msg($msg , $msgno = 0)
    {
        if ($msgno == 0) {
			fprintf(STDIN, $msg . "\n");
		} else {
			fprintf(STDIN, $msg . " ...... ");
			if ($msgno == self::OK) {
				fprintf(STDIN, $this->colorize('success', 'green'));
			} else {
				fprintf(STDIN, $this->colorize('failed', 'red'));
				exit;
			} 
			fprintf(STDIN, "\n");
		}
    }

    private function colorize($text, $color, $bold = FALSE) {
		$colors = array_flip(array(30 => 'gray', 'red', 'green', 'yellow', 'blue', 'purple', 'cyan', 'white', 'black'));
		return "\033[" . ($bold ? '1' : '0') . ';' . $colors[$color] . "m$text\033[0m";
	}
}