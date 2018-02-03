#!/usr/bin/php
<?php
echo 'Loading settings...'.PHP_EOL;
require('settings.php');
$strings = @json_decode(file_get_contents('strings_'.$settings['language'].'.json'), 1);
if (!file_exists('sessions')) mkdir('sessions');
if (!isset($settings['multithread'])) $settings['multithread'] = 0;
if ($settings['multithread'] and function_exists('pcntl_fork') == 0) $settings['multithread'] = 0;
if (!is_array($strings)) {
  if (!file_exists('strings_it.json')) {
    echo 'downloading strings_it.json...'.PHP_EOL;
    file_put_contents('strings_it.json', file_get_contents('https://raw.githubusercontent.com/peppelg/TGUserbot/master/strings_it.json'));
  }
  $strings = json_decode(file_get_contents('strings_it.json'), 1);
}
if (isset($argv[1]) and $argv[1]) {
  if ($argv[1] == 'background') {
    shell_exec('screen -d -m php start.php');
    echo PHP_EOL.$strings['background'].PHP_EOL;
    exit;
  }
  if (isset($argv[2]) and $argv[2] == 'background') {
    shell_exec('screen -d -m php start.php '.escapeshellarg($argv[1]));
    echo PHP_EOL.$strings['background'].PHP_EOL;
    exit;
  }
  if ($argv[1] == 'update') {
    echo PHP_EOL.$strings['updating'].PHP_EOL;
    $bot = file_get_contents('bot.php');
    $settings = file_get_contents('settings.php');
    shell_exec('git reset --hard HEAD');
    shell_exec('git pull');
    file_put_contents('bot.php', $bot);
    file_put_contents('settings.php', $settings);
    passthru('composer update');
    echo PHP_EOL.$strings['done'].PHP_EOL;
    exit;
  }
  $settings['session'] = $argv[1];
}
if ($settings['auto_reboot'] and function_exists('pcntl_exec')) {
  register_shutdown_function(function () {
    pcntl_exec($_SERVER['_'], array("start.php", 0));
  });
}
echo $strings['loading'].PHP_EOL;
require('vendor/autoload.php');
include('functions.php');
if ($settings['multithread']) {
  $m = readline($strings['shitty_multithread_warning']);
  if ($m != 'y') exit;
  if (file_exists('SimpleProcess.phar')) require('SimpleProcess.phar');
  else {
    copy('https://peppelg.github.io/SimpleProcess.phar', 'SimpleProcess.phar');
    if (file_exists('SimpleProcess.phar')) require('SimpleProcess.phar');
    else $settings['multithread'] = false;
  }
  if ($settings['multithread']) {
    declare(ticks=1);
    $manager = new SimpleProcess\ProcessManager();
  }
}
if (file_exists('plugins') and is_dir('plugins')) {
  $settings['plugins'] = true;
  echo $strings['loading_plugins'].PHP_EOL;
  class TGUserbotPlugin {
    public function onUpdate() {

    }
    public function onStart() {

    }
  }
  $pluginslist = array_values(array_diff(scandir('plugins'), ['..', '.']));
  $plugins = [];
  $pluginN = 0;
  foreach ($pluginslist as $plugin) {
    if (substr($plugin, -4) == '.php') {
      include('plugins/'.$plugin);
    }
  }
  foreach (get_declared_classes() as $class) {
    if (is_subclass_of($class, 'TGUserbotPlugin')) {
      $pluginN++;
      $plugin = new $class();
      if (method_exists($class, 'onStart')) {
        $plugins['onStart'][$class] = $plugin;
      }
      if (method_exists($class, 'onUpdate')) {
        $plugins['onUpdate'][$class] = $plugin;
      }
    }
  }
  echo $pluginN.' '.$strings['plugins_loaded'].PHP_EOL;
  if ($pluginN == 0) $settings['plugins'] = false;
} else {
  $settings['plugins'] = false;
}
if (!file_exists($settings['session'])) {
  $MadelineProto = new \danog\MadelineProto\API(['app_info' => ['api_id' => 6, 'api_hash' => 'eb06d4abfb49dc3eeb1aeb98ae0f581e', 'lang_code' => $settings['language'], 'app_version' => '4.7.0'], 'logger' => ['logger' => 0], 'updates' => ['handle_old_updates' => 0]]);
  echo $strings['loaded'].PHP_EOL;
  echo $strings['ask_phone_number'];
  $phoneNumber = fgets(STDIN);
  $sentCode = $MadelineProto->phone_login($phoneNumber);
  echo $strings['ask_login_code'];
  $code = fgets(STDIN, (isset($sentCode['type']['length']) ? $sentCode['type']['length'] : 5) + 1);
  $authorization = $MadelineProto->complete_phone_login($code);
  if ($authorization['_'] === 'account.password') {
    echo $strings['ask_2fa_password'];
    $password = trim(fgets(STDIN));
    if ($password == '') $password = trim(fgets(STDIN));
    $authorization = $MadelineProto->complete_2fa_login($password);
  }
  if ($authorization['_'] === 'account.needSignup') {
    echo $strings['ask_name'];
    $name = trim(fgets(STDIN));
    if ($name == '') $name = trim(fgets(STDIN));
    if ($name == '') $name = 'TGUserbot';
    $authorization = $MadelineProto->complete_signup($name, '');
  }
  $MadelineProto->session = $settings['session'];
  $MadelineProto->serialize($settings['session']);
} else {
  $MadelineProto = new \danog\MadelineProto\API($settings['session']);
  echo $strings['loaded'].PHP_EOL;
}
echo $strings['session_loaded'].PHP_EOL;
if ($settings['plugins']) {
  foreach ($plugins['onStart'] as $plugin) {
    $plugin->onStart();
  }
}
if (isset($settings['cronjobs']) and $settings['cronjobs']) {
  function cronjobAdd($time, $id) {
    global $MadelineProto;
    if (!is_numeric($time) and strlen($time) !== 10) {
      $time = strtotime($time);
    }
    if (!is_numeric($time)) return false;
    if ($time < time()) return false;
    $MadelineProto->cronjobs[$time] = $id;
    return true;
  }
  function cronjobDel($id) {
    global $MadelineProto;
    $cronid = array_search($id, $MadelineProto->cronjobs);
    if ($cronid !== false) {
      unset($MadelineProto->cronjobs[$cronid]);
      return true;
    } else {
      return false;
    }
  }
  function cronjobReset() {
    global $MadelineProto;
    $MadelineProto->cronjobs = [];
    return true;
  }
  function cronrun() {
    global $MadelineProto;
    global $settings;
    global $strings;
    global $plugins;
    $now = date('d m Y H i');
    if (isset($MadelineProto->cronjobs) and !empty($MadelineProto->cronjobs)) {
      foreach ($MadelineProto->cronjobs as $time => $cronjob) {
        if (date('d m Y H i', $time) === $now) {
          cronjobDel($cronjob);
          if (is_string($cronjob)) echo 'CRONJOB >>> '.$cronjob.PHP_EOL;
          else echo 'CRONJOB >>> *array*'.PHP_EOL;
          $msg = 'cronjob';
          $msgid = 'cronjob';
          $type = 'cronjob';
          if ($settings['plugins']) {
            foreach ($plugins['onUpdate'] as $plugin) {
              $plugin->onUpdate();
            }
          }
          try {
            require('bot.php');
          } catch(\danog\MadelineProto\Exception $e) {
            echo $strings['error'].$e->getMessage().PHP_EOL;
          }
        }
      }
    }
  }
}
$offset = 0;
while (true) {
  if ($settings['always_online']) {
    if (date('s') == 30) {
      $MadelineProto->account->updateStatus(['offline' => 0]);
    }
  }
  if (isset($settings['cronjobs']) and $settings['cronjobs']) cronrun();
  try {
    $updates = $MadelineProto->get_updates(['offset' => $offset, 'limit' => 50, 'timeout' => 0]);
    foreach ($updates as $update) {
      $offset = $update['update_id'] + 1;
      if (isset($update['update']['message']['from_id'])) $userID = $update['update']['message']['from_id'];
      if (isset($update['update']['message']['id'])) $msgid = $update['update']['message']['id'];
      if (isset($update['update']['message']['message'])) $msg = $update['update']['message']['message'];
      if ($settings['old_chatinfo']) {
        if (isset($update['update']['message']['to_id']['channel_id'])) {
          $chatID = '-100'.$update['update']['message']['to_id']['channel_id'];
          $type = 'supergroup';
        }
        if (isset($update['update']['message']['to_id']['chat_id'])) {
          $chatID = '-'.$update['update']['message']['to_id']['chat_id'];
          $type = 'group';
        }
        if (isset($update['update']['message']['to_id']['user_id'])) {
          $chatID = $update['update']['message']['from_id'];
          $type = 'user';
        }
      } else {
        if (isset($update['update']['message'])) {
          $info['to'] = $MadelineProto->get_info($update['update']['message']['to_id']);
          if (isset($info['to']['bot_api_id'])) $chatID = $info['to']['bot_api_id'];
          if (isset($info['to']['type'])) $type = $info['to']['type'];
          if (isset($userID)) $info['from'] = $MadelineProto->get_info($userID);
          if (isset($info['to']['User']['self']) and isset($userID) and $info['to']['User']['self'] and $userID) $chatID = $userID;
          if (isset($type) and $type == 'chat') $type = 'group';
          if (isset($info['from']['User']['first_name'])) $name = $info['from']['User']['first_name']; else $name = NULL;
          if (isset($info['to']['Chat']['title'])) $title = $info['to']['Chat']['title']; else $title = NULL;
          if (isset($info['from']['User']['username'])) $username = $info['from']['User']['username']; else $username = NULL;
          if (isset($info['to']['Chat']['username'])) $chatusername = $info['to']['Chat']['username']; else $chatusername = NULL;
        }
      }
      if (isset($msg) and $msg) {
        if ($settings['readmsg'] and isset($type) and isset($msgid) and isset($chatID) and $type == 'user' and $msgid and $chatID) $MadelineProto->messages->readHistory(['peer' => $chatID, 'max_id' => $msgid]);
        if (isset($msg) and isset($chatID) and isset($type) and isset($userID) and $msg and $chatID and $type and $userID) {
          if ($type == 'user') {
            echo $name.' ('.$userID.') >>> '.$msg.PHP_EOL;
          } else {
            echo $name.' ('.$userID.') -> '.$title.' ('.$chatID.') >>> '.$msg.PHP_EOL;
          }
        }
      }
      if (!isset($msg)) $msg = NULL;
      if (!isset($chatID)) $chatID = NULL;
      if (!isset($userID)) $userID = NULL;
      if (!isset($msgid)) $msgid = NULL;
      if (!isset($type)) $type = NULL;
      if (!isset($name)) $name = NULL;
      if (!isset($username)) $username = NULL;
      if (!isset($chatusername)) $chatusername = NULL;
      if (!isset($title)) $title = NULL;
      if (!isset($info)) $info = NULL;
      if (!isset($cronjob)) $cronjob = NULL;
      if ($settings['plugins']) {
        foreach ($plugins['onUpdate'] as $plugin) {
          $plugin->onUpdate();
        }
      }
      if ($settings['multithread'] and isset($msg) and isset($userID) and isset($msgid) and isset($info) and isset($chatID) and isset($type)) {
        $manager->fork(new SimpleProcess\Process(function() {
          global $MadelineProto;
          global $settings;
          global $update;
          global $msg;
          global $userID;
          global $msgid;
          global $info;
          global $chatID;
          global $name;
          global $username;
          global $title;
          global $usernamechat;
          $MadelineProto->reset_session();
          require('bot.php');
        }, 'TGUserbot'));
      } else {
        try {
          require('bot.php');
        } catch(\danog\MadelineProto\Exception $e) {
          echo $strings['error'].$e->getMessage().PHP_EOL;
          if (isset($chatID) and $settings['send_errors']) {
            try {
              $MadelineProto->messages->sendMessage(['peer' => $chatID, 'message' => '<b>'.$strings['error'].'</b> <code>'.$e->getMessage().'</code>', 'parse_mode' => 'HTML']);
            } catch(\danog\MadelineProto\Exception $e) { }
          }
        }
      }
      if (isset($msg)) unset($msg);
      if (isset($chatID)) unset($chatID);
      if (isset($userID)) unset($userID);
      if (isset($type)) unset($type);
      if (isset($msgid)) unset($msgid);
      if (isset($name)) unset($name);
      if (isset($username)) unset($username);
      if (isset($chatusername)) unset($chatusername);
      if (isset($title)) unset($title);
      if (isset($info)) $info = [];
    }
  } catch(\danog\MadelineProto\Exception $e) {
    echo $strings['error'].$e->getMessage().PHP_EOL;
    if (isset($chatID) and $settings['send_errors']) {
      try {
        $MadelineProto->messages->sendMessage(['peer' => $chatID, 'message' => '<b>'.$strings['error'].'</b> <code>'.$e->getMessage().'</code>', 'parse_mode' => 'HTML']);
      } catch(\danog\MadelineProto\Exception $e) { }
    }
  }
}
