<?php
if (count(get_included_files()) == 1) define ('TEST_SUITE', __FILE__);
/**
 * diff-parser from https://github.com/ptlis/diff-parser
 * packed with https://github.com/mpyw/comphar
 */
require_once '../diff_parser.phar';
use ptlis\DiffParser\Parser;
use ptlis\DiffParser\File;
use ptlis\DiffParser\Line;

function parse_db_diff($diff, $master, $cbMap) {
  global $db;
  $db = $master;
  $versionDiff = [];
  $operates = [
    File::CREATED => 0,
    File::CHANGED => 0,
    File::DELETED => 0,
  ];

  // remove other files from diff
  $ori = fopen($diff, 'r');
  $new = fopen($diff.'.new', 'w');
  $observeFiles = array_keys($cbMap);
  $line = fgets($ori);
  $newtable = [];
  while (!feof($ori)) {
    if (substr($line, 0, 11) === "diff --git ") {
      $operates[File::CHANGED]++;
      $fname = substr(explode(' ', trim($line))[3], 2);
      if (!in_array($fname, $observeFiles)) {
        while (!feof($ori)) {
          $line = fgets($ori);
          if (trim($line) === '--- /dev/null') {
            $operates[File::CHANGED]--;
            $operates[File::CREATED]++;
            $newtable[] = substr($fname, 0, -4);
          } else if (trim($line) === '+++ /dev/null') {
            $operates[File::CHANGED]--;
            $operates[File::DELETED]++;
          }
          if (substr($line, 0, 11) === 'diff --git ') {
            $operates[File::CHANGED]++;
            break;
          }
        }
        continue;
      }
    }
    fwrite($new, $line);
    $line = fgets($ori);
  }
  fclose($ori); fclose($new);
  unlink($diff);
  rename($diff.'.new', $diff);

  $parser = new Parser();

  $changeset = $parser->parseFile($diff, Parser::VCS_GIT);
  $files = $changeset->getFiles();

  foreach ($files as &$file) {
    $fname = $file->getNewFilename();
    if (isset($cbMap[$fname])) {
      $data = call_user_func($cbMap[$fname], $file);
      if (!empty($data[1])) {
        $versionDiff[$data[0]] = isset($versionDiff[$data[0]]) ? array_merge($versionDiff[$data[0]], $data[1]) : $data[1];
      }
    }
  }
  $versionDiff['diff'] = $operates;
  if (!empty($newtable)) {
    $versionDiff['new_table'] = $newtable;
  }
  return $versionDiff;
}

$db = NULL;
function diff_event_data($file) {
  global $db;
  $items = [];
  foreach ($file->getHunks() as $hunk) {
    foreach ($hunk->getLines() as $line) {
      $op = $line->getOperation();
      if ($op != Line::ADDED) continue;
      preg_match('(/\*id\*/(\d+))', $line->getContent(), $id);
      if (empty($id)) continue;
      $id = $id[1];
      $item = execQuery($db, 'SELECT * FROM event_data WHERE id='.$id);
      if (!isset($item[0])) continue;
      $item = $item[0];
      $items[] = [
        'id' => $id,
        'name' => $item['name'],
        'start' => $item['event_start'],
        'end' => $item['event_end']
      ];
    }
  }
  return ['event', $items];
}
function diff_chino($file) {
  global $db;
  $items = [];
  foreach ($file->getHunks() as $hunk) {
    foreach ($hunk->getLines() as $line) {
      $op = $line->getOperation();
      if ($op != Line::ADDED) continue;
      preg_match('(/\*id\*/(\d+))', $line->getContent(), $id);
      if (empty($id)) continue;
      $id = $id[1] |0;
      if (empty($id)) continue;
      $item = execQuery($db, 'SELECT * FROM cappuccino_data WHERE id='.$id);
      if (!isset($item[0])) continue;
      $item = $item[0];
      $items[] = [
        'id' => $id,
        'title' => str_replace('\n',' ',$item['title']),
        'chara_id'=>$item['chara_list'],
        'chara' => implode(',', array_map(function($a){return $a['name'];}, execQuery($db, 'SELECT name FROM chara_data WHERE chara_id in ('.$item['chara_list'].')')))
      ];
    }
  }
  return ['gekijou_anime', $items];
}
function diff_card($file) {
  global $db;
  $items = [];
  foreach ($file->getHunks() as $hunk) {
    $balance = 0;
    foreach ($hunk->getLines() as $line) {
      $op = $line->getOperation();
      if ($op == Line::REMOVED) {$balance--; continue;}
      if ($op != Line::ADDED) continue;
      if ($balance++ < 0) continue;
      $balance--;
      preg_match('(/\*id\*/(\d+))', $line->getContent(), $id);
      if (empty($id)) continue;
      $id = $id[1];
      if ($id % 2 == 0) continue;
      $item = execQuery($db, 'SELECT a.name as name, a.rarity as rarity, b.skill_type as skill_type, b.condition as condition, b.probability_type as probability_type, b.available_time_type as available_time_type FROM card_data as a, skill_data as b WHERE a.id='.$id.' AND a.skill_id=b.id');
      if (!isset($item[0])) continue;
      $item = $item[0];
      $items[] = [
        'id' => $id,
        'name' => $item['name'],
        'rarity' => (int)$item['rarity'],
        'skill_type' => (int)$item['skill_type'],
        'condition' => (int)$item['condition'],
        'probability_type' => (int)$item['probability_type'],
        'available_time_type' => (int)$item['available_time_type']
      ];
    }
  }
  return ['card', $items];
}
function diff_gacha($file) {
  global $db;
  $items = [];
  foreach ($file->getHunks() as $hunk) {
    foreach ($hunk->getLines() as $line) {
      $op = $line->getOperation();
      if ($op != Line::ADDED) continue;
      preg_match('(/\*id\*/(\d+))', $line->getContent(), $id);
      if (empty($id)) continue;
      $id = $id[1];
      if (
        $id < 30000 || 
        ($id >= 40000 && $id < 80000)
      ) continue;
      $item = execQuery($db, 'SELECT * FROM gacha_data WHERE id='.$id);
      if (!isset($item[0])) continue;
      $item = $item[0];
      $items[] = [
        'id' => $id,
        'name' => $item['name'],
        'detail' => preg_replace('/^(.+登場).+/', '$1', $item['dicription']),
        'start' => $item['start_date'],
        'end' => $item['end_date']
      ];
    }
  }
  return ['gacha', $items];
}
function diff_gekijou($file) {
  global $db;
  $items = [];
  foreach ($file->getHunks() as $hunk) {
    foreach ($hunk->getLines() as $line) {
      $op = $line->getOperation();
      if ($op != Line::ADDED) continue;
      preg_match('(/\*id\*/(\d+))', $line->getContent(), $id);
      if (empty($id)) continue;
      $id = $id[1] |0;
      if (empty($id)) continue;
      $item = execQuery($db, 'SELECT * FROM latte_art_data WHERE id='.$id);
      if (!isset($item[0])) continue;
      $item = $item[0];
      $items[] = [
        'id' => $id,
        'title' => $item['title'],
        'chara_id'=>$item['chara_list'],
        'chara' => implode(',', array_map(function($a){return $a['name'];}, execQuery($db, 'SELECT name FROM chara_data WHERE chara_id in ('.$item['chara_list'].')')))
      ];
    }
  }
  return ['gekijou', $items];
}
function diff_music($file) {
  global $db;
  $items = [];
  foreach ($file->getHunks() as $hunk) {
    $balance = 0;
    foreach ($hunk->getLines() as $line) {
      $op = $line->getOperation();
      if ($op == Line::REMOVED) {$balance--; continue;}
      if ($op != Line::ADDED) continue;
      if ($line->getOriginalLineNo() != -1) continue;
      preg_match('(/\*id\*/(\d+))', $line->getContent(), $id);
      if ($balance++ < 0) continue;
      $balance--;
      if (empty($id)) continue;
      $id = $id[1];
      $item = execQuery($db, 'SELECT * FROM music_data WHERE id='.$id);
      if (!isset($item[0])) continue;
      $item = $item[0];
      $items[] = [
        'id' => $id,
        'name' => str_replace('\n', '',$item['name'])
      ];
    }
  }
  return ['music', $items];
}
function diff_party($file) {
  global $db;
  $items = [];
  foreach ($file->getHunks() as $hunk) {
    foreach ($hunk->getLines() as $line) {
      $op = $line->getOperation();
      if ($op != Line::ADDED) continue;
      preg_match('(/\*term_id\*/(\d+))', $line->getContent(), $id);
      if (empty($id)) continue;
      $id = $id[1];
      $item = execQuery($db, 'SELECT * FROM party_data_re WHERE term_id='.$id);
      if (!isset($item[0])) continue;
      $item = $item[0];
      $items[] = [
        'id' => $id,
        'start' => $item['event_start'],
        'end' => $item['event_end']
      ];
    }
  }
  return ['party', $items];
}

if (defined('TEST_SUITE') && TEST_SUITE == __FILE__) {
  function execQuery($db, $query) {
    $returnVal = [];
    $result = $db->query($query);
    $returnVal = $result->fetchAll(PDO::FETCH_ASSOC);
    return $returnVal;
  }
  require_once '../mysql.php';
  $mysqli->select_db('db_diff');
  chdir(__DIR__);
  chdir('data');
  exec('D:/cygwin64/bin/git log --pretty=format:"%H %s %ct"', $commits);
  $data = [];
  $commits = array_map(function ($commit) {
    preg_match('((.{40}) (.+) (\d+))',$commit,$detail);
    $comm = [
      'hash' => $detail[1],
      'ver' => $detail[2],
      'time' => (int)$detail[3],
      'skip' => false
    ];
    if (!is_numeric($comm['ver'])) {
      preg_match('/(\d+) (\d+)\/(\d+)\/(\d+) (\d+):(\d+)/', $comm['ver'], $ver);
      if (empty($ver)) {
        //$comm['skip'] = true;
        $comm['ver'] = intval($comm['ver']).'';
      } else {
        $comm['ver'] = $ver[1];
        $comm['time'] = mktime($ver[5], $ver[6], 0, $ver[3], $ver[4], $ver[2]) - 3600;
      }
    }
    if ($comm['ver'] == '0') $comm['skip'] = true;
    return $comm;
  }, $commits);
  $i=0;
  foreach($commits as $no=>$commit){
    if (!isset($commits[$no+1])) continue;
    if ($commit['skip']) continue;
    echo "\n".$commit['ver'].' '.date('Y-m-d H:i', $commit['time'] + 3600).' '.$no.'/'.count($commits);
    exec('D:/cygwin64/bin/git diff '.$commits[$no+1]['hash'].' '.$commit['hash'].' >../a.diff');
    chdir('..');
    $master = new PDO('sqlite:'.__DIR__.'/master.db');
    $versionDiff = parse_db_diff('a.diff', $master, [
      'event_data.sql' => 'diff_event_data', // event
      'cappuccino_data.sql' => 'diff_chino', // gekijou_anime
      'card_data.sql' => 'diff_card',        // card
      'gacha_data.sql' => 'diff_gacha',      // gacha
      'latte_art_data.sql' => 'diff_gekijou',// gekijou
      'music_data.sql' => 'diff_music',      // music
      'party_data_re.sql' => 'diff_party',   // party
    ]);
    unlink('a.diff');
    chdir('data');
    $versionDiff['hash'] = $commit['hash'];
    $versionDiff['ver'] = $commit['ver'];
    $versionDiff['time'] = $commit['time'];
    $versionDiff['timeStr'] = date('Y-m-d H:i', $commit['time'] + 3600);
    $mysqli->query('REPLACE INTO cgss (ver,data) vALUES ('.$commit['ver'].',"'.$mysqli->real_escape_string(brotli_compress(
      json_encode($versionDiff, JSON_UNESCAPED_SLASHES), 11, BROTLI_TEXT
    )).'")');
    //if (++$i > 10) break;
    //if ($commit['ver'] < 10040000) break;
  }
  file_put_contents('../diff_parsed.json',json_encode($data));
  //print_r($versionDiff);
}

