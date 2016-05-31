<?php

#version: 1.0.1
#projectUrl: https://github.com/mcmraak/joomlaDeploy
#autor: Aleksandr Ablizin (mcmraak@gmail.com)
#autorUrl: http://mraak.ru
set_time_limit(0);
# Configuration
$_config = array(
    'remoteHost' => 'http://domain.com', // Project domain http://domain.com
    'ftpHost' => '127.0.0.1',
    'ftpRemoteDir' => '', // Empty or "www/domain.com"
    'ftpUser' => 'ftpuse',
    'ftpPass' => 'ftppassword',
    'mysqlHost' => 'localhost',
    'mysqlDB' => 'myslqdb',
    'mysqlUser' => 'mysqluser',
    'mysqlPass' => 'mysqlpass',
    'ignoreFiles' => array(// ignore files
        '\.git',
		'\.idea',
        'nbproject',
        'deploy\.php',
        'cache.',
        'logs.',
        'tmp.'
    )
);

class Deploy {

    public $_config;

    function __construct($_config, $_options)
    {
        $this->_config = $_config;
        switch($_options[1])  
        {    
        case '-test':  
            $this->testOnly();  
            break;  
        case '-dump':  
            $this->dumpOnly();  
            break;  
        case '-help':
            $this->help();
            break;
        case '-savedump':
            $this->getMysqlDump();
            break;
        case '':
            $this->run();
            break;
        default:  
            echo "bad command. (-help)";
            break;  
        }   
    }
    
    function help()
    {
        echo "-test     - Test only\n"
           , "-dump     - Deploy only MySQL dump nj host\n"
           , "-savedump - Save MySQL dump in dump.sql file";
    }
    
    # FTP Upload
    function ftpUpload($filename)
    {

        $conn_id = ftp_connect($this->_config['ftpHost']);
        ftp_login($conn_id, $this->_config['ftpUser'], $this->_config['ftpPass']);
        ftp_pasv($conn_id, true);
        if ($this->_config['ftpRemoteDir'] != '')
        {
            @ftp_mkdir($conn_id, $this->_config['ftpRemoteDir']);
            $remote_directory = $this->_config['ftpRemoteDir'] . '/';
        }

        $ret = ftp_nb_put($conn_id, $remote_directory . $filename, $filename, FTP_BINARY);
        $preloader = array('|', '/', '-', '\\');
        $pc = 0;
        while ($ret == FTP_MOREDATA)
        {
            echo 'FTP uploading ' . $preloader[$pc] . "\r";
            ++$pc;
            if ($pc > 3)
            {
                $pc = 0;
            }
            $ret = ftp_nb_continue($conn_id);
        }
        echo "                          \r";
        if ($ret != FTP_FINISHED)
        {
            die('Uploading error!');
        }
        ftp_close($conn_id);
    }

    # Ignore files

    function searchIncludes($pathString)
    {
        $ignoreFiles = $this->_config['ignoreFiles'];
        $entry = FALSE;
        foreach ($ignoreFiles as $v)
        {
            if (preg_match("/^\.\/$v/", $pathString))
            {
                $entry = TRUE;
            }
        }
        return $entry;
    }

    # ZIP cucle

    function ZipDirectory($src_dir, $zip, $dir_in_archive = '')
    {
        $src_dir = str_replace("\\", "/", $src_dir);
        $dir_in_archive = str_replace("\\", "/", $dir_in_archive);
        $dirHandle = opendir($src_dir);
        while (false !== ($file = readdir($dirHandle)))
        {
            if (($file != '.') && ($file != '..'))
            {

                if (!$this->searchIncludes($src_dir . $file))
                {
                    if (!is_dir($src_dir . $file))
                    {
                        $zip->addFile($src_dir . $file, $dir_in_archive . $file);
                    } else
                    {
                        $zip->addEmptyDir($dir_in_archive . $file);
                        $zip = $this->ZipDirectory($src_dir . $file . DIRECTORY_SEPARATOR, $zip, $dir_in_archive . $file . DIRECTORY_SEPARATOR);
                    }
                }
            }
        }
        return $zip;
    }

    # ZIP init

    function ZipFull($src_dir, $archive_path)
    {
        $zip = new ZipArchive();
        if ($zip->open($archive_path, ZIPARCHIVE::CREATE) !== true)
        {
            return false;
        }
        $zip = $this->ZipDirectory($src_dir, $zip);
        $zip->close();
        return true;
    }

    # Create shell proxy

    function createShell()
    {
        echo "Create shell\n";
        $shell = '<?php set_time_limit(0);if($_POST["key"]!="hYdfjuKnff"){die();}$code=$_POST["code"];eval($code);';
        file_put_contents("deployshell.php", $shell);
        $this->ftpUpload('deployshell.php');
        unlink('deployshell.php');
    }

    # Curl wrapper

    function curlPush($code)
    {
        if ($curl = curl_init())
        {
            curl_setopt($curl, CURLOPT_URL, $this->_config['remoteHost'] . '/deployshell.php');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, "key=hYdfjuKnff&code=$code");
            $out = curl_exec($curl);
            curl_close($curl);
        }
        return $out;
    }

    function uzipInHost()
    {
        $unzipCode = '$zip = new ZipArchive;
        $res = $zip->open(\'deploy.zip\');
        if ($res === TRUE) {
          $zip->extractTo(\'./\');
          $zip->close();
          echo \'Unzip\';
        }';
        return $this->curlPush($unzipCode) . "\n";
    }

    function restoreDB()
    {
        $restoryDbCode = 'mysql_connect(\'' . $this->_config['mysqlHost'] . '\',\'' . $this->_config['mysqlUser'] . '\',\'' . $this->_config['mysqlPass'] . '\') OR die(\'mysql connect error\');
         mysql_select_db(\'' . $this->_config['mysqlDB'] . '\') or die(\'Not found database\');
         mysql_query(\'SET NAMES "utf8"\');
         mysql_query(\'SET CHARACTER SET "utf8"\');
         $templine = \'\';
         $lines = file(\'dump.sql\');
         foreach ($lines as $line)
         {
         if (substr($line, 0, 2) == \'--\' || $line == \'\')
             continue;
         $templine .= $line;
         if (substr(trim($line), -1, 1) == \';\')
         {
             mysql_query($templine) or die(mysql_error());
             $templine = \'\';
         }
         }
         echo "Database migrate successfully.";';
        return $this->curlPush($restoryDbCode) . "\n";
    }

    function joomlaConfig()
    {
        $path = $this->curlPush('echo $_SERVER[\'DOCUMENT_ROOT\'];');
        $logs_path = $path . '/logs';
        $tmp_path = $path . '/tmp';
        $rerpaceVarsCode = '$config=file_get_contents(\'configuration.php\');' .
                '$config = preg_replace(\'/\$log_path = \\\'.*\\\';/\', \'$log_path = \\\'' . $logs_path . '\\\';\', $config);' .
                '$config = preg_replace(\'/\$tmp_path = \\\'.*\\\';/\', \'$tmp_path = \\\'' . $tmp_path . '\\\';\', $config);' .
                '$config = preg_replace(\'/\$db = \\\'.*\\\';/\', \'$db = \\\'' . $this->_config['mysqlDB'] . '\\\';\', $config);' .
                '$config = preg_replace(\'/\$host = \\\'.*\\\';/\', \'$host = \\\'' . $this->_config['mysqlHost'] . '\\\';\', $config);' .
                '$config = preg_replace(\'/\$user = \\\'.*\\\';/\', \'$user = \\\'' . $this->_config['mysqlUser'] . '\\\';\', $config);' .
                '$config = preg_replace(\'/\$password = \\\'.*\\\';/\', \'$password = \\\'' . $this->_config['mysqlPass'] . '\\\';\', $config);' .
                'file_put_contents(\'configuration.php\', $config);' .
                'echo \'Joomla configured.\';';
        return $this->curlPush($rerpaceVarsCode) . "\n";
    }

    function utf8Force($dump)
    {
        $dump = file_get_contents($dump);
        $dump = str_replace('utf8mb4', 'utf8', $dump);
        file_put_contents('dump.sql', $dump);
    }

    function hostTests()
    {

        echo "Test shell:";
        $testShell = $this->curlPush('echo \'ok\';');
        if ($testShell = 'ok')
        {
            echo "OK\n";
        } else
            die('Error');

        echo "Test mysql:";
        $testDb = 'mysql_connect(\'' . $this->_config['mysqlHost'] . '\',\'' . $this->_config['mysqlUser'] . '\',\'' . $this->_config['mysqlPass'] . '\') OR die(mysql_error());
         mysql_select_db(\'' . $this->_config['mysqlDB'] . '\') or die(mysql_error());
         mysql_query(\'SET NAMES "utf8"\');
         mysql_query(\'SET CHARACTER SET "utf8"\');
         echo "ok";';
        $testDbResult = $this->curlPush($testDb);
        if ($testDbResult != 'ok')
        {
            die($testDbResult);
        } else
        {
            echo "OK\n";
        }
    }

    function cleanHost()
    {
        $cleanCode = 'function deltree($folder)
            {
            if (is_dir($folder))
                {
                    $handle = opendir($folder);
                    while($subfile = readdir($handle))
                    {
                        if ($subfile == \'.\' or $subfile == \'..\' or $subfile == \'deployshell.php\' or $subfile == \'deploy.zip\' or $subfile == \'dump.sql\') continue;
                        if (is_file($subfile)) unlink("{$folder}/{$subfile}");
                        else deltree("{$folder}/{$subfile}");
                    }
                    closedir($handle);
                    rmdir($folder);
                } else unlink($folder);
            }
            deltree("./");
            echo "Host clean";
            ';
        echo $this->curlPush($cleanCode) . "\n";
    }

    ##########################

    function testOnly()
    {
        if (!@file_get_contents('dump.sql'))
        {
            die('Error: dump.sql not found!');
        }
        $this->createShell();
        $this->hostTests();
        $this->curlPush('unlink(\'deployshell.php\');') . "\n";
    }

    function dumpOnly()
    {
        $this->getMysqlDump();
        if (!@file_get_contents('dump.sql'))
        {
            die('Error: dump.sql not found!');
        }
        $this->createShell();
        $this->hostTests();
        echo "Convert dump.sql to utf-8:";
        $this->utf8Force('dump.sql');
        echo "OK\n";
        echo "Database migrate started...\n";
        $this->ftpUpload('dump.sql');
        echo $this->restoreDB();
        echo $this->curlPush('unlink(\'deployshell.php\');unlink(\'dump.sql\');echo \'Clean\';') . "\n";
    }
    
    function getLocalConfig(){
        include 'configuration.php';
        $cfg = new JConfig;
        return $cfg;
    }
    
    
    function getMysqlDump()
    {
        echo "Get mysql dump from localhost:";
        $cfg = $this->getLocalConfig(); // Joomla configuration object
        
        mysql_connect($cfg->host,$cfg->user,$cfg->password) or die ('Local db connect error!');
        mysql_select_db($cfg->db) or die('Can not select database!');
        mysql_query("SET NAMES 'utf8'");
        mysql_query("SET CHARACTER SET 'utf8'"); 

        $tables = array();
        $result = mysql_query('SHOW TABLES');

        while ($row = mysql_fetch_row($result))
        {
            $tables[] = $row[0];
        }

        foreach ($tables as $table)
        {
            $result = mysql_query('SELECT * FROM ' . $table);
            $num_fields = mysql_num_fields($result);

            $return.= 'DROP TABLE IF EXISTS `' . $table . '`;';
            $row2 = mysql_fetch_row(mysql_query('SHOW CREATE TABLE ' . $table));
            $return.= "\n\n" . $row2[1] . ";\n\n";


            for ($i = 0; $i < $num_fields; $i++)
            {
                while ($row = mysql_fetch_row($result))
                {
                    $return.= 'INSERT INTO ' . $table . ' VALUES(';
                    for ($j = 0; $j < $num_fields; $j++)
                    {
                        $row[$j] = addslashes($row[$j]);
                        $row[$j] = preg_replace("/\n/", "\\n", $row[$j]);
                        if (isset($row[$j]))
                        {
                            $return.= '"' . $row[$j] . '"';
                        } else
                        {
                            $return.= '""';
                        }
                        if ($j < ($num_fields - 1))
                        {
                            $return.= ',';
                        }
                    }
                    $return.= ");\n";
                }
            }

            $return.="\n\n\n";
        }

        //save file
        $handle = fopen('dump.sql', 'w+');
        fwrite($handle, $return);
        fclose($handle);
        echo "OK\n";
    }

    function run()
    {
        
        $this->getMysqlDump();
        
        if (!@file_get_contents('dump.sql'))
        {
            die('Error: dump.sql not found!');
        }

        $this->createShell();

        $this->hostTests();

        echo "Convert dump.sql to utf-8:";
        $this->utf8Force('dump.sql'); # Optional
        echo "OK\n";
        echo "Zip started...\n";
        if ($this->ZipFull('./', './deploy.zip'))
        {
            echo "Archive created.\n";
        } else
        {
            die('Archive error.');
        }
        $this->ftpUpload('deploy.zip');
        unlink('deploy.zip');
        echo "Uploaded.\n";

        $this->cleanHost();
        echo $this->uzipInHost();

        # Configure Joomla
        echo "Database migrate started...\n";
        echo $this->restoreDB();
        echo $this->joomlaConfig();

        # Clean
        echo $this->curlPush('unlink(\'deployshell.php\');unlink(\'deploy.zip\');unlink(\'dump.sql\');echo \'Clean\';') . "\n";
    }
}

new Deploy($_config, $argv);